<?php
declare(strict_types=1);

namespace App\Message;

class NotificationSendMessage
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $recipient,
        public readonly string $message,
        public readonly array $metadata = [],
        public readonly ?float $timestamp = null
    ) {
        $this->timestamp = $this->timestamp ?? microtime(true);
    }
}