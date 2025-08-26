<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentStatusRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContentStatusRepository::class)]
#[ORM\Table(name: 'content_status')]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_church_id', columns: ['church_id'])]
#[ORM\Index(name: 'idx_schedule_date', columns: ['schedule_date'])]
#[ORM\Index(name: 'idx_member_status', columns: ['member_id', 'status'])]
class ContentStatus
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $contentId;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Member $member;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotNull]
    private int $churchId;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [
        self::STATUS_SCHEDULED,
        self::STATUS_WAITING,
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_ERROR
    ])]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $scheduleDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $sentDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getContentId(): string
    {
        return $this->contentId;
    }

    public function setContentId(string $contentId): self
    {
        $this->contentId = $contentId;
        return $this;
    }

    public function getMember(): Member
    {
        return $this->member;
    }

    public function setMember(Member $member): self
    {
        $this->member = $member;
        return $this;
    }

    public function getChurchId(): int
    {
        return $this->churchId;
    }

    public function setChurchId(int $churchId): self
    {
        $this->churchId = $churchId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getScheduleDate(): ?\DateTimeInterface
    {
        return $this->scheduleDate;
    }

    public function setScheduleDate(?\DateTimeInterface $scheduleDate): self
    {
        $this->scheduleDate = $scheduleDate;
        return $this;
    }

    public function getSentDate(): ?\DateTimeInterface
    {
        return $this->sentDate;
    }

    public function setSentDate(?\DateTimeInterface $sentDate): self
    {
        $this->sentDate = $sentDate;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(int $retryCount): self
    {
        $this->retryCount = $retryCount;
        return $this;
    }

    public function incrementRetryCount(): self
    {
        $this->retryCount++;
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

    public function markAsSent(): self
    {
        $this->status = self::STATUS_SENT;
        $this->sentDate = new \DateTime();
        $this->errorMessage = null;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function markAsError(string $errorMessage): self
    {
        $this->status = self::STATUS_ERROR;
        $this->errorMessage = $errorMessage;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function markAsWaiting(): self
    {
        $this->status = self::STATUS_WAITING;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function isReadyToSend(): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED) {
            return false;
        }

        if ($this->scheduleDate === null) {
            return true;
        }

        return $this->scheduleDate <= new \DateTime();
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_SCHEDULED,
            self::STATUS_WAITING,
            self::STATUS_QUEUED,
            self::STATUS_SENT,
            self::STATUS_ERROR,
        ];
    }
}