<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'members')]
#[ORM\Index(name: 'idx_phone_number', columns: ['phone_number'])]
#[ORM\Index(name: 'idx_last_activity', columns: ['last_activity'])]
#[UniqueEntity('phoneNumber')]
class Member
{
    public const TARGET_GROUP_VOLWASSEN = 'volwassen';
    public const TARGET_GROUP_VERDIEPING = 'verdieping';
    public const TARGET_GROUP_JONGEREN = 'jongeren';
    
    public const PLATFORM_SIGNAL = 'signal';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $openaiConversationId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: [
        self::TARGET_GROUP_VOLWASSEN,
        self::TARGET_GROUP_VERDIEPING,
        self::TARGET_GROUP_JONGEREN
    ])]
    private ?string $targetGroup = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 120)]
    private ?int $age = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $intakeCompleted = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notificationsNewService = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $notificationsReflection = true;

    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\+[1-9]\d{1,14}$/', message: 'Phone number must be in E.164 format')]
    private string $phoneNumber;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::PLATFORM_SIGNAL])]
    private string $platform = self::PLATFORM_SIGNAL;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $activeSince;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $activeSermonId = null;

    #[ORM\Column(type: Types::JSON)]
    private array $churchIds = [];

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $lastActivity;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->activeSince = new \DateTime();
        $this->lastActivity = new \DateTime();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOpenaiConversationId(): ?string
    {
        return $this->openaiConversationId;
    }

    public function setOpenaiConversationId(?string $openaiConversationId): self
    {
        $this->openaiConversationId = $openaiConversationId;
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getTargetGroup(): ?string
    {
        return $this->targetGroup;
    }

    public function setTargetGroup(?string $targetGroup): self
    {
        $this->targetGroup = $targetGroup;
        return $this;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(?int $age): self
    {
        $this->age = $age;
        return $this;
    }

    public function isIntakeCompleted(): bool
    {
        return $this->intakeCompleted;
    }

    public function setIntakeCompleted(bool $intakeCompleted): self
    {
        $this->intakeCompleted = $intakeCompleted;
        return $this;
    }

    public function isNotificationsNewService(): bool
    {
        return $this->notificationsNewService;
    }

    public function setNotificationsNewService(bool $notificationsNewService): self
    {
        $this->notificationsNewService = $notificationsNewService;
        return $this;
    }

    public function isNotificationsReflection(): bool
    {
        return $this->notificationsReflection;
    }

    public function setNotificationsReflection(bool $notificationsReflection): self
    {
        $this->notificationsReflection = $notificationsReflection;
        return $this;
    }

    public function getPhoneNumber(): string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(string $phoneNumber): self
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): self
    {
        $this->platform = $platform;
        return $this;
    }

    public function getActiveSince(): \DateTimeInterface
    {
        return $this->activeSince;
    }

    public function setActiveSince(\DateTimeInterface $activeSince): self
    {
        $this->activeSince = $activeSince;
        return $this;
    }

    public function getActiveSermonId(): ?string
    {
        return $this->activeSermonId;
    }

    public function setActiveSermonId(?string $activeSermonId): self
    {
        $this->activeSermonId = $activeSermonId;
        return $this;
    }

    public function getChurchIds(): array
    {
        return $this->churchIds;
    }

    public function setChurchIds(array $churchIds): self
    {
        $this->churchIds = $churchIds;
        return $this;
    }

    public function addChurchId(int $churchId): self
    {
        if (!in_array($churchId, $this->churchIds)) {
            $this->churchIds[] = $churchId;
        }
        return $this;
    }

    public function removeChurchId(int $churchId): self
    {
        $this->churchIds = array_values(array_diff($this->churchIds, [$churchId]));
        return $this;
    }

    public function hasMultipleChurches(): bool
    {
        return count($this->churchIds) > 1;
    }

    public function isMemberOfChurch(int $churchId): bool
    {
        return in_array($churchId, $this->churchIds);
    }

    public function getLastActivity(): \DateTimeInterface
    {
        return $this->lastActivity;
    }

    public function setLastActivity(\DateTimeInterface $lastActivity): self
    {
        $this->lastActivity = $lastActivity;
        return $this;
    }

    public function updateLastActivity(): self
    {
        $this->lastActivity = new \DateTime();
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public static function getTargetGroups(): array
    {
        return [
            self::TARGET_GROUP_VOLWASSEN,
            self::TARGET_GROUP_VERDIEPING,
            self::TARGET_GROUP_JONGEREN,
        ];
    }
}