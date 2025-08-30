<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ChatHistory;
use App\Message\SignalMessageReceivedMessage;
use App\Repository\MemberRepository;
use App\Service\EventPublisher;
use App\Service\OpenAIService;
use App\Service\ToolExecutor;
use App\Service\SignalServiceClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SignalMessageReceivedHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MemberRepository $memberRepository,
        private readonly OpenAIService $openAIService,
        private readonly ToolExecutor $toolExecutor,
        private readonly SignalServiceClient $signalServiceClient,
        private readonly EventPublisher $eventPublisher,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(SignalMessageReceivedMessage $message): void
    {
        $this->logger->info('Processing signal.message.received event', [
            'sender' => $message->sender,
            'recipient' => $message->recipient
        ]);

        try {
            $phoneNumber = $this->normalizePhoneNumber($message->sender);
            $member = $this->memberRepository->findOneBy(['phoneNumber' => $phoneNumber]);

            if (!$member) {
                $this->logger->warning('Member not found for phone number', [
                    'phone_number' => $phoneNumber
                ]);
                
                $this->signalServiceClient->sendMessage(
                    $message->sender,
                    'Je bent nog niet geregistreerd. Neem contact op met je kerk voor registratie.',
                    ['type' => 'not_registered']
                );
                return;
            }

            $conversationId = $member->getOpenaiConversationId();
            
            if (!$conversationId) {
                $this->logger->warning('Member has no active conversation', [
                    'member_id' => $member->getId()
                ]);
                
                $this->signalServiceClient->sendMessage(
                    $message->sender,
                    'Je hebt nog geen actieve conversatie. Wacht tot de volgende preek beschikbaar is.',
                    ['type' => 'no_conversation']
                );
                return;
            }

            $activeChurchId = null;
            $churchIds = $member->getChurchIds();
            if (!empty($churchIds)) {
                $activeChurchId = $churchIds[0];
            }

            $response = $this->openAIService->sendMessage(
                $conversationId,
                $message->message,
                $activeChurchId ?? 0,
                $member
            );

            $this->processOpenAIResponse($response, $member, $conversationId, $activeChurchId ?? 0);

            $member->updateLastActivity();
            $this->entityManager->persist($member);
            $this->entityManager->flush();

            $this->logger->info('Signal message processed successfully', [
                'member_id' => $member->getId(),
                'conversation_id' => $conversationId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process signal message', [
                'sender' => $message->sender,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->signalServiceClient->sendMessage(
                $message->sender,
                'Er is een fout opgetreden bij het verwerken van je bericht. Probeer het later opnieuw.',
                ['type' => 'error']
            );
        }
    }

    private function processOpenAIResponse(
        array $response, 
        \App\Entity\Member $member, 
        string $conversationId,
        int $churchId
    ): void {
        $toolCalls = $this->openAIService->extractToolCalls($response);
        $responseText = $this->openAIService->extractResponseText($response);
        
        if (!empty($toolCalls)) {
            $this->logger->info('Processing tool calls', [
                'count' => count($toolCalls),
                'member_id' => $member->getId()
            ]);
            
            $this->processToolCalls($toolCalls, $member, $conversationId, $churchId);
        } elseif ($responseText) {
            $this->signalServiceClient->sendMessage(
                $member->getPhoneNumber(),
                $responseText,
                [
                    'type' => 'assistant_message',
                    'conversation_id' => $conversationId
                ]
            );
        }
    }

    private function processToolCalls(
        array $toolCalls, 
        \App\Entity\Member $member, 
        string $conversationId,
        int $churchId
    ): void {
        foreach ($toolCalls as $toolCall) {
            if (!isset($toolCall['name']) || !isset($toolCall['call_id'])) {
                continue;
            }
            
            $arguments = $toolCall['arguments'] ?? '{}';
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }
            
            $this->logger->info('Executing tool call', [
                'tool' => $toolCall['name'],
                'call_id' => $toolCall['call_id'],
                'arguments' => $arguments
            ]);
            
            $toolResult = $this->toolExecutor->executeTool(
                $toolCall['name'],
                $arguments,
                $member
            );
            
            $toolResponse = $this->openAIService->sendToolOutput(
                $conversationId,
                $toolCall['call_id'],
                $toolResult,
                $member,
                $churchId
            );
            
            $finalResponseText = $this->openAIService->extractResponseText($toolResponse);
            
            if ($finalResponseText) {
                $this->signalServiceClient->sendMessage(
                    $member->getPhoneNumber(),
                    $finalResponseText,
                    [
                        'type' => 'tool_response',
                        'tool' => $toolCall['name'],
                        'conversation_id' => $conversationId
                    ]
                );
            }
            
            $additionalToolCalls = $this->openAIService->extractToolCalls($toolResponse);
            if (!empty($additionalToolCalls)) {
                $this->processToolCalls($additionalToolCalls, $member, $conversationId, $churchId);
            }
        }
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        if (!str_starts_with($phoneNumber, '+')) {
            if (str_starts_with($phoneNumber, '0')) {
                $phoneNumber = '+31' . substr($phoneNumber, 1);
            } else {
                $phoneNumber = '+' . $phoneNumber;
            }
        }
        
        return $phoneNumber;
    }
}