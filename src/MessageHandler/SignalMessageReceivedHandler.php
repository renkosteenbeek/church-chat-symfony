<?php
declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ChatHistory;
use App\Message\SignalMessageReceivedMessage;
use App\Repository\MemberRepository;
use App\Service\EventPublisher;
use App\Service\OpenAIService;
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
                
                $this->eventPublisher->publishNotification(
                    $message->sender,
                    'Je bent nog niet geregistreerd. Neem contact op met je kerk voor registratie.'
                );
                return;
            }

            $chatHistory = new ChatHistory();
            $chatHistory->setMember($member);
            $chatHistory->setRole('user');
            $chatHistory->setContent($message->message);
            $chatHistory->setMetadata([
                'source' => 'signal',
                'timestamp' => $message->timestamp,
                'raw_data' => $message->rawData
            ]);
            $this->entityManager->persist($chatHistory);
            $this->entityManager->flush();

            if (!$member->getConversationId()) {
                $this->logger->warning('Member has no active conversation', [
                    'member_id' => $member->getId()
                ]);
                
                $this->eventPublisher->publishNotification(
                    $message->sender,
                    'Je hebt nog geen actieve conversatie. Wacht tot de volgende preek beschikbaar is.'
                );
                return;
            }

            $activeChurchId = null;
            if ($member->getActiveSermon()) {
                $metadata = $member->getActiveSermon();
                $activeChurchId = $metadata['church_id'] ?? null;
            }

            if (!$activeChurchId && count($member->getChurchIds()) > 0) {
                $activeChurchId = $member->getChurchIds()[0];
            }

            $response = $this->openAIService->sendMessage(
                $member->getConversationId(),
                $message->message,
                $activeChurchId ?? 0,
                $member
            );

            $assistantMessage = $response['message'] ?? null;
            
            if ($assistantMessage) {
                $assistantHistory = new ChatHistory();
                $assistantHistory->setMember($member);
                $assistantHistory->setRole('assistant');
                $assistantHistory->setContent($assistantMessage);
                $assistantHistory->setMetadata([
                    'conversation_id' => $member->getConversationId(),
                    'tool_calls' => $response['tool_calls'] ?? []
                ]);
                $this->entityManager->persist($assistantHistory);
                $this->entityManager->flush();

                $this->eventPublisher->publishNotification(
                    $message->sender,
                    $assistantMessage
                );
            }

            if (!empty($response['tool_calls'])) {
                foreach ($response['tool_calls'] as $toolCall) {
                    $this->logger->info('Tool call executed', [
                        'tool' => $toolCall['function'] ?? 'unknown',
                        'member_id' => $member->getId()
                    ]);
                }
            }

            $member->setLastActivity(new \DateTime());
            $this->entityManager->persist($member);
            $this->entityManager->flush();

            $this->logger->info('Signal message processed successfully', [
                'member_id' => $member->getId(),
                'conversation_id' => $member->getConversationId()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process signal message', [
                'sender' => $message->sender,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->eventPublisher->publishNotification(
                $message->sender,
                'Er is een fout opgetreden bij het verwerken van je bericht. Probeer het later opnieuw.'
            );
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