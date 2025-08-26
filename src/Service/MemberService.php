<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Member;
use App\Repository\MemberRepository;
use Psr\Log\LoggerInterface;

class MemberService
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function findOrCreateByPhone(string $phoneNumber): Member
    {
        $member = $this->memberRepository->findByPhoneNumber($phoneNumber);
        
        if ($member === null) {
            $member = new Member();
            $member->setPhoneNumber($phoneNumber);
            $this->memberRepository->save($member, true);
            
            $this->logger->info('Created new member', [
                'phone_number' => $phoneNumber,
                'member_id' => $member->getId()
            ]);
        }
        
        return $member;
    }

    public function updateFromToolCall(Member $member, array $data): void
    {
        $updated = false;
        
        if (isset($data['name']) && $data['name'] !== $member->getFirstName()) {
            $member->setFirstName($data['name']);
            $updated = true;
        }
        
        if (isset($data['age']) && $data['age'] !== $member->getAge()) {
            $member->setAge($data['age']);
            $updated = true;
        }
        
        if ($updated) {
            $member->setUpdatedAt(new \DateTime());
            $this->memberRepository->save($member, true);
            
            $this->logger->info('Updated member from tool call', [
                'member_id' => $member->getId(),
                'updated_fields' => array_keys($data)
            ]);
        }
    }

    public function getMembersByChurch(int $churchId, array $targetGroups = []): array
    {
        return $this->memberRepository->findByChurch($churchId, empty($targetGroups) ? null : $targetGroups);
    }

    public function resetConversation(Member $member, ?string $newSermonId = null): void
    {
        $oldConversationId = $member->getOpenaiConversationId();
        
        $member->setOpenaiConversationId(null);
        
        if ($newSermonId !== null) {
            $member->setActiveSermonId($newSermonId);
        }
        
        $member->setUpdatedAt(new \DateTime());
        $this->memberRepository->save($member, true);
        
        $this->logger->info('Reset member conversation', [
            'member_id' => $member->getId(),
            'old_conversation_id' => $oldConversationId,
            'new_sermon_id' => $newSermonId
        ]);
    }

    public function getMembersNeedingIntake(): array
    {
        return $this->memberRepository->findMembersNeedingIntake();
    }

    public function getActiveMembers(?\DateTime $since = null): array
    {
        return $this->memberRepository->findActiveMembers($since);
    }

    public function getMembersWithMultipleChurches(): array
    {
        return $this->memberRepository->findMembersWithMultipleChurches();
    }

    public function completeIntake(Member $member, array $data): void
    {
        if (isset($data['first_name'])) {
            $member->setFirstName($data['first_name']);
        }
        
        if (isset($data['age'])) {
            $member->setAge($data['age']);
        }
        
        if (isset($data['target_group'])) {
            $member->setTargetGroup($data['target_group']);
        }
        
        $member->setIntakeCompleted(true);
        $member->setUpdatedAt(new \DateTime());
        
        $this->memberRepository->save($member, true);
        
        $this->logger->info('Completed member intake', [
            'member_id' => $member->getId(),
            'phone_number' => $member->getPhoneNumber()
        ]);
    }

    public function updateNotificationPreferences(Member $member, array $preferences): void
    {
        if (isset($preferences['new_service'])) {
            $member->setNotificationsNewService($preferences['new_service']);
        }
        
        if (isset($preferences['reflection'])) {
            $member->setNotificationsReflection($preferences['reflection']);
        }
        
        $member->setUpdatedAt(new \DateTime());
        $this->memberRepository->save($member, true);
        
        $this->logger->info('Updated notification preferences', [
            'member_id' => $member->getId(),
            'preferences' => $preferences
        ]);
    }
}