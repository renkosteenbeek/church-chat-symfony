<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessChatMessage;
use App\Service\EventPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessChatMessageHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EventPublisher $eventPublisher
    ) {}

    public function __invoke(ProcessChatMessage $message): void
    {
        $this->logger->info('Processing chat message', [
            'message_id' => $message->getMessageId(),
            'conversation_id' => $message->getConversationId(),
            'user_id' => $message->getUserId()
        ]);

        try {
            $this->logger->info('Chat message would be processed here', [
                'content_preview' => substr($message->getContent(), 0, 50)
            ]);
            
            $this->eventPublisher->publish('chat.message.processed', [
                'message_id' => $message->getMessageId(),
                'conversation_id' => $message->getConversationId(),
                'processed_at' => (new \DateTime())->format('c')
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process chat message', [
                'message_id' => $message->getMessageId(),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}