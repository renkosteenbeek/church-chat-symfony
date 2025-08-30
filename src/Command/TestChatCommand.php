<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Member;
use App\Service\TestChatService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:test-chat',
    description: 'Autonomous testing tool for chat service - designed for Claude Code analysis',
)]
class TestChatCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly TestChatService $testChatService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scenario', 's', InputOption::VALUE_REQUIRED, 'Test specific scenario')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Test single input message')
            ->addOption('member-profile', 'p', InputOption::VALUE_REQUIRED, 'Member profile to use', 'volwassen')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Run all scenarios')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (json|table)', 'table')
            ->addOption('scenarios-file', null, InputOption::VALUE_REQUIRED, 'Path to scenarios file', 'tests/chat-scenarios.yaml')
            ->addOption('verbose-errors', null, InputOption::VALUE_NONE, 'Include full error details in output')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Show what would be tested without making API calls')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command provides autonomous testing capabilities for the chat service.

<comment>Basic Usage:</comment>
  <info>php %command.full_name% --input="Mijn naam is Renko"</info>
  <info>php %command.full_name% --scenario=name_recognition</info>
  <info>php %command.full_name% --all --format=json</info>

<comment>Member Profiles:</comment>
  • <info>jongeren</info>     - Youth target group
  • <info>volwassen</info>     - Adult target group (default)  
  • <info>verdieping</info>    - Deep theological discussions

<comment>Output Formats:</comment>
  • <info>table</info>        - Human-readable table output
  • <info>json</info>         - Machine-readable JSON for Claude Code analysis

<comment>Examples for Claude Code autonomous testing:</comment>
  <info>php %command.full_name% --all --format=json > results.json</info>
  <info>php %command.full_name% --scenario=failed_scenario --verbose-errors</info>
  <info>php %command.full_name% --dry-run --all</info>

The JSON output is optimized for programmatic analysis and includes:
- Test results with success/failure status
- Response validation details
- Performance metrics (latency, token usage)
- Actionable error messages and suggestions
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        
        if ($input->getOption('dry-run')) {
            return $this->runDryRun($input);
        }

        $scenariosFile = $input->getOption('scenarios-file');
        
        if (!file_exists($scenariosFile)) {
            $this->io->error("Scenarios file not found: {$scenariosFile}");
            $this->io->note('Run with --dry-run to see what would be tested, or create the scenarios file first.');
            return Command::FAILURE;
        }

        try {
            $scenarios = Yaml::parseFile($scenariosFile);
        } catch (\Exception $e) {
            $this->io->error("Failed to parse scenarios file: " . $e->getMessage());
            return Command::FAILURE;
        }

        $profile = $input->getOption('member-profile');
        $format = $input->getOption('format');
        
        if ($input->getOption('input')) {
            return $this->runSingleInput($input->getOption('input'), $profile, $format);
        }
        
        if ($input->getOption('scenario')) {
            return $this->runSingleScenario($input->getOption('scenario'), $scenarios, $profile, $format);
        }
        
        if ($input->getOption('all')) {
            return $this->runAllScenarios($scenarios, $profile, $format, $input->getOption('verbose-errors'));
        }

        $this->io->error('Please specify --input, --scenario, or --all');
        return Command::FAILURE;
    }

    private function runSingleInput(string $inputMessage, string $profile, string $format): int
    {
        $member = $this->createMemberForProfile($profile);
        
        $initResult = $this->testChatService->initializeTestConversation($member);
        if (!$initResult['success']) {
            $this->outputError('Failed to initialize conversation', $initResult['error'], $format);
            return Command::FAILURE;
        }

        $testResult = $this->testChatService->sendTestMessage($member, $inputMessage);
        
        $result = [
            'input' => $inputMessage,
            'profile' => $profile,
            'conversation_id' => $initResult['conversation_id'],
            'success' => $testResult['success'],
            'response' => $testResult['response'] ?? null,
            'tool_calls' => $testResult['tool_calls'] ?? [],
            'latency_ms' => $testResult['latency_ms'] ?? 0,
            'error' => $testResult['error'] ?? null
        ];

        $this->outputResults([$result], $format);
        
        return $testResult['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    private function runSingleScenario(string $scenarioName, array $scenarios, string $profile, string $format): int
    {
        $scenario = null;
        foreach ($scenarios['scenarios'] ?? [] as $s) {
            if ($s['name'] === $scenarioName) {
                $scenario = $s;
                break;
            }
        }

        if (!$scenario) {
            $this->io->error("Scenario '{$scenarioName}' not found");
            return Command::FAILURE;
        }

        $results = $this->executeScenario($scenario, $profile);
        $this->outputResults($results, $format);
        
        $allSuccessful = array_reduce($results, fn($carry, $r) => $carry && $r['success'], true);
        return $allSuccessful ? Command::SUCCESS : Command::FAILURE;
    }

    private function runAllScenarios(array $scenarios, string $profile, string $format, bool $verboseErrors): int
    {
        $startTime = microtime(true);
        $allResults = [];

        foreach ($scenarios['scenarios'] ?? [] as $scenario) {
            $results = $this->executeScenario($scenario, $profile);
            $allResults = array_merge($allResults, $results);
        }

        $endTime = microtime(true);
        $totalTimeMs = intval(($endTime - $startTime) * 1000);

        $analysis = $this->testChatService->analyzeTestResults($allResults);
        
        $output = [
            'test_run' => [
                'timestamp' => date('c'),
                'total_time_ms' => $totalTimeMs,
                'profile' => $profile,
                'scenarios_tested' => count($scenarios['scenarios'] ?? []),
                'total_inputs_tested' => count($allResults),
                'success_rate' => $analysis['summary']['success_rate'],
                'results' => $allResults,
                'analysis' => $analysis
            ]
        ];

        if ($format === 'json') {
            if ($verboseErrors) {
                $output['test_run']['verbose_errors'] = true;
            } else {
                foreach ($output['test_run']['results'] as &$result) {
                    unset($result['raw_response']);
                }
            }
            
            echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->outputTableResults($allResults, $analysis);
        }

        $successRate = $analysis['summary']['success_rate'];
        return $successRate >= 0.8 ? Command::SUCCESS : Command::FAILURE;
    }

    private function runDryRun(InputInterface $input): int
    {
        $scenariosFile = $input->getOption('scenarios-file');
        
        $this->io->title('Dry Run - Chat Testing Overview');
        
        if (!file_exists($scenariosFile)) {
            $this->io->warning("Scenarios file would be loaded from: {$scenariosFile}");
            $this->io->text('Example scenarios that would be created:');
            
            $exampleScenarios = $this->getExampleScenarios();
            foreach ($exampleScenarios['scenarios'] as $scenario) {
                $this->io->text("• {$scenario['name']}: {$scenario['description']}");
                $this->io->text("  Inputs: " . count($scenario['inputs']));
                $this->io->newLine();
            }
            
            return Command::SUCCESS;
        }

        try {
            $scenarios = Yaml::parseFile($scenariosFile);
            $this->io->success("Scenarios file loaded successfully");
            
            foreach ($scenarios['scenarios'] ?? [] as $scenario) {
                $inputCount = count($scenario['inputs'] ?? []);
                $this->io->text("• {$scenario['name']}: {$inputCount} test inputs");
            }
            
            $totalInputs = array_sum(array_map(fn($s) => count($s['inputs'] ?? []), $scenarios['scenarios'] ?? []));
            $this->io->note("Would test {$totalInputs} total inputs across " . count($scenarios['scenarios'] ?? []) . " scenarios");
            
        } catch (\Exception $e) {
            $this->io->error("Would fail to parse scenarios: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function executeScenario(array $scenario, string $profile): array
    {
        $results = [];
        
        foreach ($scenario['inputs'] ?? [] as $inputData) {
            $input = is_string($inputData) ? $inputData : $inputData['text'];
            $member = $this->createMemberForProfile($profile, $scenario);
            
            $initResult = $this->testChatService->initializeTestConversation($member);
            if (!$initResult['success']) {
                $results[] = [
                    'scenario' => $scenario['name'],
                    'input' => $input,
                    'success' => false,
                    'error' => 'Failed to initialize conversation: ' . $initResult['error'],
                    'latency_ms' => 0
                ];
                continue;
            }

            $testResult = $this->testChatService->sendTestMessage($member, $input);
            
            $validationResults = null;
            if ($testResult['success'] && isset($scenario['expected'])) {
                $validationResults = $this->testChatService->validateResponse($testResult, $scenario['expected']);
                $testResult['success'] = $testResult['success'] && $validationResults['success'];
            }

            $results[] = [
                'scenario' => $scenario['name'],
                'input' => $input,
                'expected_tool_calls' => $scenario['expected']['tool_calls'] ?? [],
                'actual_tool_calls' => array_column($testResult['tool_calls'] ?? [], 'name'),
                'response' => $testResult['response'] ?? null,
                'success' => $testResult['success'],
                'latency_ms' => $testResult['latency_ms'] ?? 0,
                'error' => $testResult['error'] ?? null,
                'validation_results' => $validationResults,
                'raw_response' => $testResult['raw_response'] ?? null
            ];
        }

        return $results;
    }

    private function createMemberForProfile(string $profile, ?array $scenario = null): Member
    {
        $config = [
            'name' => 'TestUser',
            'target_group' => match($profile) {
                'jongeren' => Member::TARGET_GROUP_JONGEREN,
                'verdieping' => Member::TARGET_GROUP_VERDIEPING,
                default => Member::TARGET_GROUP_VOLWASSEN
            }
        ];

        if ($scenario && isset($scenario['setup']['context'])) {
            $config['context'] = $scenario['setup']['context'];
        }

        return $this->testChatService->createMockMember($config);
    }

    private function outputResults(array $results, string $format): void
    {
        if ($format === 'json') {
            echo json_encode(['results' => $results], JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->outputTableResults($results);
        }
    }

    private function outputTableResults(array $results, ?array $analysis = null): void
    {
        $tableData = [];
        
        foreach ($results as $result) {
            $tableData[] = [
                $result['scenario'] ?? 'single_input',
                substr($result['input'], 0, 30) . (strlen($result['input']) > 30 ? '...' : ''),
                $result['success'] ? '✅' : '❌',
                implode(', ', $result['actual_tool_calls'] ?? []) ?: '-',
                $result['latency_ms'] . 'ms',
                $result['error'] ? substr($result['error'], 0, 40) . '...' : '-'
            ];
        }

        $this->io->table(
            ['Scenario', 'Input', 'Success', 'Tools Called', 'Latency', 'Error'],
            $tableData
        );

        if ($analysis) {
            $this->io->section('Analysis Summary');
            $summary = $analysis['summary'];
            
            $this->io->text([
                "Success Rate: {$summary['success_rate']}",
                "Average Latency: {$summary['avg_latency_ms']}ms",
                "P95 Latency: {$summary['p95_latency_ms']}ms"
            ]);
            
            if (!empty($analysis['common_failures'])) {
                $this->io->text("Common Issues: " . implode(', ', $analysis['common_failures']));
            }
        }
    }

    private function outputError(string $message, string $error, string $format): void
    {
        if ($format === 'json') {
            echo json_encode(['error' => $message, 'details' => $error]) . "\n";
        } else {
            $this->io->error($message . ': ' . $error);
        }
    }

    private function getExampleScenarios(): array
    {
        return [
            'scenarios' => [
                [
                    'name' => 'name_recognition',
                    'description' => 'Test if names are correctly recognized and stored',
                    'inputs' => ['Mijn naam is Renko', 'Ik heet Maria'],
                    'expected' => ['tool_calls' => ['manage_user']]
                ],
                [
                    'name' => 'sermon_attendance', 
                    'description' => 'Test sermon attendance registration',
                    'inputs' => ['Ja, ik was erbij', 'Nee, helaas niet'],
                    'expected' => ['tool_calls' => ['handle_sermon']]
                ],
                [
                    'name' => 'theological_questions',
                    'description' => 'Test theological question handling',
                    'inputs' => ['Wat betekent genade?', 'Wie is Jezus?'],
                    'expected' => ['tool_calls' => ['answer_question']]
                ]
            ]
        ];
    }
}