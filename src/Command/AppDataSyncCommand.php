<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TaskStatusService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:data:sync',
    description: 'Orchestrated sync: download Rebrickable CSV dumps, import datasets, and purge duplicates with telemetry'
)]
class AppDataSyncCommand extends Command
{
    protected TaskStatusService $statusService;

    public function __construct(
        ?TaskStatusService $statusService = null,
        protected readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->statusService = $statusService ?? new TaskStatusService(dirname(__DIR__, 2));
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force re-download even if dumps are up to date')
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Limit number of sets to import (0 for all)', 0)
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Overall timeout limit in seconds', 3600)
            ->addOption('skip-download', null, InputOption::VALUE_NONE, 'Skip download phase')
            ->addOption('skip-duplicates', null, InputOption::VALUE_NONE, 'Skip duplicate purge phase')
            ->addOption('status', 's', InputOption::VALUE_NONE, 'Show last recorded sync status and exit')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->showStatus($io);
        }

        $overallStart = microtime(true);
        $timeout = (int) $input->getOption('timeout');
        $force = (bool) $input->getOption('force');
        $count = (int) $input->getOption('count');
        $skipDownload = (bool) $input->getOption('skip-download');
        $skipDuplicates = (bool) $input->getOption('skip-duplicates');

        $this->statusService->start('app:data:sync', [
            'timeout' => $timeout,
            'force' => $force,
            'count' => $count,
            'skip_download' => $skipDownload,
            'skip_duplicates' => $skipDuplicates,
        ]);

        $io->title('Rebrickable Catalog Synchronizer');
        $io->writeln(sprintf('Started at: %s (overall timeout: %s)', date('Y-m-d H:i:s T'), TaskStatusService::formatDuration($timeout)));

        $phases = [];

        // Phase 1: Download
        if (!$skipDownload) {
            $io->section('[1/3] Downloading CSV dumps');
            $phaseStart = microtime(true);

            $downloadCommand = $this->getApplication()?->find('app:data:download-csv');
            if (!$downloadCommand instanceof \Symfony\Component\Console\Command\Command) {
                $io->error('Command app:data:download-csv not found');
                return Command::FAILURE;
            }

            $downloadInput = new ArrayInput(['--force' => $force]);
            $downloadExit = $downloadCommand->run($downloadInput, $output);
            $phaseDuration = microtime(true) - $phaseStart;

            $phases['download'] = [
                'name' => 'Download CSV Dumps',
                'status' => $downloadExit === Command::SUCCESS ? 'SUCCESS' : 'FAILED',
                'duration' => $phaseDuration,
            ];

            if ($downloadExit !== Command::SUCCESS) {
                $this->handleFailure($io, 'Download phase failed', $phases, $overallStart);
                return Command::FAILURE;
            }
        } else {
            $phases['download'] = ['name' => 'Download CSV Dumps', 'status' => 'SKIPPED', 'duration' => 0];
        }

        // Check remaining time before Phase 2
        $elapsed = microtime(true) - $overallStart;
        if ($timeout > 0 && $elapsed >= $timeout) {
            $this->handleFailure($io, 'Timeout reached before import phase', $phases, $overallStart);
            return Command::FAILURE;
        }

        // Phase 2: Import
        $io->section('[2/3] Importing CSV data into database');
        $phaseStart = microtime(true);
        $importCommand = $this->getApplication()?->find('app:data:import-csv');
        if (!$importCommand instanceof \Symfony\Component\Console\Command\Command) {
            $io->error('Command app:data:import-csv not found');
            return Command::FAILURE;
        }

        $remainingTimeout = $timeout > 0 ? max(60, (int) round($timeout - $elapsed)) : 0;
        $importArgs = [
            '--count' => $count,
            '--timeout' => $remainingTimeout,
        ];
        $importInput = new ArrayInput($importArgs);
        $importExit = $importCommand->run($importInput, $output);
        $phaseDuration = microtime(true) - $phaseStart;

        $phases['import'] = [
            'name' => 'Import Sets & Pieces',
            'status' => $importExit === Command::SUCCESS ? 'SUCCESS' : 'FAILED',
            'duration' => $phaseDuration,
        ];

        if ($importExit !== Command::SUCCESS) {
            $this->handleFailure($io, 'Import phase failed or timed out', $phases, $overallStart);
            return Command::FAILURE;
        }

        // Check remaining time before Phase 3
        $elapsed = microtime(true) - $overallStart;
        if ($timeout > 0 && $elapsed >= $timeout) {
            $this->handleFailure($io, 'Timeout reached before duplicates purge phase', $phases, $overallStart);
            return Command::FAILURE;
        }

        // Phase 3: Remove duplicates
        if (!$skipDuplicates) {
            $io->section('[3/3] Purging duplicates');
            $phaseStart = microtime(true);
            $purgeCommand = $this->getApplication()?->find('app:data:remove-duplicates');
            if (!$purgeCommand instanceof \Symfony\Component\Console\Command\Command) {
                $io->error('Command app:data:remove-duplicates not found');
                return Command::FAILURE;
            }

            $purgeInput = new ArrayInput([]);
            $purgeExit = $purgeCommand->run($purgeInput, $output);
            $phaseDuration = microtime(true) - $phaseStart;

            $phases['duplicates'] = [
                'name' => 'Purge Duplicate Sets',
                'status' => $purgeExit === Command::SUCCESS ? 'SUCCESS' : 'FAILED',
                'duration' => $phaseDuration,
            ];

            if ($purgeExit !== Command::SUCCESS) {
                $this->handleFailure($io, 'Duplicate removal phase failed', $phases, $overallStart);
                return Command::FAILURE;
            }
        } else {
            $phases['duplicates'] = ['name' => 'Purge Duplicate Sets', 'status' => 'SKIPPED', 'duration' => 0];
        }

        $totalDuration = microtime(true) - $overallStart;

        $this->statusService->finish(true, [
            'phases' => $phases,
            'total_duration' => TaskStatusService::formatDuration($totalDuration),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ]);

        $io->newLine();
        $rows = [];
        foreach ($phases as $p) {
            $statusFormatted = match ($p['status']) {
                'SUCCESS' => '<info>SUCCESS</info>',
                'SKIPPED' => '<comment>SKIPPED</comment>',
                default => '<error>' . $p['status'] . '</error>',
            };
            $rows[] = [$p['name'], $statusFormatted, TaskStatusService::formatDuration($p['duration'])];
        }

        $io->table(['Phase', 'Status', 'Duration'], $rows);
        $io->success(sprintf(
            'All sync phases completed successfully in %s (peak memory: %.1f MB).',
            TaskStatusService::formatDuration($totalDuration),
            round(memory_get_peak_usage(true) / 1024 / 1024, 1)
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array{name: string, status: string, duration: float|int}> $phases
     */
    private function handleFailure(SymfonyStyle $io, string $reason, array $phases, float $overallStart): void
    {
        $totalDuration = microtime(true) - $overallStart;

        $diagnostics = [
            'failure_reason' => $reason,
            'phases' => $phases,
            'elapsed_seconds' => round($totalDuration, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ];

        $this->statusService->fail($reason, $diagnostics);
        $this->logger->critical('Scheduled data sync failed', $diagnostics);

        $io->error(sprintf(
            "Sync failed: %s (after %s).\nInspect var/scheduled_task_status.json or run 'app:data:sync --status' for diagnostics.",
            $reason,
            TaskStatusService::formatDuration($totalDuration)
        ));
    }

    private function showStatus(SymfonyStyle $io): int
    {
        $status = $this->statusService->getStatus();
        if ($status === null) {
            $io->warning('No recorded task status found at ' . $this->statusService->getStatusFilePath());
            return Command::SUCCESS;
        }

        $io->title('Last Scheduled Task Status');
        $io->writeln(sprintf('Status file: %s', $this->statusService->getStatusFilePath()));

        $rows = [
            ['Task', (string) ($status['task'] ?? 'N/A')],
            ['Status', (string) ($status['status'] ?? 'N/A')],
            ['Started At', (string) ($status['started_at'] ?? 'N/A')],
            ['Updated At', (string) ($status['updated_at'] ?? 'N/A')],
            ['Elapsed', isset($status['elapsed_seconds']) ? TaskStatusService::formatDuration((int) $status['elapsed_seconds']) : 'N/A'],
            ['Peak Memory', isset($status['memory_peak_bytes']) ? round(((int) $status['memory_peak_bytes']) / 1024 / 1024, 1) . ' MB' : 'N/A'],
        ];

        if (!empty($status['step'])) {
            $rows[] = ['Current Step', (string) $status['step']];
        }

        if (!empty($status['progress'])) {
            $p = $status['progress'];
            $rows[] = ['Progress', sprintf('%d / %d (%s%%)', $p['current'] ?? 0, $p['total'] ?? 0, $p['percentage'] ?? 0)];
        }

        if (!empty($status['throughput'])) {
            $t = $status['throughput'];
            $rows[] = ['Rate', sprintf('%.2f items/sec', $t['rate_per_second'] ?? 0)];
            if (!empty($t['eta_seconds'])) {
                $rows[] = ['ETA', TaskStatusService::formatDuration((int) $t['eta_seconds'])];
            }
        }

        if (!empty($status['error'])) {
            $rows[] = ['Error', '<error>' . $status['error'] . '</error>'];
        }

        $io->table(['Property', 'Value'], $rows);

        return Command::SUCCESS;
    }
}
