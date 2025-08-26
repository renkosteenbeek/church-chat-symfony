<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentStatus;
use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentStatus>
 */
class ContentStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentStatus::class);
    }

    public function save(ContentStatus $contentStatus, bool $flush = false): void
    {
        $this->getEntityManager()->persist($contentStatus);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ContentStatus $contentStatus, bool $flush = false): void
    {
        $this->getEntityManager()->remove($contentStatus);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByStatus(string $status, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('cs')
            ->where('cs.status = :status')
            ->setParameter('status', $status)
            ->orderBy('cs.createdAt', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function findQueuedContent(int $limit = 10): array
    {
        return $this->createQueryBuilder('cs')
            ->where('cs.status = :status')
            ->setParameter('status', ContentStatus::STATUS_QUEUED)
            ->orderBy('cs.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findScheduledContentReady(): array
    {
        return $this->createQueryBuilder('cs')
            ->where('cs.status = :status')
            ->andWhere('cs.scheduleDate <= :now OR cs.scheduleDate IS NULL')
            ->setParameter('status', ContentStatus::STATUS_SCHEDULED)
            ->setParameter('now', new \DateTime())
            ->orderBy('cs.scheduleDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByMemberAndContent(Member $member, string $contentId): ?ContentStatus
    {
        return $this->createQueryBuilder('cs')
            ->where('cs.member = :member')
            ->andWhere('cs.contentId = :contentId')
            ->setParameter('member', $member)
            ->setParameter('contentId', $contentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByChurch(int $churchId, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('cs')
            ->where('cs.churchId = :churchId')
            ->setParameter('churchId', $churchId);

        if ($status !== null) {
            $qb->andWhere('cs.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->orderBy('cs.createdAt', 'DESC')
                  ->getQuery()
                  ->getResult();
    }

    public function findErrorsForRetry(int $maxRetries = 3): array
    {
        return $this->createQueryBuilder('cs')
            ->where('cs.status = :status')
            ->andWhere('cs.retryCount < :maxRetries')
            ->setParameter('status', ContentStatus::STATUS_ERROR)
            ->setParameter('maxRetries', $maxRetries)
            ->orderBy('cs.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getStatsByChurch(int $churchId, \DateTime $since = null): array
    {
        $qb = $this->createQueryBuilder('cs')
            ->select('cs.status, COUNT(cs.id) as count')
            ->where('cs.churchId = :churchId')
            ->setParameter('churchId', $churchId)
            ->groupBy('cs.status');

        if ($since !== null) {
            $qb->andWhere('cs.createdAt >= :since')
               ->setParameter('since', $since);
        }

        $results = $qb->getQuery()->getResult();
        
        $stats = [];
        foreach ($results as $result) {
            $stats[$result['status']] = (int) $result['count'];
        }

        return $stats;
    }

    public function markAsProcessing(array $contentStatuses): void
    {
        $ids = array_map(fn($cs) => $cs->getId(), $contentStatuses);
        
        $this->createQueryBuilder('cs')
            ->update()
            ->set('cs.status', ':status')
            ->set('cs.updatedAt', ':now')
            ->where('cs.id IN (:ids)')
            ->setParameter('status', ContentStatus::STATUS_QUEUED)
            ->setParameter('now', new \DateTime())
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function cleanupOldSentContent(\DateTime $before): int
    {
        return $this->createQueryBuilder('cs')
            ->delete()
            ->where('cs.status = :status')
            ->andWhere('cs.sentDate < :before')
            ->setParameter('status', ContentStatus::STATUS_SENT)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}