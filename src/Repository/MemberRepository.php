<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Member>
 */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    public function save(Member $member, bool $flush = false): void
    {
        $this->getEntityManager()->persist($member);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Member $member, bool $flush = false): void
    {
        $this->getEntityManager()->remove($member);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByPhoneNumber(string $phoneNumber): ?Member
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.phoneNumber = :phoneNumber')
            ->setParameter('phoneNumber', $phoneNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByChurch(int $churchId, ?array $targetGroups = null): array
    {
        $qb = $this->createQueryBuilder('m');

        if (!empty($targetGroups)) {
            $qb->andWhere('m.targetGroup IN (:targetGroups)')
               ->setParameter('targetGroups', $targetGroups);
        }

        $results = $qb->orderBy('m.firstName', 'ASC')
                     ->getQuery()
                     ->getResult();
        
        // Filter by church ID in PHP
        return array_filter($results, function($member) use ($churchId) {
            $churchIds = $member->getChurchIds();
            return in_array($churchId, $churchIds);
        });
    }

    public function findMembersWithMultipleChurches(): array
    {
        $results = $this->createQueryBuilder('m')
            ->getQuery()
            ->getResult();
        
        // Filter members with multiple churches in PHP
        return array_filter($results, function($member) {
            return count($member->getChurchIds()) > 1;
        });
    }

    public function findActiveMembers(\DateTime $since = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.intakeCompleted = true');

        if ($since !== null) {
            $qb->andWhere('m.lastActivity >= :since')
               ->setParameter('since', $since);
        }

        return $qb->orderBy('m.lastActivity', 'DESC')
                  ->getQuery()
                  ->getResult();
    }

    public function findMembersNeedingIntake(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.intakeCompleted = false')
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByActiveSermon(string $sermonId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.activeSermonId = :sermonId')
            ->setParameter('sermonId', $sermonId)
            ->getQuery()
            ->getResult();
    }

    public function updateConversationId(Member $member, string $conversationId): void
    {
        $member->setOpenaiConversationId($conversationId);
        $this->save($member, true);
    }

    public function resetConversationsForChurch(int $churchId): int
    {
        return $this->createQueryBuilder('m')
            ->update()
            ->set('m.openaiConversationId', ':null')
            ->set('m.activeSermonId', ':null')
            ->where('JSON_CONTAINS(m.churchIds, :churchId, \'$\') = 1')
            ->setParameter('null', null)
            ->setParameter('churchId', json_encode($churchId))
            ->getQuery()
            ->execute();
    }

    public function findActiveByChurchId(int $churchId): array
    {
        $results = $this->createQueryBuilder('m')
            ->where('m.intakeCompleted = true')
            ->andWhere('m.notificationsNewService = true')
            ->orderBy('m.firstName', 'ASC')
            ->getQuery()
            ->getResult();
        
        // Filter by church ID in PHP since JSON_CONTAINS is not available in DQL
        return array_filter($results, function($member) use ($churchId) {
            $churchIds = $member->getChurchIds();
            return in_array($churchId, $churchIds);
        });
    }
}