<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChatHistoryRepository::class)]
#[ORM\Table(name: 'chat_history')]
#[ORM\Index(name: 'idx_member_id', columns: ['member_id'])]
#[ORM\Index(name: 'idx_conversation_id', columns: ['conversation_id'])]
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
class ChatHistory
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Member $member;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $conversationId;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [
        self::ROLE_USER,
        self::ROLE_ASSISTANT,
        self::ROLE_SYSTEM
    ])]
    private string $role;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $content;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $openaiResponseId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $toolCalls = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTime();
    }

    public function getId(): string
    {
        return $this->id;
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

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function setConversationId(string $conversationId): self
    {
        $this->conversationId = $conversationId;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getOpenaiResponseId(): ?string
    {
        return $this->openaiResponseId;
    }

    public function setOpenaiResponseId(?string $openaiResponseId): self
    {
        $this->openaiResponseId = $openaiResponseId;
        return $this;
    }

    public function getToolCalls(): ?array
    {
        return $this->toolCalls;
    }

    public function setToolCalls(?array $toolCalls): self
    {
        $this->toolCalls = $toolCalls;
        return $this;
    }

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public static function getRoles(): array
    {
        return [
            self::ROLE_USER,
            self::ROLE_ASSISTANT,
            self::ROLE_SYSTEM,
        ];
    }

    public static function createUserMessage(Member $member, string $conversationId, string $content): self
    {
        $history = new self();
        $history->setMember($member)
                ->setConversationId($conversationId)
                ->setRole(self::ROLE_USER)
                ->setContent($content);
        return $history;
    }

    public static function createAssistantMessage(
        Member $member, 
        string $conversationId, 
        string $content,
        ?string $responseId = null,
        ?array $toolCalls = null
    ): self {
        $history = new self();
        $history->setMember($member)
                ->setConversationId($conversationId)
                ->setRole(self::ROLE_ASSISTANT)
                ->setContent($content)
                ->setOpenaiResponseId($responseId)
                ->setToolCalls($toolCalls);
        return $history;
    }
}