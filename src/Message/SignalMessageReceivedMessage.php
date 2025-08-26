<?php
declare(strict_types=1);

namespace App\Message;

class SignalMessageReceivedMessage
{
    public function __construct(
        public readonly string $sender,
        public readonly string $recipient,
        public readonly string $message,
        public readonly int $timestamp,
        public readonly array $rawData = []
    ) {}
}