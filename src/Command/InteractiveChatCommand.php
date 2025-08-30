<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Member;
use App\Message\SignalMessageReceivedMessage;
use App\Repository\MemberRepository;
use App\Repository\ChatHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:chat',
    description: 'Interactive chat simulator for testing Signal message flow',
)]
class InteractiveChatCommand extends Command
{
    private ?Member $currentMember = null;
    private SymfonyStyle $io;
    
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly ChatHistoryRepository $chatHistoryRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('member-id', 'm', InputOption::VALUE_REQUIRED, 'Member ID to chat as')
            ->addOption('phone', 'p', InputOption::VALUE_REQUIRED, 'Phone number to chat as')
            ->addOption('reset', 'r', InputOption::VALUE_NONE, 'Reset conversation before starting')
            ->addOption('test-tools', 't', InputOption::VALUE_NONE, 'Test all available tools')
            ->addOption('history', null, InputOption::VALUE_NONE, 'Show chat history before starting')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command simulates Signal chat messages for testing:

Start chat with member ID:
  <info>php %command.full_name% --member-id=123</info>

Start chat with phone number:
  <info>php %command.full_name% --phone=+31612345678</info>

Reset conversation and start fresh:
  <info>php %command.full_name% --member-id=123 --reset</info>

Test all available tools:
  <info>php %command.full_name% --member-id=123 --test-tools</info>

Show history before starting:
  <info>php %command.full_name% --member-id=123 --history</info>

During chat:
  - Type messages to send them through the Signal pipeline
  - Type 'exit', 'quit' or '/stop' to end the session
  - Type '/history' to show conversation history
  - Type '/reset' to reset the conversation
  - Type '/info' to show member information
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        
        $this->io->title('Church Chat Simulator');
        
        if (!$this->initializeMember($input)) {
            return Command::FAILURE;
        }
        
        $this->displayMemberInfo();
        
        if ($input->getOption('reset')) {
            $this->resetConversation();
        }
        
        if ($input->getOption('history')) {
            $this->showHistory();
        }
        
        if ($input->getOption('test-tools')) {
            return $this->testTools();
        }
        
        return $this->runChatLoop($input, $output);
    }
    
    private function initializeMember(InputInterface $input): bool
    {
        $memberId = $input->getOption('member-id');
        $phone = $input->getOption('phone');
        
        if (!$memberId && !$phone) {
            $memberId = $this->io->ask('Enter member ID or phone number');
            if (!$memberId) {
                $this->io->error('Member ID or phone number is required');
                return false;
            }
            
            if (str_starts_with($memberId, '+') || is_numeric($memberId[0])) {
                $phone = $memberId;
                $memberId = null;
            }
        }
        
        if ($memberId) {
            $this->currentMember = $this->memberRepository->find($memberId);
        } elseif ($phone) {
            $phone = $this->normalizePhoneNumber($phone);
            $this->currentMember = $this->memberRepository->findOneBy(['phoneNumber' => $phone]);
        }
        
        if (!$this->currentMember) {
            $this->io->error('Member not found');
            return false;
        }
        
        return true;
    }
    
    private function displayMemberInfo(): void
    {
        $this->io->section('Member Information');
        $this->io->table(
            ['Field', 'Value'],
            [
                ['ID', $this->currentMember->getId()],
                ['Name', $this->currentMember->getFirstName() ?? 'Not set'],
                ['Phone', $this->currentMember->getPhoneNumber()],
                ['Age', $this->currentMember->getAge() ?? 'Not set'],
                ['Target Group', $this->currentMember->getTargetGroup() ?? 'volwassen'],
                ['Churches', implode(', ', $this->currentMember->getChurchIds())],
                ['Conversation ID', $this->currentMember->getOpenaiConversationId() ?? 'None'],
                ['Active Sermon', $this->currentMember->getActiveSermonId() ?? 'None']
            ]
        );
    }
    
    private function runChatLoop(InputInterface $input, OutputInterface $output): int
    {
        $this->io->success('Chat session started. Type "exit", "quit" or "/stop" to end.');
        $this->io->text('Special commands: /history, /reset, /info');
        $this->io->newLine();
        
        $helper = $this->getHelper('question');
        
        while (true) {
            $question = new Question('<fg=cyan>You:</>  ');
            $message = $helper->ask($input, $output, $question);
            
            if (!$message) {
                continue;
            }
            
            $message = trim($message);
            
            if (in_array(strtolower($message), ['exit', 'quit', '/stop'])) {
                $this->io->info('Ending chat session...');
                break;
            }
            
            if ($message === '/history') {
                $this->showHistory();
                continue;
            }
            
            if ($message === '/reset') {
                $this->resetConversation();
                continue;
            }
            
            if ($message === '/info') {
                $this->displayMemberInfo();
                continue;
            }
            
            $this->processMessage($message);
        }
        
        return Command::SUCCESS;
    }
    
    private function processMessage(string $message): void
    {
        try {
            $signalMessage = new SignalMessageReceivedMessage(
                sender: $this->currentMember->getPhoneNumber(),
                recipient: '+31682016353',
                message: $message,
                timestamp: time() * 1000,
                rawData: ['source' => 'cli_simulator']
            );
            
            $this->messageBus->dispatch($signalMessage);
            
            $this->io->text('<fg=gray>Message dispatched to queue...</>');
            
            sleep(2);
            
            $latestHistory = $this->chatHistoryRepository->findByMember($this->currentMember, 2);
            
            foreach (array_reverse($latestHistory) as $chat) {
                if ($chat->getRole() === 'assistant') {
                    $this->io->newLine();
                    $this->io->text('<fg=green>Assistant:</> ' . $this->formatMessage($chat->getContent()));
                    
                    if ($chat->getToolCalls()) {
                        $this->io->text('<fg=yellow>Tools used: ' . implode(', ', array_map(
                            fn($tc) => $tc['name'] ?? 'unknown',
                            $chat->getToolCalls()
                        )) . '</>');
                    }
                    break;
                }
            }
            
        } catch (\Exception $e) {
            $this->io->error('Failed to process message: ' . $e->getMessage());
            $this->logger->error('Chat command message processing failed', [
                'error' => $e->getMessage(),
                'member_id' => $this->currentMember->getId()
            ]);
        }
    }
    
    private function showHistory(int $limit = 10): void
    {
        $history = $this->chatHistoryRepository->findByMember($this->currentMember, $limit);
        
        if (empty($history)) {
            $this->io->info('No chat history found');
            return;
        }
        
        $this->io->section('Chat History (last ' . $limit . ' messages)');
        
        foreach (array_reverse($history) as $chat) {
            $role = $chat->getRole() === 'user' ? '<fg=cyan>You</>' : '<fg=green>Assistant</>';
            $timestamp = $chat->getCreatedAt()->format('H:i:s');
            
            $this->io->text(sprintf(
                '[%s] %s: %s',
                $timestamp,
                $role,
                $this->formatMessage($chat->getContent(), 80)
            ));
            
            if ($chat->getToolCalls()) {
                $this->io->text('  <fg=yellow>→ Tools: ' . implode(', ', array_map(
                    fn($tc) => $tc['name'] ?? 'unknown',
                    $chat->getToolCalls()
                )) . '</>');
            }
        }
        
        $this->io->newLine();
    }
    
    private function resetConversation(): void
    {
        $this->currentMember->setOpenaiConversationId(null);
        $this->currentMember->setActiveSermonId(null);
        $this->entityManager->persist($this->currentMember);
        $this->entityManager->flush();
        
        $this->io->success('Conversation has been reset');
    }
    
    private function testTools(): int
    {
        $this->io->section('Testing All Tools');
        
        $testMessages = [
            'Mijn naam is Test User en ik ben 35 jaar',
            'Ik was er afgelopen zondag bij de dienst',
            'Kan ik een samenvatting krijgen van de preek?',
            'Ik wil graag wekelijks updates ontvangen',
            'Wat betekent genade volgens de Bijbel?',
            'De preek was erg inspirerend, dank je wel!'
        ];
        
        $this->io->progressStart(count($testMessages));
        
        foreach ($testMessages as $message) {
            $this->io->progressAdvance();
            $this->io->text('Testing: ' . $message);
            $this->processMessage($message);
            sleep(1);
        }
        
        $this->io->progressFinish();
        $this->io->success('Tool testing completed');
        
        return Command::SUCCESS;
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
    
    private function formatMessage(string $message, int $maxLength = 100): string
    {
        if (strlen($message) > $maxLength) {
            return substr($message, 0, $maxLength - 3) . '...';
        }
        return $message;
    }
}