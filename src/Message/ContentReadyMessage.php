<?php
declare(strict_types=1);

namespace App\Message;

class ContentReadyMessage
{
    public function __construct(
        public readonly int $sermonId,
        public readonly string $uuid,
        public readonly int $churchId,
        public readonly string $title,
        public readonly ?string $speaker = null,
        public readonly ?string $serviceDate = null,
        public readonly array $contentTypes = [],
        public readonly ?string $openaiFileId = null,
        public readonly array $metadata = []
    ) {}
}