<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Message\ProcessChatMessage;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/chat')]
#[OA\Tag(name: 'Chat')]
class ChatController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus
    ) {}

    #[Route('/conversations', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chat/conversations',
        summary: 'Get all conversations',
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of conversations',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'conversations',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'user_id', type: 'string'),
                                    new OA\Property(property: 'church_id', type: 'integer'),
                                    new OA\Property(property: 'message_count', type: 'integer'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
                                ]
                            )
                        )
                    ]
                )
            )
        ]
    )]
    public function getConversations(): JsonResponse
    {
        $conversations = [
            [
                'id' => Uuid::v4()->toString(),
                'user_id' => '+31612345678',
                'church_id' => 1,
                'message_count' => 5,
                'created_at' => (new \DateTime('-1 day'))->format('c'),
                'updated_at' => (new \DateTime('-1 hour'))->format('c')
            ],
            [
                'id' => Uuid::v4()->toString(),
                'user_id' => '+31687654321',
                'church_id' => 2,
                'message_count' => 3,
                'created_at' => (new \DateTime('-2 days'))->format('c'),
                'updated_at' => (new \DateTime('-2 hours'))->format('c')
            ]
        ];

        return $this->json(['conversations' => $conversations]);
    }

    #[Route('/conversation/{id}', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chat/conversation/{id}',
        summary: 'Get conversation details',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'user_id', type: 'string'),
                        new OA\Property(property: 'church_id', type: 'integer'),
                        new OA\Property(
                            property: 'messages',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant']),
                                    new OA\Property(property: 'content', type: 'string'),
                                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time')
                                ]
                            )
                        ),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation not found'
            )
        ]
    )]
    public function getConversation(string $id): JsonResponse
    {
        $conversation = [
            'id' => $id,
            'user_id' => '+31612345678',
            'church_id' => 1,
            'messages' => [
                [
                    'id' => Uuid::v4()->toString(),
                    'role' => 'user',
                    'content' => 'Hallo, ik heb een vraag over de preek van afgelopen zondag',
                    'timestamp' => (new \DateTime('-1 hour'))->format('c')
                ],
                [
                    'id' => Uuid::v4()->toString(),
                    'role' => 'assistant',
                    'content' => 'Natuurlijk! Ik help je graag met vragen over de preek. Waar gaat je vraag over?',
                    'timestamp' => (new \DateTime('-59 minutes'))->format('c')
                ]
            ],
            'created_at' => (new \DateTime('-1 day'))->format('c'),
            'updated_at' => (new \DateTime('-1 hour'))->format('c')
        ];

        return $this->json($conversation);
    }

    #[Route('/messages', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/chat/messages',
        summary: 'Send a new chat message',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['conversation_id', 'content'],
                properties: [
                    new OA\Property(property: 'conversation_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'content', type: 'string'),
                    new OA\Property(property: 'user_id', type: 'string')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 202,
                description: 'Message accepted for processing',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'status', type: 'string', example: 'processing')
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid request'
            )
        ]
    )]
    public function sendMessage(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['conversation_id']) || !isset($data['content'])) {
            return $this->json([
                'error' => 'Missing required fields: conversation_id and content'
            ], Response::HTTP_BAD_REQUEST);
        }

        $messageId = Uuid::v4()->toString();
        
        $chatMessage = new ProcessChatMessage(
            $messageId,
            $data['conversation_id'],
            $data['content'],
            $data['user_id'] ?? 'anonymous'
        );
        
        $this->messageBus->dispatch($chatMessage);

        return $this->json([
            'message_id' => $messageId,
            'status' => 'processing'
        ], Response::HTTP_ACCEPTED);
    }
}