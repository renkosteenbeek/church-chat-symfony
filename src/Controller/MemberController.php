<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use App\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/members')]
#[OA\Tag(name: 'Members')]
class MemberController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberRepository $memberRepository,
        private readonly ValidatorInterface $validator
    ) {}

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/members',
        summary: 'Get all members',
        parameters: [
            new OA\Parameter(
                name: 'church_id',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'target_group',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['volwassen', 'verdieping', 'jongeren'])
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of members',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'phone_number', type: 'string'),
                            new OA\Property(property: 'first_name', type: 'string'),
                            new OA\Property(property: 'target_group', type: 'string'),
                            new OA\Property(property: 'age', type: 'integer'),
                            new OA\Property(property: 'church_ids', type: 'array', items: new OA\Items(type: 'integer')),
                            new OA\Property(property: 'intake_completed', type: 'boolean'),
                            new OA\Property(property: 'notifications_new_service', type: 'boolean'),
                            new OA\Property(property: 'notifications_reflection', type: 'boolean'),
                            new OA\Property(property: 'active_since', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'last_activity', type: 'string', format: 'date-time')
                        ]
                    )
                )
            )
        ]
    )]
    public function getMembers(Request $request): JsonResponse
    {
        $churchId = $request->query->get('church_id');
        $targetGroup = $request->query->get('target_group');

        if ($churchId) {
            $targetGroups = $targetGroup ? [$targetGroup] : null;
            $members = $this->memberRepository->findByChurch((int)$churchId, $targetGroups);
        } else {
            $members = $this->memberRepository->findAll();
        }

        $data = array_map(fn(Member $member) => $this->serializeMember($member), $members);

        return $this->json($data);
    }

    #[Route('/{id}', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/members/{id}',
        summary: 'Get member details',
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
                description: 'Member details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'phone_number', type: 'string'),
                        new OA\Property(property: 'first_name', type: 'string'),
                        new OA\Property(property: 'target_group', type: 'string'),
                        new OA\Property(property: 'age', type: 'integer'),
                        new OA\Property(property: 'church_ids', type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'intake_completed', type: 'boolean'),
                        new OA\Property(property: 'notifications_new_service', type: 'boolean'),
                        new OA\Property(property: 'notifications_reflection', type: 'boolean'),
                        new OA\Property(property: 'platform', type: 'string'),
                        new OA\Property(property: 'active_sermon_id', type: 'integer'),
                        new OA\Property(property: 'openai_conversation_id', type: 'string'),
                        new OA\Property(property: 'active_since', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'last_activity', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            )
        ]
    )]
    public function getMember(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid UUID format'], Response::HTTP_BAD_REQUEST);
        }

        $member = $this->memberRepository->find($id);
        
        if (!$member) {
            return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeMember($member));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/members',
        summary: 'Create new member',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone_number'],
                properties: [
                    new OA\Property(property: 'phone_number', type: 'string', example: '+31612345678'),
                    new OA\Property(property: 'first_name', type: 'string'),
                    new OA\Property(property: 'target_group', type: 'string', enum: ['volwassen', 'verdieping', 'jongeren']),
                    new OA\Property(property: 'age', type: 'integer'),
                    new OA\Property(property: 'church_ids', type: 'array', items: new OA\Items(type: 'integer'))
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Member created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'phone_number', type: 'string'),
                        new OA\Property(property: 'first_name', type: 'string'),
                        new OA\Property(property: 'target_group', type: 'string'),
                        new OA\Property(property: 'age', type: 'integer'),
                        new OA\Property(property: 'church_ids', type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'intake_completed', type: 'boolean'),
                        new OA\Property(property: 'notifications_new_service', type: 'boolean'),
                        new OA\Property(property: 'notifications_reflection', type: 'boolean'),
                        new OA\Property(property: 'platform', type: 'string'),
                        new OA\Property(property: 'active_sermon_id', type: 'integer'),
                        new OA\Property(property: 'openai_conversation_id', type: 'string'),
                        new OA\Property(property: 'active_since', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'last_activity', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error'
            ),
            new OA\Response(
                response: 409,
                description: 'Member with this phone number already exists'
            )
        ]
    )]
    public function createMember(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['phone_number'])) {
            return $this->json(['error' => 'phone_number is required'], Response::HTTP_BAD_REQUEST);
        }

        $existingMember = $this->memberRepository->findByPhoneNumber($data['phone_number']);
        if ($existingMember) {
            return $this->json(['error' => 'Member with this phone number already exists'], Response::HTTP_CONFLICT);
        }

        $member = new Member();
        $member->setPhoneNumber($data['phone_number']);

        if (isset($data['first_name'])) {
            $member->setFirstName($data['first_name']);
        }
        
        if (isset($data['target_group'])) {
            $member->setTargetGroup($data['target_group']);
        }
        
        if (isset($data['age'])) {
            $member->setAge($data['age']);
        }
        
        if (isset($data['church_ids']) && is_array($data['church_ids'])) {
            $member->setChurchIds($data['church_ids']);
        }

        $errors = $this->validator->validate($member);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $this->json($this->serializeMember($member), Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/members/{id}',
        summary: 'Update member',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'first_name', type: 'string'),
                    new OA\Property(property: 'target_group', type: 'string', enum: ['volwassen', 'verdieping', 'jongeren']),
                    new OA\Property(property: 'age', type: 'integer'),
                    new OA\Property(property: 'intake_completed', type: 'boolean'),
                    new OA\Property(property: 'notifications_new_service', type: 'boolean'),
                    new OA\Property(property: 'notifications_reflection', type: 'boolean')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Member updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'phone_number', type: 'string'),
                        new OA\Property(property: 'first_name', type: 'string'),
                        new OA\Property(property: 'target_group', type: 'string'),
                        new OA\Property(property: 'age', type: 'integer'),
                        new OA\Property(property: 'church_ids', type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'intake_completed', type: 'boolean'),
                        new OA\Property(property: 'notifications_new_service', type: 'boolean'),
                        new OA\Property(property: 'notifications_reflection', type: 'boolean'),
                        new OA\Property(property: 'platform', type: 'string'),
                        new OA\Property(property: 'active_sermon_id', type: 'integer'),
                        new OA\Property(property: 'openai_conversation_id', type: 'string'),
                        new OA\Property(property: 'active_since', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'last_activity', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error'
            )
        ]
    )]
    public function updateMember(string $id, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid UUID format'], Response::HTTP_BAD_REQUEST);
        }

        $member = $this->memberRepository->find($id);
        
        if (!$member) {
            return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['first_name'])) {
            $member->setFirstName($data['first_name']);
        }
        
        if (isset($data['target_group'])) {
            $member->setTargetGroup($data['target_group']);
        }
        
        if (isset($data['age'])) {
            $member->setAge($data['age']);
        }
        
        if (isset($data['intake_completed'])) {
            $member->setIntakeCompleted($data['intake_completed']);
        }
        
        if (isset($data['notifications_new_service'])) {
            $member->setNotificationsNewService($data['notifications_new_service']);
        }
        
        if (isset($data['notifications_reflection'])) {
            $member->setNotificationsReflection($data['notifications_reflection']);
        }

        $member->setUpdatedAt(new \DateTime());
        $member->updateLastActivity();

        $errors = $this->validator->validate($member);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return $this->json($this->serializeMember($member));
    }

    #[Route('/{id}/churches', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/members/{id}/churches',
        summary: 'Update member church associations',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['church_ids'],
                properties: [
                    new OA\Property(
                        property: 'church_ids', 
                        type: 'array', 
                        items: new OA\Items(type: 'integer'),
                        example: [1, 2, 3]
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Churches updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'phone_number', type: 'string'),
                        new OA\Property(property: 'first_name', type: 'string'),
                        new OA\Property(property: 'target_group', type: 'string'),
                        new OA\Property(property: 'age', type: 'integer'),
                        new OA\Property(property: 'church_ids', type: 'array', items: new OA\Items(type: 'integer')),
                        new OA\Property(property: 'intake_completed', type: 'boolean'),
                        new OA\Property(property: 'notifications_new_service', type: 'boolean'),
                        new OA\Property(property: 'notifications_reflection', type: 'boolean'),
                        new OA\Property(property: 'platform', type: 'string'),
                        new OA\Property(property: 'active_sermon_id', type: 'integer'),
                        new OA\Property(property: 'openai_conversation_id', type: 'string'),
                        new OA\Property(property: 'active_since', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'last_activity', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time')
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
    public function updateMemberChurches(string $id, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid UUID format'], Response::HTTP_BAD_REQUEST);
        }

        $member = $this->memberRepository->find($id);
        
        if (!$member) {
            return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['church_ids']) || !is_array($data['church_ids'])) {
            return $this->json(['error' => 'church_ids array is required'], Response::HTTP_BAD_REQUEST);
        }

        $member->setChurchIds($data['church_ids']);
        $member->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        return $this->json($this->serializeMember($member));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/members/{id}',
        summary: 'Delete member',
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
                response: 204,
                description: 'Member deleted successfully'
            ),
            new OA\Response(
                response: 404,
                description: 'Member not found'
            )
        ]
    )]
    public function deleteMember(string $id): Response
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'Invalid UUID format'], Response::HTTP_BAD_REQUEST);
        }

        $member = $this->memberRepository->find($id);
        
        if (!$member) {
            return $this->json(['error' => 'Member not found'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($member);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function serializeMember(Member $member): array
    {
        return [
            'id' => $member->getId(),
            'phone_number' => $member->getPhoneNumber(),
            'first_name' => $member->getFirstName(),
            'target_group' => $member->getTargetGroup(),
            'age' => $member->getAge(),
            'church_ids' => $member->getChurchIds(),
            'intake_completed' => $member->isIntakeCompleted(),
            'notifications_new_service' => $member->isNotificationsNewService(),
            'notifications_reflection' => $member->isNotificationsReflection(),
            'platform' => $member->getPlatform(),
            'active_sermon_id' => $member->getActiveSermonId(),
            'openai_conversation_id' => $member->getOpenaiConversationId(),
            'active_since' => $member->getActiveSince()->format('c'),
            'last_activity' => $member->getLastActivity()->format('c'),
            'created_at' => $member->getCreatedAt()->format('c'),
            'updated_at' => $member->getUpdatedAt()->format('c')
        ];
    }
}