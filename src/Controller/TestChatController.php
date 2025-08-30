<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Repository\ChatHistoryRepository;
use App\Service\OpenAIService;
use App\Service\MemberService;
use App\Service\ToolExecutor;
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
        private readonly ToolExecutor $toolExecutor,
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
            
            $responseContent = $this->openAIService->extractResponseText($response);
            $toolCalls = $this->openAIService->extractToolCalls($response);
            
            if (!empty($toolCalls)) {
                foreach ($toolCalls as $toolCall) {
                    $arguments = is_string($toolCall['arguments']) 
                        ? json_decode($toolCall['arguments'], true) 
                        : $toolCall['arguments'];
                    
                    $toolResult = $this->toolExecutor->executeTool(
                        $toolCall['name'],
                        $arguments ?? [],
                        $member
                    );
                    
                    $followUpResponse = $this->openAIService->sendToolOutput(
                        $conversationId,
                        $toolCall['call_id'],
                        $toolResult,
                        $member,
                        $churchId
                    );
                    
                    $responseContent = $this->openAIService->extractResponseText($followUpResponse);
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

    #[Route('/tools/list', methods: ['GET'])]
    public function listAvailableTools(): JsonResponse
    {
        $tools = [
            'manage_user' => [
                'description' => 'Update user information (name, age, target group, church)',
                'parameters' => ['name', 'age', 'target_group', 'church'],
                'examples' => [
                    ['name' => 'Jan', 'age' => 35],
                    ['target_group' => 'jongeren'],
                    ['church' => 'Nieuwe Kerk Amsterdam']
                ]
            ],
            'handle_sermon' => [
                'description' => 'Handle sermon-related actions',
                'parameters' => ['action', 'attended', 'wants_summary', 'alternative_church', 'online_attended'],
                'examples' => [
                    ['action' => 'get_summary'],
                    ['action' => 'register_attendance', 'attended' => true],
                    ['action' => 'register_absence', 'alternative_church' => 'Andere Kerk']
                ]
            ],
            'manage_subscription' => [
                'description' => 'Manage notification subscriptions',
                'parameters' => ['action', 'notification_type', 'frequency', 'pause_until', 'reason'],
                'examples' => [
                    ['action' => 'pause', 'pause_until' => '2025-09-01'],
                    ['action' => 'change_frequency', 'frequency' => 'weekly'],
                    ['action' => 'unsubscribe', 'reason' => 'Te veel berichten']
                ]
            ],
            'answer_question' => [
                'description' => 'Answer theological questions',
                'parameters' => ['question', 'category', 'needs_search'],
                'examples' => [
                    ['question' => 'Wat betekent genade?', 'category' => 'theology'],
                    ['question' => 'Waar staat het over liefde?', 'category' => 'bible', 'needs_search' => true]
                ]
            ],
            'process_feedback' => [
                'description' => 'Process user feedback',
                'parameters' => ['type', 'message', 'severity'],
                'examples' => [
                    ['type' => 'feedback', 'message' => 'Zeer goede preek!', 'severity' => 'low'],
                    ['type' => 'complaint', 'message' => 'Te veel berichten', 'severity' => 'medium'],
                    ['type' => 'technical', 'message' => 'App crasht steeds', 'severity' => 'high']
                ]
            ]
        ];
        
        return $this->json(['tools' => $tools]);
    }

    #[Route('/tools/test-all', methods: ['POST'])]
    public function testAllTools(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $phoneNumber = $data['phone_number'] ?? null;
            $churchId = $data['church_id'] ?? 1;
            
            if (!$phoneNumber) {
                return $this->json(['error' => 'Phone number is required'], 400);
            }
            
            $member = $this->memberRepository->findOneBy(['phoneNumber' => $phoneNumber]);
            
            if (!$member) {
                return $this->json(['error' => 'Member not found'], 404);
            }

            $member->setActiveSermonId('test-sermon-123');
            $this->memberRepository->save($member, true);
            
            $testCases = [
                [
                    'tool' => 'manage_user',
                    'arguments' => ['name' => 'Test User', 'age' => 30, 'target_group' => 'volwassenen']
                ],
                [
                    'tool' => 'handle_sermon',
                    'arguments' => ['action' => 'register_attendance', 'attended' => true]
                ],
                [
                    'tool' => 'handle_sermon', 
                    'arguments' => ['action' => 'get_summary']
                ],
                [
                    'tool' => 'manage_subscription',
                    'arguments' => ['action' => 'change_frequency', 'frequency' => 'weekly']
                ],
                [
                    'tool' => 'answer_question',
                    'arguments' => ['question' => 'Test vraag over geloof', 'category' => 'faith']
                ],
                [
                    'tool' => 'process_feedback',
                    'arguments' => ['type' => 'feedback', 'message' => 'Test feedback bericht', 'severity' => 'low']
                ]
            ];
            
            $results = [];
            
            foreach ($testCases as $testCase) {
                $startTime = microtime(true);
                
                $result = $this->toolExecutor->executeTool(
                    $testCase['tool'],
                    $testCase['arguments'],
                    $member
                );
                
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                
                $results[] = [
                    'tool' => $testCase['tool'],
                    'arguments' => $testCase['arguments'],
                    'result' => $result,
                    'execution_time_ms' => $executionTime,
                    'success' => $result['success'] ?? false
                ];
            }
            
            $successfulTests = array_filter($results, fn($r) => $r['success']);
            $failedTests = array_filter($results, fn($r) => !$r['success']);
            
            return $this->json([
                'success' => true,
                'total_tests' => count($testCases),
                'successful' => count($successfulTests),
                'failed' => count($failedTests),
                'results' => $results,
                'summary' => [
                    'success_rate' => round((count($successfulTests) / count($testCases)) * 100, 2) . '%',
                    'total_execution_time_ms' => array_sum(array_column($results, 'execution_time_ms'))
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/tools/validate', methods: ['POST'])]
    public function validateToolCall(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $toolName = $data['tool'] ?? null;
            $arguments = $data['arguments'] ?? [];
            
            if (!$toolName) {
                return $this->json(['error' => 'Tool name is required'], 400);
            }
            
            $availableTools = ['manage_user', 'handle_sermon', 'manage_subscription', 'answer_question', 'process_feedback'];
            
            if (!in_array($toolName, $availableTools)) {
                return $this->json([
                    'valid' => false,
                    'error' => "Unknown tool: {$toolName}",
                    'available_tools' => $availableTools
                ]);
            }
            
            $requiredArguments = [
                'manage_user' => [],
                'handle_sermon' => ['action'],
                'manage_subscription' => ['action'],
                'answer_question' => ['question', 'category'],
                'process_feedback' => ['type', 'message']
            ];
            
            $required = $requiredArguments[$toolName];
            $missing = [];
            
            foreach ($required as $field) {
                if (!isset($arguments[$field]) || empty($arguments[$field])) {
                    $missing[] = $field;
                }
            }
            
            if (!empty($missing)) {
                return $this->json([
                    'valid' => false,
                    'error' => "Missing required arguments: " . implode(', ', $missing),
                    'required' => $required,
                    'provided' => array_keys($arguments)
                ]);
            }
            
            return $this->json([
                'valid' => true,
                'tool' => $toolName,
                'arguments' => $arguments,
                'message' => 'Tool call is valid'
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'valid' => false,
                'error' => 'Validation failed: ' . $e->getMessage()
            ], 500);
        }
    }

}