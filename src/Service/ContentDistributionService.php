<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\ChatHistory;
use App\Entity\ContentStatus;
use App\Repository\ContentStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ContentDistributionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentStatusRepository $contentStatusRepository,
        private readonly OpenAIService $openAIService,
        private readonly EventPublisher $eventPublisher,
        private readonly ContentApiClient $contentApiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function processQueue(int $limit = 10): int
    {
        $items = $this->contentStatusRepository->findBy(
            ['status' => ContentStatus::STATUS_QUEUED],
            ['createdAt' => 'ASC'],
            $limit
        );

        $processedCount = 0;

        foreach ($items as $contentStatus) {
            try {
                $this->processContentItem($contentStatus);
                $processedCount++;
            } catch (\Exception $e) {
                $this->handleProcessingError($contentStatus, $e);
            }
        }

        return $processedCount;
    }

    private function processContentItem(ContentStatus $contentStatus): void
    {
        $member = $contentStatus->getMember();
        
        if (count($member->getChurchIds()) > 1) {
            $contentStatus->setStatus(ContentStatus::STATUS_WAITING);
            $this->entityManager->persist($contentStatus);
            $this->entityManager->flush();
            
            $this->logger->info('Member has multiple churches, setting to WAITING', [
                'member_id' => $member->getId(),
                'content_id' => $contentStatus->getContentId()
            ]);
            return;
        }

        $contentData = $this->prepareContentData($contentStatus);
        
        if (!$member->getConversationId()) {
            $churchId = $member->getChurchIds()[0] ?? 0;
            
            $conversation = $this->openAIService->createConversation(
                $member,
                $contentData['message'],
                $churchId
            );
            
            $member->setConversationId($conversation['thread_id']);
            
            $sermonData = [
                'sermon_id' => $contentStatus->getContentId(),
                'church_id' => $churchId,
                'title' => $contentData['title'] ?? 'Nieuwe preek',
                'metadata' => $contentStatus->getMetadata()
            ];
            $member->setActiveSermon($sermonData);
            
            $this->entityManager->persist($member);
        } else {
            $churchId = $member->getActiveSermon()['church_id'] ?? $member->getChurchIds()[0] ?? 0;
            
            $this->openAIService->sendMessage(
                $member->getConversationId(),
                $contentData['message'],
                $churchId,
                $member
            );
        }

        $chatHistory = new ChatHistory();
        $chatHistory->setMember($member);
        $chatHistory->setRole('assistant');
        $chatHistory->setContent($contentData['message']);
        $chatHistory->setMetadata([
            'content_id' => $contentStatus->getContentId(),
            'conversation_id' => $member->getConversationId()
        ]);
        $this->entityManager->persist($chatHistory);

        $this->eventPublisher->publishNotification(
            $member->getPhoneNumber(),
            $contentData['message'],
            [
                'content_id' => $contentStatus->getContentId(),
                'member_id' => $member->getId()
            ]
        );

        $contentStatus->setStatus(ContentStatus::STATUS_SENT);
        $contentStatus->setSentDate(new \DateTime());
        $this->entityManager->persist($contentStatus);
        
        $member->setLastActivity(new \DateTime());
        $this->entityManager->persist($member);
        
        $this->entityManager->flush();

        $this->logger->info('Content distributed successfully', [
            'content_id' => $contentStatus->getContentId(),
            'member_id' => $member->getId()
        ]);
    }

    private function prepareContentData(ContentStatus $contentStatus): array
    {
        $metadata = $contentStatus->getMetadata();
        
        $message = sprintf(
            "🎯 Nieuwe preek beschikbaar!\n\n" .
            "📖 Titel: %s\n" .
            "👤 Spreker: %s\n" .
            "📅 Datum: %s\n\n" .
            "Hier is een samenvatting van de preek. Je kunt vragen stellen of reflecteren op de inhoud.",
            $metadata['title'] ?? 'Onbekend',
            $metadata['speaker'] ?? 'Onbekend',
            $metadata['service_date'] ?? 'Onbekend'
        );

        if (!empty($metadata['content_types'])) {
            foreach ($metadata['content_types'] as $content) {
                if (isset($content['type']) && $content['type'] === 'summary') {
                    $audience = $content['audience'] ?? 'general';
                    
                    try {
                        $contentDetails = $this->contentApiClient->getContentDetails(
                            $contentStatus->getContentId(),
                            $audience
                        );
                        
                        if (!empty($contentDetails['content'])) {
                            $message = $contentDetails['content'];
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Could not fetch content details', [
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
            'title' => $metadata['title'] ?? null,
            'metadata' => $metadata
        ];
    }

    private function handleProcessingError(ContentStatus $contentStatus, \Exception $e): void
    {
        $this->logger->error('Failed to process content item', [
            'content_id' => $contentStatus->getContentId(),
            'member_id' => $contentStatus->getMember()->getId(),
            'error' => $e->getMessage()
        ]);

        $contentStatus->setStatus(ContentStatus::STATUS_ERROR);
        $contentStatus->setErrorMessage($e->getMessage());
        $contentStatus->setRetryCount($contentStatus->getRetryCount() + 1);
        
        if ($contentStatus->getRetryCount() < 3) {
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
}