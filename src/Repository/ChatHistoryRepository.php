<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatHistory;
use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChatHistory>
 */
class ChatHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatHistory::class);
    }

    public function save(ChatHistory $chatHistory, bool $flush = false): void
    {
        $this->getEntityManager()->persist($chatHistory);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ChatHistory $chatHistory, bool $flush = false): void
    {
        $this->getEntityManager()->remove($chatHistory);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByMember(Member $member, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('ch')
            ->where('ch.member = :member')
            ->setParameter('member', $member)
            ->orderBy('ch.createdAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function findByConversation(string $conversationId, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('ch')
            ->where('ch.conversationId = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->orderBy('ch.createdAt', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function findLastMessageByMember(Member $member): ?ChatHistory
    {
        return $this->createQueryBuilder('ch')
            ->where('ch.member = :member')
            ->setParameter('member', $member)
            ->orderBy('ch.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findConversationContext(string $conversationId, int $contextLimit = 10): array
    {
        return $this->createQueryBuilder('ch')
            ->where('ch.conversationId = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->orderBy('ch.createdAt', 'DESC')
            ->setMaxResults($contextLimit)
            ->getQuery()
            ->getResult();
    }

    public function findMessagesWithToolCalls(Member $member = null): array
    {
        $qb = $this->createQueryBuilder('ch')
            ->where('ch.toolCalls IS NOT NULL');

        if ($member !== null) {
            $qb->andWhere('ch.member = :member')
               ->setParameter('member', $member);
        }

        return $qb->orderBy('ch.createdAt', 'DESC')
                  ->getQuery()
                  ->getResult();
    }

    public function getConversationStats(string $conversationId): array
    {
        $results = $this->createQueryBuilder('ch')
            ->select('ch.role, COUNT(ch.id) as count')
            ->where('ch.conversationId = :conversationId')
            ->setParameter('conversationId', $conversationId)
            ->groupBy('ch.role')
            ->getQuery()
            ->getResult();

        $stats = [
            'total' => 0,
            'user' => 0,
            'assistant' => 0,
            'system' => 0
        ];

        foreach ($results as $result) {
            $count = (int) $result['count'];
            $stats[$result['role']] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    public function findRecentConversations(int $days = 7, ?int $limit = null): array
    {
        $since = new \DateTime("-{$days} days");
        
        $qb = $this->createQueryBuilder('ch')
            ->select('ch.conversationId, MAX(ch.createdAt) as lastActivity, COUNT(ch.id) as messageCount')
            ->where('ch.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('ch.conversationId')
            ->orderBy('lastActivity', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function cleanupOldHistory(\DateTime $before): int
    {
        return $this->createQueryBuilder('ch')
            ->delete()
            ->where('ch.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function getTokenUsageStats(\DateTime $since = null): array
    {
        $qb = $this->createQueryBuilder('ch')
            ->select('SUM(JSON_EXTRACT(ch.metadata, \'$.usage.input_tokens\')) as inputTokens')
            ->addSelect('SUM(JSON_EXTRACT(ch.metadata, \'$.usage.output_tokens\')) as outputTokens')
            ->where('ch.metadata IS NOT NULL');

        if ($since !== null) {
            $qb->andWhere('ch.createdAt >= :since')
               ->setParameter('since', $since);
        }

        $result = $qb->getQuery()->getSingleResult();

        return [
            'input_tokens' => (int) ($result['inputTokens'] ?? 0),
            'output_tokens' => (int) ($result['outputTokens'] ?? 0),
            'total_tokens' => (int) (($result['inputTokens'] ?? 0) + ($result['outputTokens'] ?? 0))
        ];
    }
}