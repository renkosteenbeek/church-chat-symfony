<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\ContentDistributionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-content-queue',
    description: 'Process queued content for distribution via Signal',
)]
class ProcessContentQueueCommand extends Command
{
    public function __construct(
        private readonly ContentDistributionService $distributionService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Number of items to process', 10)
            ->addOption('continuous', 'c', InputOption::VALUE_NONE, 'Run continuously')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Interval between runs in seconds', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $continuous = $input->getOption('continuous');
        $interval = (int) $input->getOption('interval');

        $io->title('Content Queue Processor');

        if ($continuous) {
            $io->info('Running in continuous mode. Press Ctrl+C to stop.');
            $io->info(sprintf('Processing %d items every %d seconds', $limit, $interval));
            
            while (true) {
                $this->processQueue($io, $limit);
                
                if ($this->shouldStop()) {
                    $io->warning('Stop signal received. Exiting...');
                    break;
                }
                
                sleep($interval);
            }
        } else {
            $this->processQueue($io, $limit);
        }

        $io->success('Queue processing completed');
        return Command::SUCCESS;
    }

    private function processQueue(SymfonyStyle $io, int $limit): void
    {
        try {
            $io->section('Processing scheduled content');
            $scheduled = $this->distributionService->processScheduledContent();
            if ($scheduled > 0) {
                $io->success(sprintf('Moved %d scheduled items to queue', $scheduled));
            }

            $io->section('Processing content queue');
            $processed = $this->distributionService->processQueue($limit);
            
            if ($processed > 0) {
                $io->success(sprintf('Processed %d content items', $processed));
                
                $this->logger->info('Content queue processed', [
                    'processed' => $processed,
                    'limit' => $limit
                ]);
            } else {
                $io->info('No items to process');
            }
            
        } catch (\Exception $e) {
            $io->error('Error processing queue: ' . $e->getMessage());
            
            $this->logger->error('Queue processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function shouldStop(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        return file_exists('/tmp/stop-queue-processor');
    }
}