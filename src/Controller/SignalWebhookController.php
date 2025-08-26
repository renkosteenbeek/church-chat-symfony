<?php
declare(strict_types=1);

namespace App\Controller;

use App\Message\SignalMessageReceivedMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v1/signal')]
class SignalWebhookController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {}

    #[Route('/webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $this->logger->info('Signal webhook received', [
            'data' => $data
        ]);

        try {
            if (!$this->validateWebhookData($data)) {
                return new JsonResponse([
                    'error' => 'Invalid webhook data'
                ], Response::HTTP_BAD_REQUEST);
            }

            if ($data['event_type'] === 'signal.message.received') {
                $this->handleMessageReceived($data['data']);
            }

            return new JsonResponse([
                'status' => 'ok',
                'processed' => true
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/webhook/test', methods: ['POST'])]
    public function testWebhook(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['sender']) || !isset($data['message'])) {
            return new JsonResponse([
                'error' => 'Missing required fields: sender, message'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $message = new SignalMessageReceivedMessage(
                sender: $data['sender'],
                recipient: $data['recipient'] ?? '+31682016353',
                message: $data['message'],
                timestamp: time() * 1000,
                rawData: $data
            );

            $this->messageBus->dispatch($message);

            $this->logger->info('Test Signal message dispatched', [
                'sender' => $data['sender'],
                'message' => substr($data['message'], 0, 50)
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'message' => 'Test message dispatched successfully',
                'sender' => $data['sender']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to dispatch test message', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Failed to dispatch message',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function validateWebhookData(array $data): bool
    {
        if (!isset($data['event_type'])) {
            return false;
        }

        if ($data['event_type'] === 'signal.message.received') {
            return isset($data['data']['sender']) 
                && isset($data['data']['message'])
                && isset($data['data']['timestamp']);
        }

        return true;
    }

    private function handleMessageReceived(array $data): void
    {
        $message = new SignalMessageReceivedMessage(
            sender: $data['sender'],
            recipient: $data['recipient'] ?? '+31682016353',
            message: $data['message'],
            timestamp: $data['timestamp'],
            rawData: $data['raw_data'] ?? []
        );

        $this->messageBus->dispatch($message);

        $this->logger->info('Signal message dispatched to queue', [
            'sender' => $data['sender'],
            'message_preview' => substr($data['message'], 0, 50)
        ]);
    }
}