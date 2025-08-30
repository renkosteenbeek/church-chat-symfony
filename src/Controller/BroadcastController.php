<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentStatus;
use App\Repository\ContentStatusRepository;
use App\Repository\MemberRepository;
use App\Service\ContentDistributionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/content')]
class BroadcastController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberRepository $memberRepository,
        private readonly ContentStatusRepository $contentStatusRepository,
        private readonly ContentDistributionService $distributionService,
        private readonly LoggerInterface $logger
    ) {}

    #[Route('/broadcast', methods: ['POST'])]
    public function broadcast(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['sermon_id']) || !isset($data['church_id'])) {
            return new JsonResponse([
                'error' => 'Missing required fields: sermon_id, church_id'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $members = $this->memberRepository->findActiveByChurchId($data['church_id']);
            
            if (empty($members)) {
                return new JsonResponse([
                    'message' => 'No active members found for church',
                    'church_id' => $data['church_id'],
                    'queued_count' => 0
                ]);
            }

            $queuedCount = 0;
            $skippedCount = 0;

            foreach ($members as $member) {
                $existingStatus = $this->contentStatusRepository->findOneBy([
                    'contentId' => $data['sermon_id'],
                    'member' => $member
                ]);

                if ($existingStatus) {
                    $skippedCount++;
                    continue;
                }

                $contentStatus = new ContentStatus();
                $contentStatus->setContentId($data['sermon_id']);
                $contentStatus->setMember($member);

                $churchIds = $member->getChurchIds();
                if (count($churchIds) > 1) {
                    $contentStatus->setStatus(ContentStatus::STATUS_WAITING);
                } else {
                    $contentStatus->setStatus(ContentStatus::STATUS_QUEUED);
                    $queuedCount++;
                }

                if (isset($data['schedule_date'])) {
                    $scheduleDate = new \DateTime($data['schedule_date']);
                    $contentStatus->setScheduleDate($scheduleDate);
                    $contentStatus->setStatus(ContentStatus::STATUS_SCHEDULED);
                }

                $this->entityManager->persist($contentStatus);
            }

            $this->entityManager->flush();

            $this->logger->info('Content broadcast initiated', [
                'sermon_id' => $data['sermon_id'],
                'church_id' => $data['church_id'],
                'queued' => $queuedCount,
                'skipped' => $skippedCount
            ]);

            return new JsonResponse([
                'message' => 'Content queued for broadcast',
                'sermon_id' => $data['sermon_id'],
                'church_id' => $data['church_id'],
                'queued_count' => $queuedCount,
                'skipped_count' => $skippedCount
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to broadcast content', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Failed to broadcast content',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/queue/status', methods: ['GET'])]
    public function queueStatus(): JsonResponse
    {
        try {
            $stats = [
                'scheduled' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_SCHEDULED]),
                'waiting' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_WAITING]),
                'queued' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_QUEUED]),
                'sent' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_SENT]),
                'error' => $this->contentStatusRepository->count(['status' => ContentStatus::STATUS_ERROR])
            ];

            $pendingItems = $this->contentStatusRepository->findBy(
                ['status' => ContentStatus::STATUS_QUEUED],
                ['createdAt' => 'ASC'],
                10
            );

            $pending = array_map(function($item) {
                return [
                    'id' => $item->getId(),
                    'content_id' => $item->getContentId(),
                    'member_id' => $item->getMember()->getId(),
                    'member_name' => $item->getMember()->getFirstName(),
                    'created_at' => $item->getCreatedAt()->format('Y-m-d H:i:s')
                ];
            }, $pendingItems);

            return new JsonResponse([
                'stats' => $stats,
                'pending' => $pending,
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get queue status', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Failed to get queue status',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/queue/process', methods: ['POST'])]
    public function processQueue(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $limit = $data['limit'] ?? 10;

        try {
            $processed = $this->distributionService->processQueue($limit);

            return new JsonResponse([
                'message' => 'Queue processed',
                'processed_count' => $processed,
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process queue', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Failed to process queue',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/status/{statusId}/retry', methods: ['POST'])]
    public function retryContent(string $statusId): JsonResponse
    {
        try {
            $contentStatus = $this->contentStatusRepository->find($statusId);
            
            if (!$contentStatus) {
                return new JsonResponse([
                    'error' => 'Content status not found'
                ], Response::HTTP_NOT_FOUND);
            }

            if ($contentStatus->getStatus() !== ContentStatus::STATUS_ERROR) {
                return new JsonResponse([
                    'error' => 'Can only retry failed content',
                    'current_status' => $contentStatus->getStatus()
                ], Response::HTTP_BAD_REQUEST);
            }

            $contentStatus->setStatus(ContentStatus::STATUS_QUEUED);
            $contentStatus->setRetryCount($contentStatus->getRetryCount() + 1);
            
            $this->entityManager->persist($contentStatus);
            $this->entityManager->flush();

            $this->logger->info('Content queued for retry', [
                'status_id' => $statusId,
                'retry_count' => $contentStatus->getRetryCount()
            ]);

            return new JsonResponse([
                'message' => 'Content queued for retry',
                'status_id' => $statusId,
                'retry_count' => $contentStatus->getRetryCount()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to retry content', [
                'status_id' => $statusId,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Failed to retry content',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}