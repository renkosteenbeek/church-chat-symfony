<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ContentStatus;
use App\Message\ContentReadyMessage;
use App\Repository\ContentStatusRepository;
use App\Repository\MemberRepository;
use App\Service\ContentApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ContentReadyHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberRepository $memberRepository,
        private readonly ContentStatusRepository $contentStatusRepository,
        private readonly ContentApiClient $contentApiClient,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(ContentReadyMessage $message): void
    {
        $this->logger->info('Processing content.ready event', [
            'sermon_id' => $message->sermonId,
            'church_id' => $message->churchId,
            'title' => $message->title
        ]);

        try {
            $members = $this->memberRepository->findActiveByChurchId($message->churchId);
            
            if (empty($members)) {
                $this->logger->warning('No active members found for church', [
                    'church_id' => $message->churchId
                ]);
                return;
            }

            $contentData = [
                'sermon_id' => $message->sermonId,
                'uuid' => $message->uuid,
                'title' => $message->title,
                'speaker' => $message->speaker,
                'service_date' => $message->serviceDate,
                'content_types' => $message->contentTypes,
                'openai_file_id' => $message->openaiFileId,
                'metadata' => $message->metadata
            ];

            foreach ($members as $member) {
                $existingStatus = $this->contentStatusRepository->findOneBy([
                    'contentId' => $message->sermonId,
                    'member' => $member
                ]);

                if ($existingStatus) {
                    $this->logger->debug('Content already queued for member', [
                        'member_id' => $member->getId(),
                        'content_id' => $message->sermonId
                    ]);
                    continue;
                }

                $contentStatus = new ContentStatus();
                $contentStatus->setContentId($message->sermonId);
                $contentStatus->setMember($member);
                $contentStatus->setStatus(ContentStatus::STATUS_QUEUED);
                $contentStatus->setMetadata($contentData);

                $churchIds = $member->getChurchIds();
                if (count($churchIds) > 1) {
                    $contentStatus->setStatus(ContentStatus::STATUS_WAITING);
                    $this->logger->info('Member belongs to multiple churches, setting status to WAITING', [
                        'member_id' => $member->getId(),
                        'church_ids' => $churchIds
                    ]);
                }

                $this->entityManager->persist($contentStatus);
            }

            $this->entityManager->flush();

            $this->logger->info('Content queued for distribution', [
                'sermon_id' => $message->sermonId,
                'member_count' => count($members)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process content.ready event', [
                'sermon_id' => $message->sermonId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}