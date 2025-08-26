<?php
declare(strict_types=1);

namespace App\Message;

class ProcessChatMessage
{
    public function __construct(
        private readonly string $messageId,
        private readonly string $conversationId,
        private readonly string $content,
        private readonly string $userId
    ) {}

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}