<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentStatus;
use App\Repository\ContentStatusRepository;
use App\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ContentDistributionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentStatusRepository $contentStatusRepository,
        private readonly MemberRepository $memberRepository,
        private readonly OpenAIService $openAIService,
        private readonly SignalServiceClient $signalServiceClient,
        private readonly ContentApiClient $contentApiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function processQueue(int $limit = 10, bool $parallel = false): int
    {
        $items = $this->contentStatusRepository->findBy(
            ['status' => ContentStatus::STATUS_QUEUED],
            ['createdAt' => 'ASC'],
            $limit
        );

        if (empty($items)) {
            return 0;
        }

        $processedCount = 0;

        if ($parallel && extension_loaded('pcntl')) {
            $processedCount = $this->processParallel($items);
        } else {
            foreach ($items as $contentStatus) {
                try {
                    $this->processContentItem($contentStatus);
                    $processedCount++;
                } catch (\Exception $e) {
                    $this->handleProcessingError($contentStatus, $e);
                }
            }
        }

        return $processedCount;
    }

    private function processParallel(array $items): int
    {
        $maxProcesses = (int) ($_ENV['PARALLEL_PROCESSES'] ?? 3);
        $activeProcesses = [];
        $processedCount = 0;
        
        foreach ($items as $index => $contentStatus) {
            while (count($activeProcesses) >= $maxProcesses) {
                foreach ($activeProcesses as $key => $pid) {
                    $status = null;
                    if (pcntl_waitpid($pid, $status, WNOHANG) > 0) {
                        unset($activeProcesses[$key]);
                        $processedCount++;
                    }
                }
                usleep(100000);
            }
            
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->logger->error('Could not fork process');
                $this->processContentItem($contentStatus);
                $processedCount++;
            } elseif ($pid === 0) {
                try {
                    $this->processContentItem($contentStatus);
                } catch (\Exception $e) {
                    $this->handleProcessingError($contentStatus, $e);
                }
                exit(0);
            } else {
                $activeProcesses[] = $pid;
            }
        }
        
        while (!empty($activeProcesses)) {
            foreach ($activeProcesses as $key => $pid) {
                $status = null;
                if (pcntl_waitpid($pid, $status, WNOHANG) > 0) {
                    unset($activeProcesses[$key]);
                    $processedCount++;
                }
            }
            usleep(100000);
        }
        
        return $processedCount;
    }

    private function processContentItem(ContentStatus $contentStatus): void
    {
        $member = $contentStatus->getMember();
        
        if ($member->hasMultipleChurches()) {
            $contentStatus->markAsWaiting();
            $this->entityManager->persist($contentStatus);
            $this->entityManager->flush();
            
            $this->logger->info('Member has multiple churches, setting to WAITING', [
                'member_id' => $member->getId(),
                'content_id' => $contentStatus->getContentId(),
                'church_ids' => $member->getChurchIds()
            ]);
            return;
        }

        $contentData = $this->prepareContentMessage($contentStatus);
        $churchId = $contentStatus->getChurchId();
        
        $currentSermonId = $member->getActiveSermonId();
        $newSermonId = $contentStatus->getContentId();
        
        if ($currentSermonId !== $newSermonId) {
            $this->logger->info('New sermon detected, creating new conversation', [
                'member_id' => $member->getId(),
                'old_sermon' => $currentSermonId,
                'new_sermon' => $newSermonId
            ]);
            
            $conversation = $this->openAIService->createConversation(
                $member,
                $contentData['message']
            );
            
            if (isset($conversation['id'])) {
                $member->setOpenaiConversationId($conversation['id']);
                $member->setActiveSermonId($newSermonId);
                $this->entityManager->persist($member);
            }
        } else {
            $this->logger->info('Same sermon, using existing conversation', [
                'member_id' => $member->getId(),
                'conversation_id' => $member->getOpenaiConversationId(),
                'sermon_id' => $newSermonId
            ]);
            
            if ($member->getOpenaiConversationId()) {
                $this->openAIService->sendMessage(
                    $member->getOpenaiConversationId(),
                    $contentData['message'],
                    $churchId,
                    $member
                );
            }
        }

        $this->signalServiceClient->sendMessage(
            $member->getPhoneNumber(),
            $contentData['message'],
            [
                'content_id' => $contentStatus->getContentId(),
                'member_id' => $member->getId(),
                'type' => 'content_broadcast'
            ]
        );

        $contentStatus->markAsSent();
        $this->entityManager->persist($contentStatus);
        
        $member->updateLastActivity();
        $this->entityManager->persist($member);
        
        $this->entityManager->flush();

        $this->logger->info('Content distributed successfully', [
            'content_id' => $contentStatus->getContentId(),
            'member_id' => $member->getId(),
            'conversation_id' => $member->getOpenaiConversationId()
        ]);
    }

    private function prepareContentMessage(ContentStatus $contentStatus): array
    {
        $metadata = $contentStatus->getMetadata() ?? [];
        
        $title = $metadata['title'] ?? 'Nieuwe preek';
        $speaker = $metadata['speaker'] ?? 'Onbekend';
        $serviceDate = $metadata['service_date'] ?? date('Y-m-d');
        
        $message = "🎯 Nieuwe preek beschikbaar!\n\n";
        $message .= "📖 **{$title}**\n";
        $message .= "👤 Spreker: {$speaker}\n";
        $message .= "📅 Datum: {$serviceDate}\n\n";
        $message .= "Was je bij de dienst? Ik kan je een samenvatting geven of vragen beantwoorden over de preek.";

        if (isset($metadata['content_types']) && is_array($metadata['content_types'])) {
            foreach ($metadata['content_types'] as $content) {
                if (isset($content['type']) && $content['type'] === 'summary_intro') {
                    try {
                        $member = $contentStatus->getMember();
                        $targetGroup = $member->getTargetGroup() ?? 'volwassen';
                        
                        $contentDetails = $this->contentApiClient->getContentDetails(
                            $contentStatus->getContentId(),
                            $targetGroup
                        );
                        
                        if (!empty($contentDetails['intro_message'])) {
                            $message = $contentDetails['intro_message'];
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Could not fetch custom intro message', [
                            'content_id' => $contentStatus->getContentId(),
                            'error' => $e->getMessage()
                        ]);
                    }
                    break;
                }
            }
        }

        return [
            'message' => $message,
            'title' => $title,
            'metadata' => $metadata
        ];
    }

    private function handleProcessingError(ContentStatus $contentStatus, \Exception $e): void
    {
        $this->logger->error('Failed to process content item', [
            'content_id' => $contentStatus->getContentId(),
            'member_id' => $contentStatus->getMember()->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $contentStatus->incrementRetryCount();
        
        if ($contentStatus->getRetryCount() >= 3) {
            $contentStatus->markAsError($e->getMessage());
        } else {
            $contentStatus->setStatus(ContentStatus::STATUS_QUEUED);
        }
        
        $this->entityManager->persist($contentStatus);
        $this->entityManager->flush();
    }

    public function processScheduledContent(): int
    {
        $now = new \DateTime();
        
        $items = $this->contentStatusRepository->createQueryBuilder('cs')
            ->where('cs.status = :status')
            ->andWhere('cs.scheduleDate <= :now')
            ->setParameter('status', ContentStatus::STATUS_SCHEDULED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($items as $contentStatus) {
            $contentStatus->setStatus(ContentStatus::STATUS_QUEUED);
            $this->entityManager->persist($contentStatus);
            $count++;
        }
        
        if ($count > 0) {
            $this->entityManager->flush();
            $this->logger->info('Moved scheduled content to queue', [
                'count' => $count
            ]);
        }

        return $count;
    }

    public function getQueueStatistics(): array
    {
        $stats = [
            'queued' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_QUEUED]),
            'scheduled' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_SCHEDULED]),
            'waiting' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_WAITING]),
            'sent' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_SENT]),
            'error' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_ERROR]),
        ];
        
        $stats['total'] = array_sum($stats);
        
        return $stats;
    }
}