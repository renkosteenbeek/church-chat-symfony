<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Member;
use App\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ToolExecutor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberRepository $memberRepository,
        private readonly ContentApiClient $contentApiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function executeTool(string $toolName, array $arguments, Member $member): array
    {
        $startTime = microtime(true);
        
        $this->logger->info('Executing tool', [
            'tool' => $toolName,
            'arguments' => array_keys($arguments),
            'member_id' => $member->getId()
        ]);

        try {
            $validationResult = $this->validateToolArguments($toolName, $arguments);
            if (!$validationResult['valid']) {
                return [
                    'success' => false,
                    'error' => 'Invalid arguments: ' . $validationResult['message']
                ];
            }

            $result = match ($toolName) {
                'manage_user' => $this->executeManageUser($arguments, $member),
                'handle_sermon' => $this->executeHandleSermon($arguments, $member),
                'manage_subscription' => $this->executeManageSubscription($arguments, $member),
                'answer_question' => $this->executeAnswerQuestion($arguments, $member),
                'process_feedback' => $this->executeProcessFeedback($arguments, $member),
                default => [
                    'success' => false,
                    'error' => "Unknown tool: {$toolName}",
                    'available_tools' => ['manage_user', 'handle_sermon', 'manage_subscription', 'answer_question', 'process_feedback']
                ]
            };

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->info('Tool execution completed', [
                'tool' => $toolName,
                'success' => $result['success'] ?? false,
                'execution_time_ms' => $executionTime,
                'member_id' => $member->getId()
            ]);

            return $result;

        } catch (\Throwable $e) {
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
                'execution_time_ms' => $executionTime,
                'trace' => $e->getTraceAsString(),
                'member_id' => $member->getId()
            ]);

            return [
                'success' => false,
                'error' => 'Er is een technische fout opgetreden. Probeer het later opnieuw.',
                'internal_error' => $e->getMessage(),
                'tool' => $toolName
            ];
        }
    }

    private function validateToolArguments(string $toolName, array $arguments): array
    {
        $requiredArguments = [
            'manage_user' => [],
            'handle_sermon' => ['action'],
            'manage_subscription' => ['action'],
            'answer_question' => ['question', 'category'],
            'process_feedback' => ['type', 'message']
        ];

        if (!isset($requiredArguments[$toolName])) {
            return ['valid' => false, 'message' => "Unknown tool: {$toolName}"];
        }

        $required = $requiredArguments[$toolName];
        $missing = [];

        foreach ($required as $field) {
            if (!isset($arguments[$field]) || empty($arguments[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return [
                'valid' => false, 
                'message' => "Missing required arguments: " . implode(', ', $missing)
            ];
        }

        return ['valid' => true];
    }

    private function executeManageUser(array $arguments, Member $member): array
    {
        $updated = [];

        if (isset($arguments['name'])) {
            $member->setFirstName($arguments['name']);
            $updated[] = "naam: {$arguments['name']}";
        }

        if (isset($arguments['age'])) {
            $member->setAge((int) $arguments['age']);
            $updated[] = "leeftijd: {$arguments['age']}";
        }

        if (isset($arguments['target_group'])) {
            $targetGroupMap = [
                'jongeren' => Member::TARGET_GROUP_JONGEREN,
                'volwassenen' => Member::TARGET_GROUP_VOLWASSEN,
                'verdieping' => Member::TARGET_GROUP_VERDIEPING,
                'gezinnen' => Member::TARGET_GROUP_VOLWASSEN
            ];
            
            if (isset($targetGroupMap[$arguments['target_group']])) {
                $member->setTargetGroup($targetGroupMap[$arguments['target_group']]);
                $updated[] = "doelgroep: {$arguments['target_group']}";
            }
        }

        if (isset($arguments['church'])) {
            try {
                $church = $this->contentApiClient->getChurchByName($arguments['church']);
                if ($church && isset($church['id'])) {
                    $member->setChurchIds([(int) $church['id']]);
                    $updated[] = "kerk: {$arguments['church']}";
                }
            } catch (\Exception $e) {
                $this->logger->warning('Could not update church', [
                    'church_name' => $arguments['church'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (!$member->isIntakeCompleted() && $member->getFirstName() && $member->getAge()) {
            $member->setIntakeCompleted(true);
            $updated[] = 'intake voltooid';
        }

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $this->logger->info('User information updated', [
            'member_id' => $member->getId(),
            'updated_fields' => $updated
        ]);

        return [
            'success' => true,
            'message' => 'Gebruikersinformatie bijgewerkt',
            'updated' => $updated
        ];
    }

    private function executeHandleSermon(array $arguments, Member $member): array
    {
        $action = $arguments['action'] ?? '';

        switch ($action) {
            case 'get_summary':
                $activeSermon = $member->getActiveSermonId();
                if (!$activeSermon) {
                    return [
                        'success' => false,
                        'message' => 'Geen actieve preek gevonden'
                    ];
                }

                try {
                    $summary = $this->contentApiClient->getSermonSummary(
                        $activeSermon,
                        $member->getTargetGroup() ?? 'volwassen'
                    );
                    
                    if (!$summary) {
                        return [
                            'success' => false,
                            'message' => 'Samenvatting nog niet beschikbaar'
                        ];
                    }
                    
                    return [
                        'success' => true,
                        'message' => 'Hier is een samenvatting van de preek',
                        'summary' => $summary['content'] ?? $summary,
                        'reflection_questions' => $summary['reflection_questions'] ?? null
                    ];
                } catch (\Exception $e) {
                    $this->logger->error('Failed to fetch sermon summary', [
                        'sermon_id' => $activeSermon,
                        'member_id' => $member->getId(),
                        'error' => $e->getMessage()
                    ]);
                    
                    return [
                        'success' => false,
                        'message' => 'Kon samenvatting niet ophalen - probeer het later opnieuw',
                        'error' => 'Service temporarily unavailable'
                    ];
                }

            case 'register_attendance':
                $attended = $arguments['attended'] ?? true;
                $online = $arguments['online_attended'] ?? false;
                
                $attendanceData = [
                    'attended' => $attended,
                    'online' => $online,
                    'date' => new \DateTime()
                ];
                
                $member->addMetadata('last_attendance', $attendanceData);
                $this->entityManager->persist($member);
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'message' => $attended 
                        ? ($online ? 'Online aanwezigheid geregistreerd' : 'Aanwezigheid geregistreerd')
                        : 'Afwezigheid geregistreerd'
                ];

            case 'register_absence':
                $alternativeChurch = $arguments['alternative_church'] ?? null;
                
                $absenceData = [
                    'attended' => false,
                    'alternative_church' => $alternativeChurch,
                    'date' => new \DateTime()
                ];
                
                $member->addMetadata('last_attendance', $absenceData);
                $this->entityManager->persist($member);
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'message' => $alternativeChurch
                        ? "Geregistreerd dat je bij {$alternativeChurch} was"
                        : 'Afwezigheid geregistreerd'
                ];

            default:
                return [
                    'success' => false,
                    'message' => "Onbekende actie: {$action}"
                ];
        }
    }

    private function executeManageSubscription(array $arguments, Member $member): array
    {
        $action = $arguments['action'] ?? '';

        switch ($action) {
            case 'pause':
                $pauseUntil = $arguments['pause_until'] ?? null;
                
                $member->setNotificationsNewService(false);
                $member->setNotificationsReflection(false);
                
                if ($pauseUntil) {
                    $member->addMetadata('notifications_paused_until', $pauseUntil);
                }
                
                $this->entityManager->persist($member);
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'message' => $pauseUntil
                        ? "Notificaties gepauzeerd tot {$pauseUntil}"
                        : 'Notificaties gepauzeerd'
                ];

            case 'resume':
                $member->setNotificationsNewService(true);
                $member->setNotificationsReflection(true);
                $member->addMetadata('notifications_paused_until', null);
                
                $this->entityManager->persist($member);
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'message' => 'Notificaties hervat'
                ];

            case 'change_frequency':
                $notificationType = $arguments['notification_type'] ?? 'all';
                $frequency = $arguments['frequency'] ?? 'weekly';
                
                $frequencySettings = [
                    'type' => $notificationType,
                    'frequency' => $frequency
                ];
                
                $member->addMetadata('notification_frequency', $frequencySettings);
                
                if ($frequency === 'never') {
                    if ($notificationType === 'all' || $notificationType === 'summary') {
                        $member->setNotificationsNewService(false);
                    }
                    if ($notificationType === 'all' || $notificationType === 'reflection') {
                        $member->setNotificationsReflection(false);
                    }
                }
                
                $this->entityManager->persist($member);
                $this->entityManager->flush();

                return [
                    'success' => true,
                    'message' => "Notificatie frequentie aangepast naar {$frequency}"
                ];

            case 'unsubscribe':
                $reason = $arguments['reason'] ?? 'Geen reden opgegeven';
                
                $member->setNotificationsNewService(false);
                $member->setNotificationsReflection(false);
                $member->addMetadata('unsubscribe_reason', $reason);
                $member->addMetadata('unsubscribe_date', (new \DateTime())->format('Y-m-d'));
                
                $this->entityManager->persist($member);
                $this->entityManager->flush();

                $this->logger->info('Member unsubscribed', [
                    'member_id' => $member->getId(),
                    'reason' => $reason
                ]);

                return [
                    'success' => true,
                    'message' => 'Je bent afgemeld voor alle notificaties'
                ];

            default:
                return [
                    'success' => false,
                    'message' => "Onbekende actie: {$action}"
                ];
        }
    }

    private function executeAnswerQuestion(array $arguments, Member $member): array
    {
        $question = $arguments['question'] ?? '';
        $category = $arguments['category'] ?? 'general';
        $needsSearch = $arguments['needs_search'] ?? false;

        $this->logger->info('Answering theological question', [
            'question' => $question,
            'category' => $category,
            'needs_search' => $needsSearch,
            'member_id' => $member->getId()
        ]);

        $member->addMetadata('last_question', [
            'question' => $question,
            'category' => $category,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);
        
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return [
            'success' => true,
            'message' => 'Vraag geregistreerd voor beantwoording',
            'question' => $question,
            'category' => $category,
            'needs_vector_search' => $needsSearch
        ];
    }

    private function executeProcessFeedback(array $arguments, Member $member): array
    {
        $type = $arguments['type'] ?? 'general';
        $message = $arguments['message'] ?? '';
        $severity = $arguments['severity'] ?? 'medium';

        $ticketId = 'TICKET-' . uniqid() . '-' . date('Ymd');
        
        $feedbackData = [
            'id' => $ticketId,
            'type' => $type,
            'message' => $message,
            'severity' => $severity,
            'member_id' => $member->getId(),
            'member_name' => $member->getFirstName() ?? 'Onbekend',
            'phone' => $member->getPhoneNumber(),
            'church_ids' => $member->getChurchIds(),
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'status' => 'open'
        ];

        $member->addMetadata('last_feedback', $feedbackData);
        $this->entityManager->persist($member);
        
        try {
            $submitted = $this->contentApiClient->submitFeedback($feedbackData);
            
            if ($submitted) {
                $this->logger->info('Feedback submitted to content service', [
                    'ticket_id' => $ticketId,
                    'type' => $type,
                    'severity' => $severity
                ]);
                
                $feedbackData['submitted_to_service'] = true;
            } else {
                $this->logger->warning('Failed to submit feedback to content service', [
                    'ticket_id' => $ticketId,
                    'fallback' => 'stored_locally'
                ]);
                
                $feedbackData['submitted_to_service'] = false;
                $feedbackData['fallback_storage'] = true;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error submitting feedback', [
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);
            
            $feedbackData['submitted_to_service'] = false;
            $feedbackData['error'] = $e->getMessage();
        }
        
        $member->setMetadata($member->getMetadata() ?? []);
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        if ($severity === 'high') {
            $this->logger->critical('High priority feedback received', $feedbackData);
        }

        $responseMessages = [
            'feedback' => 'Bedankt voor je feedback! We hebben het doorgestuurd (ticket: ' . $ticketId . ').',
            'complaint' => 'Je klacht is geregistreerd met nummer ' . $ticketId . '. We nemen zo snel mogelijk contact op.',
            'suggestion' => 'Bedankt voor je suggestie! We hebben het genoteerd (referentie: ' . $ticketId . ').',
            'question' => 'Je vraag is ontvangen met nummer ' . $ticketId . '. We komen er op terug.',
            'technical' => 'Het technische probleem is doorgegeven aan ons team (ticket: ' . $ticketId . ').'
        ];

        return [
            'success' => true,
            'message' => $responseMessages[$type] ?? "Feedback ontvangen (ticket: {$ticketId})",
            'ticket_id' => $ticketId,
            'routed_to_admins' => $feedbackData['submitted_to_service'] ?? false
        ];
    }

    public function processMultipleToolCalls(array $toolCalls, Member $member): array
    {
        $results = [];
        
        foreach ($toolCalls as $toolCall) {
            if (!isset($toolCall['name']) || !isset($toolCall['arguments'])) {
                continue;
            }
            
            $arguments = is_string($toolCall['arguments']) 
                ? json_decode($toolCall['arguments'], true) 
                : $toolCall['arguments'];
            
            $result = $this->executeTool($toolCall['name'], $arguments, $member);
            
            $results[] = [
                'call_id' => $toolCall['call_id'] ?? null,
                'tool' => $toolCall['name'],
                'result' => $result
            ];
        }
        
        return $results;
    }

    private function addMetadata(Member $member, string $key, mixed $value): void
    {
        $metadata = $member->getMetadata() ?? [];
        $metadata[$key] = $value;
        $member->setMetadata($metadata);
    }
}