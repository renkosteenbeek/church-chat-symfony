<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use App\Repository\MemberRepository;
use App\Service\OpenAIService;
use App\Service\ContentApiClient;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/conversation')]
#[OA\Tag(name: 'Conversation')]
class ConversationController extends AbstractController
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly OpenAIService $openAIService,
        private readonly ContentApiClient $contentApiClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    #[Route('/initialize', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/conversation/initialize',
        summary: 'Initialize OpenAI conversation for a member',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['member_id'],
                properties: [
                    new OA\Property(property: 'member_id', type: 'string', format: 'uuid', example: '6cb1706f-b3d3-4296-81fd-edc95d4d4eb8'),
                    new OA\Property(property: 'sermon_id', type: 'string', example: '23'),
                    new OA\Property(property: 'church_id', type: 'integer', example: 3),
                    new OA\Property(property: 'welcome_message', type: 'string', example: 'Welkom! Ik help je graag met vragen over de preek.'),
                    new OA\Property(property: 'force_new', type: 'boolean', example: false, description: 'Force create new conversation even if one exists')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation initialized successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'conversation_id', type: 'string'),
                        new OA\Property(property: 'member_id', type: 'string'),
                        new OA\Property(property: 'is_new', type: 'boolean'),
                        new OA\Property(property: 'welcome_message', type: 'string'),
                        new OA\Property(property: 'sermon_info', type: 'object')
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid request'
            )
        ]
    )]
    public function initializeConversation(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['member_id'])) {
            return $this->json([
                'error' => 'member_id is required'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        $member = $this->memberRepository->find($data['member_id']);
        
        if (!$member) {
            return $this->json([
                'error' => 'Member not found'
            ], Response::HTTP_NOT_FOUND);
        }
        
        $forceNew = $data['force_new'] ?? false;
        $existingConversationId = $member->getOpenaiConversationId();
        
        if ($existingConversationId && !$forceNew) {
            $this->logger->info('Member already has conversation', [
                'member_id' => $member->getId(),
                'conversation_id' => $existingConversationId
            ]);
            
            return $this->json([
                'conversation_id' => $existingConversationId,
                'member_id' => $member->getId(),
                'is_new' => false,
                'message' => 'Existing conversation returned'
            ]);
        }
        
        try {
            $churchId = $data['church_id'] ?? null;
            if (!$churchId && !empty($member->getChurchIds())) {
                $churchId = $member->getChurchIds()[0];
            }
            
            $sermonId = $data['sermon_id'] ?? null;
            $sermonInfo = null;
            
            if ($sermonId) {
                $sermonContent = $this->contentApiClient->getSermonContent($sermonId);
                if ($sermonContent) {
                    $sermonInfo = [
                        'id' => $sermonId,
                        'title' => $sermonContent['title'] ?? 'Onbekende preek',
                        'speaker' => $sermonContent['speaker'] ?? 'Onbekende spreker',
                        'date' => $sermonContent['service_date'] ?? null
                    ];
                    
                    $member->setActiveSermonId($sermonId);
                }
            }
            
            $welcomeMessage = $data['welcome_message'] ?? $this->generateWelcomeMessage($member, $sermonInfo);
            
            $conversationData = $this->openAIService->createConversation(
                $member,
                $welcomeMessage,
                $churchId ?? 1
            );
            
            if (!isset($conversationData['id'])) {
                throw new \RuntimeException('Failed to create OpenAI conversation');
            }
            
            $member->setOpenaiConversationId($conversationData['id']);
            $this->entityManager->persist($member);
            $this->entityManager->flush();
            
            $this->logger->info('Conversation initialized successfully', [
                'member_id' => $member->getId(),
                'conversation_id' => $conversationData['id'],
                'church_id' => $churchId,
                'sermon_id' => $sermonId
            ]);
            
            return $this->json([
                'conversation_id' => $conversationData['id'],
                'member_id' => $member->getId(),
                'is_new' => true,
                'welcome_message' => $welcomeMessage,
                'sermon_info' => $sermonInfo
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize conversation', [
                'member_id' => $member->getId(),
                'error' => $e->getMessage()
            ]);
            
            return $this->json([
                'error' => 'Failed to initialize conversation',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/reset/{memberId}', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/conversation/reset/{memberId}',
        summary: 'Reset conversation for a member',
        parameters: [
            new OA\Parameter(
                name: 'memberId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation reset successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'member_id', type: 'string'),
                        new OA\Property(property: 'previous_conversation_id', type: 'string')
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            )
        ]
    )]
    public function resetConversation(string $memberId): JsonResponse
    {
        $member = $this->memberRepository->find($memberId);
        
        if (!$member) {
            return $this->json([
                'error' => 'Member not found'
            ], Response::HTTP_NOT_FOUND);
        }
        
        $previousConversationId = $member->getOpenaiConversationId();
        
        $member->setOpenaiConversationId(null);
        $member->setActiveSermonId(null);
        
        $this->entityManager->persist($member);
        $this->entityManager->flush();
        
        $this->logger->info('Conversation reset', [
            'member_id' => $memberId,
            'previous_conversation_id' => $previousConversationId
        ]);
        
        return $this->json([
            'message' => 'Conversation reset successfully',
            'member_id' => $memberId,
            'previous_conversation_id' => $previousConversationId
        ]);
    }
    
    #[Route('/status/{memberId}', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/conversation/status/{memberId}',
        summary: 'Get conversation status for a member',
        parameters: [
            new OA\Parameter(
                name: 'memberId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversation status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'member_id', type: 'string'),
                        new OA\Property(property: 'has_conversation', type: 'boolean'),
                        new OA\Property(property: 'conversation_id', type: 'string', nullable: true),
                        new OA\Property(property: 'active_sermon_id', type: 'string', nullable: true),
                        new OA\Property(property: 'church_ids', type: 'array', items: new OA\Items(type: 'integer'))
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            )
        ]
    )]
    public function getConversationStatus(string $memberId): JsonResponse
    {
        $member = $this->memberRepository->find($memberId);
        
        if (!$member) {
            return $this->json([
                'error' => 'Member not found'
            ], Response::HTTP_NOT_FOUND);
        }
        
        $conversationId = $member->getOpenaiConversationId();
        
        return $this->json([
            'member_id' => $memberId,
            'has_conversation' => !empty($conversationId),
            'conversation_id' => $conversationId,
            'active_sermon_id' => $member->getActiveSermonId(),
            'church_ids' => $member->getChurchIds()
        ]);
    }
    
    private function generateWelcomeMessage(Member $member, ?array $sermonInfo): string
    {
        $name = $member->getFirstName() ?? 'daar';
        
        if ($sermonInfo) {
            return sprintf(
                "Welkom %s! 🙏\n\nIk zie dat je interesse hebt in de preek '%s' van %s. " .
                "Ik help je graag met:\n" .
                "• Een samenvatting van de hoofdpunten\n" .
                "• Reflectievragen om over na te denken\n" .
                "• Uitleg bij moeilijke passages\n" .
                "• Praktische toepassingen voor je dagelijks leven\n\n" .
                "Waar wil je mee beginnen?",
                $name,
                $sermonInfo['title'],
                $sermonInfo['speaker']
            );
        }
        
        return sprintf(
            "Welkom %s! 🙏\n\n" .
            "Ik ben hier om je te helpen met vragen over:\n" .
            "• De preek van afgelopen zondag\n" .
            "• Bijbelteksten en hun betekenis\n" .
            "• Geloofsvragen\n" .
            "• Praktische toepassingen van Gods Woord\n\n" .
            "Wat kan ik voor je doen?",
            $name
        );
    }
}