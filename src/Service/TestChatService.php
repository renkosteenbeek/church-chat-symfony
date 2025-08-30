<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Member;
use App\Repository\MemberRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class TestChatService
{
    public function __construct(
        private readonly OpenAIService $openAIService,
        private readonly MemberRepository $memberRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function createMockMember(array $config = []): Member
    {
        $member = new Member();
        $member->setPhoneNumber($config['phone'] ?? $this->generateUniquePhoneNumber());
        $member->setFirstName($config['name'] ?? 'TestUser');
        $member->setAge($config['age'] ?? 30);
        $member->setTargetGroup($config['target_group'] ?? Member::TARGET_GROUP_VOLWASSEN);
        $member->setChurchIds($config['church_ids'] ?? [1]);
        
        if (isset($config['conversation_id'])) {
            $member->setOpenaiConversationId($config['conversation_id']);
        }
        
        if (isset($config['sermon_id'])) {
            $member->setActiveSermonId($config['sermon_id']);
        }

        return $member;
    }

    public function initializeTestConversation(Member $member, ?string $welcomeMessage = null): array
    {
        $welcomeMessage = $welcomeMessage ?? $this->getDefaultWelcomeMessage($member);
        
        try {
            $conversationData = $this->openAIService->createConversation($member, $welcomeMessage);
            
            $this->logger->info('Test conversation initialized', [
                'member_id' => $member->getId(),
                'conversation_id' => $conversationData['id'] ?? null,
                'target_group' => $member->getTargetGroup()
            ]);
            
            return [
                'success' => true,
                'conversation_id' => $conversationData['id'] ?? null,
                'welcome_message' => $welcomeMessage,
                'member_profile' => [
                    'name' => $member->getFirstName(),
                    'target_group' => $member->getTargetGroup(),
                    'church_ids' => $member->getChurchIds()
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize test conversation', [
                'error' => $e->getMessage(),
                'member_id' => $member->getId()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'member_profile' => [
                    'name' => $member->getFirstName(),
                    'target_group' => $member->getTargetGroup()
                ]
            ];
        }
    }

    public function sendTestMessage(Member $member, string $message, ?string $conversationId = null): array
    {
        $conversationId = $conversationId ?? $member->getOpenaiConversationId();
        
        if (!$conversationId) {
            return [
                'success' => false,
                'error' => 'No conversation ID available'
            ];
        }

        $churchId = !empty($member->getChurchIds()) ? $member->getChurchIds()[0] : 1;
        $startTime = microtime(true);
        
        try {
            $response = $this->openAIService->sendMessage(
                $conversationId,
                $message,
                $churchId,
                $member
            );
            
            $endTime = microtime(true);
            $latencyMs = intval(($endTime - $startTime) * 1000);
            
            $responseText = $this->openAIService->extractResponseText($response);
            $toolCalls = $this->openAIService->extractToolCalls($response);
            
            $this->logger->info('Test message sent successfully', [
                'conversation_id' => $conversationId,
                'latency_ms' => $latencyMs,
                'tool_calls_count' => count($toolCalls)
            ]);
            
            return [
                'success' => true,
                'response' => $responseText,
                'tool_calls' => array_map(fn($tc) => [
                    'name' => $tc['name'] ?? null,
                    'arguments' => $tc['arguments'] ?? null
                ], $toolCalls),
                'latency_ms' => $latencyMs,
                'raw_response' => $response
            ];
            
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $latencyMs = intval(($endTime - $startTime) * 1000);
            
            $this->logger->error('Test message failed', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversationId,
                'latency_ms' => $latencyMs
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'latency_ms' => $latencyMs
            ];
        }
    }

    public function validateResponse(array $actual, array $expected): array
    {
        $results = [
            'success' => true,
            'validations' => []
        ];

        if (isset($expected['tool_calls'])) {
            $actualToolNames = array_map(fn($tc) => $tc['name'], $actual['tool_calls'] ?? []);
            $expectedToolNames = $expected['tool_calls'];
            
            $toolCallsMatch = empty(array_diff($expectedToolNames, $actualToolNames));
            
            $results['validations']['tool_calls'] = [
                'expected' => $expectedToolNames,
                'actual' => $actualToolNames,
                'success' => $toolCallsMatch
            ];
            
            if (!$toolCallsMatch) {
                $results['success'] = false;
            }
        }

        if (isset($expected['response_contains'])) {
            $responseText = $actual['response'] ?? '';
            $contains = [];
            
            foreach ($expected['response_contains'] as $needle) {
                $found = stripos($responseText, $needle) !== false;
                $contains[] = [
                    'text' => $needle,
                    'found' => $found
                ];
                
                if (!$found) {
                    $results['success'] = false;
                }
            }
            
            $results['validations']['response_contains'] = $contains;
        }

        if (isset($expected['response_min_length'])) {
            $responseLength = strlen($actual['response'] ?? '');
            $minLength = $expected['response_min_length'];
            $lengthValid = $responseLength >= $minLength;
            
            $results['validations']['response_length'] = [
                'expected_min' => $minLength,
                'actual' => $responseLength,
                'success' => $lengthValid
            ];
            
            if (!$lengthValid) {
                $results['success'] = false;
            }
        }

        return $results;
    }

    public function analyzeTestResults(array $results): array
    {
        $totalTests = count($results);
        $successfulTests = array_filter($results, fn($r) => $r['success']);
        $successRate = $totalTests > 0 ? count($successfulTests) / $totalTests : 0;
        
        $latencies = array_column($results, 'latency_ms');
        $avgLatency = !empty($latencies) ? array_sum($latencies) / count($latencies) : 0;
        $p95Latency = !empty($latencies) ? $this->calculatePercentile($latencies, 95) : 0;
        
        $failedScenarios = array_filter($results, fn($r) => !$r['success']);
        $commonFailures = [];
        
        foreach ($failedScenarios as $failure) {
            if (isset($failure['validation_results']['validations']['tool_calls']) && 
                !$failure['validation_results']['validations']['tool_calls']['success']) {
                $commonFailures[] = 'Tool calling';
            }
            
            if (isset($failure['error']) && strpos($failure['error'], 'context') !== false) {
                $commonFailures[] = 'Context handling';
            }
        }
        
        return [
            'summary' => [
                'total_tests' => $totalTests,
                'successful_tests' => count($successfulTests),
                'success_rate' => round($successRate, 2),
                'avg_latency_ms' => round($avgLatency, 0),
                'p95_latency_ms' => round($p95Latency, 0)
            ],
            'common_failures' => array_unique($commonFailures),
            'performance' => [
                'fastest_ms' => !empty($latencies) ? min($latencies) : 0,
                'slowest_ms' => !empty($latencies) ? max($latencies) : 0
            ]
        ];
    }

    private function calculatePercentile(array $values, int $percentile): float
    {
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        
        if (floor($index) == $index) {
            return $values[(int)$index];
        }
        
        $lower = $values[(int)floor($index)];
        $upper = $values[(int)ceil($index)];
        
        return $lower + ($upper - $lower) * ($index - floor($index));
    }

    private function generateUniquePhoneNumber(): string
    {
        return '+31' . mt_rand(600000000, 699999999);
    }

    private function getDefaultWelcomeMessage(Member $member): string
    {
        $name = $member->getFirstName() ?? 'daar';
        
        return sprintf(
            "Welkom %s! 🙏\n\n" .
            "Dit is een test conversatie. Ik help je graag met:\n" .
            "• Vragen over de preek\n" .
            "• Bijbelteksten en hun betekenis\n" .
            "• Geloofsvragen\n\n" .
            "Wat kan ik voor je doen?",
            $name
        );
    }
}