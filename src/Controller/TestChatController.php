<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Repository\ChatHistoryRepository;
use App\Service\OpenAIService;
use App\Service\MemberService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/chat/test')]
#[OA\Tag(name: 'Test Chat')]
class TestChatController extends AbstractController
{
    public function __construct(
        private readonly OpenAIService $openAIService,
        private readonly MemberService $memberService,
        private readonly MemberRepository $memberRepository,
        private readonly ChatHistoryRepository $chatHistoryRepository
    ) {}

    #[Route('/send', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/chat/test/send',
        summary: 'Simulate sending a user message',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone_number', 'message'],
                properties: [
                    new OA\Property(property: 'phone_number', type: 'string', example: '+31612345678'),
                    new OA\Property(property: 'message', type: 'string', example: 'Waar ging de preek over?'),
                    new OA\Property(property: 'church_id', type: 'integer', example: 1)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Message processed successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'conversation_id', type: 'string'),
                        new OA\Property(property: 'response', type: 'string'),
                        new OA\Property(property: 'tool_calls', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'member_id', type: 'string'),
                        new OA\Property(property: 'usage', type: 'object')
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid request'
            )
        ]
    )]
    public function testSend(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['phone_number']) || !isset($data['message'])) {
            return $this->json([
                'error' => 'phone_number and message are required'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        $phoneNumber = $data['phone_number'];
        $message = $data['message'];
        $churchId = $data['church_id'] ?? 1;
        
        try {
            $member = $this->memberService->findOrCreateByPhone($phoneNumber);
            
            if (!$member->isMemberOfChurch($churchId)) {
                $member->addChurchId($churchId);
                $this->memberRepository->save($member, true);
            }
            
            $conversationId = $member->getOpenaiConversationId();
            
            if (!$conversationId) {
                $welcomeMessage = "Welkom bij de kerk chat! Ik help je graag met vragen over de preek of andere kerkzaken.";
                $conversationData = $this->openAIService->createConversation($member, $welcomeMessage, $churchId);
                $conversationId = $conversationData['id'] ?? null;
                
                if (!$conversationId) {
                    throw new \RuntimeException('Failed to create conversation');
                }
            }
            
            $response = $this->openAIService->sendMessage($conversationId, $message, $churchId, $member);
            
            $responseContent = $this->extractResponseContent($response);
            $toolCalls = $this->extractToolCalls($response);
            
            if (!empty($toolCalls)) {
                foreach ($toolCalls as $toolCall) {
                    if ($toolCall['name'] === 'update_user') {
                        $toolResult = $this->handleUpdateUserTool($member, $toolCall);
                        
                        $followUpResponse = $this->openAIService->handleToolCall(
                            $conversationId,
                            $toolCall['call_id'],
                            $toolResult,
                            $member
                        );
                        
                        $responseContent = $this->extractResponseContent($followUpResponse);
                    }
                }
            }
            
            return $this->json([
                'conversation_id' => $conversationId,
                'response' => $responseContent,
                'tool_calls' => $toolCalls,
                'member_id' => $member->getId(),
                'usage' => $response['usage'] ?? null
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to process message',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/conversation/{phoneNumber}', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/chat/test/conversation/{phoneNumber}',
        summary: 'Get conversation history for a phone number',
        parameters: [
            new OA\Parameter(
                name: 'phoneNumber',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'URL-encoded phone number (e.g., %2B31612345678 for +31612345678)'
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation history',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'member', type: 'object'),
                        new OA\Property(property: 'conversation_id', type: 'string'),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'string'),
                                new OA\Property(property: 'role', type: 'string'),
                                new OA\Property(property: 'content', type: 'string'),
                                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'tool_calls', type: 'array', items: new OA\Items(type: 'object'))
                            ]
                        ))
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            )
        ]
    )]
    public function getConversationHistory(string $phoneNumber, Request $request): JsonResponse
    {
        $phoneNumber = urldecode($phoneNumber);
        $limit = (int) $request->query->get('limit', 20);
        
        $member = $this->memberRepository->findByPhoneNumber($phoneNumber);
        
        if (!$member) {
            return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);
        }
        
        $history = $this->chatHistoryRepository->findByMember($member, $limit);
        
        $messages = array_map(function($chat) {
            return [
                'id' => $chat->getId(),
                'role' => $chat->getRole(),
                'content' => $chat->getContent(),
                'created_at' => $chat->getCreatedAt()->format('c'),
                'tool_calls' => $chat->getToolCalls()
            ];
        }, array_reverse($history));
        
        return $this->json([
            'member' => [
                'id' => $member->getId(),
                'phone_number' => $member->getPhoneNumber(),
                'first_name' => $member->getFirstName(),
                'age' => $member->getAge(),
                'target_group' => $member->getTargetGroup()
            ],
            'conversation_id' => $member->getOpenaiConversationId(),
            'messages' => $messages
        ]);
    }

    #[Route('/reset/{phoneNumber}', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/chat/test/reset/{phoneNumber}',
        summary: 'Reset conversation for a member',
        parameters: [
            new OA\Parameter(
                name: 'phoneNumber',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'URL-encoded phone number'
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation reset successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'member_id', type: 'string')
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            )
        ]
    )]
    public function resetConversation(string $phoneNumber): JsonResponse
    {
        $phoneNumber = urldecode($phoneNumber);
        
        $member = $this->memberRepository->findByPhoneNumber($phoneNumber);
        
        if (!$member) {
            return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);
        }
        
        $member->setOpenaiConversationId(null);
        $member->setActiveSermonId(null);
        $this->memberRepository->save($member, true);
        
        return $this->json([
            'message' => 'Conversation reset successfully',
            'member_id' => $member->getId()
        ]);
    }

    private function handleUpdateUserTool(Member $member, array $toolCall): array
    {
        try {
            $arguments = json_decode($toolCall['arguments'], true);
            
            if (isset($arguments['name'])) {
                $member->setFirstName($arguments['name']);
            }
            
            if (isset($arguments['age'])) {
                $member->setAge($arguments['age']);
            }
            
            $this->memberRepository->save($member, true);
            
            return [
                'success' => true,
                'message' => 'Gebruiker opgeslagen'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Fout bij opslaan: ' . $e->getMessage()
            ];
        }
    }

    private function extractResponseContent(array $response): ?string
    {
        if (!isset($response['output']) || !is_array($response['output'])) {
            return null;
        }
        
        foreach ($response['output'] as $output) {
            if ($output['type'] === 'message' && isset($output['content'])) {
                foreach ($output['content'] as $content) {
                    if ($content['type'] === 'output_text' && isset($content['text'])) {
                        return $content['text'];
                    }
                }
            }
        }
        
        return null;
    }

    private function extractToolCalls(array $response): array
    {
        if (!isset($response['output']) || !is_array($response['output'])) {
            return [];
        }
        
        $toolCalls = [];
        
        foreach ($response['output'] as $output) {
            if ($output['type'] === 'function_call') {
                $toolCalls[] = [
                    'id' => $output['id'] ?? null,
                    'call_id' => $output['call_id'] ?? null,
                    'name' => $output['name'] ?? null,
                    'arguments' => $output['arguments'] ?? null
                ];
            }
        }
        
        return $toolCalls;
    }
}