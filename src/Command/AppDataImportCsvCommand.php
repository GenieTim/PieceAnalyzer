<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\CsvLegoLoaderService;
use App\Service\TaskStatusService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:data:import-csv', description: 'Import the CSV files from the data directory')]
class AppDataImportCsvCommand extends Command
{
    protected TaskStatusService $statusService;

    public function __construct(
        protected readonly CsvLegoLoaderService $loader,
        protected readonly EntityManagerInterface $em,
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
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Number of sets to import', 0)
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Max execution time in seconds (0 for unlimited)', 3600)
            ->addOption('heartbeat', null, InputOption::VALUE_OPTIONAL, 'Heartbeat interval in seconds', 15)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $end = (int) $input->getOption('count');
        $timeout = (int) $input->getOption('timeout');
        $heartbeatInterval = max(1, (int) $input->getOption('heartbeat'));

        $end = $end ?: $this->getLines($this->loader->normalizeCsvPath('sets'));

        $this->statusService->start('app:data:import-csv', [
            'total_target' => $end,
            'timeout_seconds' => $timeout,
        ]);

        $io->title('Rebrickable CSV Sets Importer');
        $io->writeln(sprintf('Starting to import %d sets (timeout: %s).', $end, $timeout > 0 ? TaskStatusService::formatDuration($timeout) : 'none'));

        $this->resetDatabase();
        $this->loader->warmIndexCache();

        $startTime = microtime(true);
        $lastHeartbeat = $startTime;
        $warnedSlowThroughput = false;

        $numberAtOnce = 50;
        $sets = 1;
        $localStart = 1;
        $localEnd = 1;
        $processed = 0;

        $io->writeln(sprintf('[%s] Starting import loop...', date('H:i:s')));

        while ($localEnd <= $end && $sets) {
            $elapsed = microtime(true) - $startTime;
            if ($timeout > 0 && $elapsed >= $timeout) {
                $rate = $processed > 0 ? round($processed / $elapsed, 2) : 0.0;
                $diagnostics = [
                    'elapsed_seconds' => round($elapsed, 1),
                    'processed_sets' => $processed,
                    'total_sets' => $end,
                    'percentage' => round(($processed / $end) * 100, 1),
                    'throughput_rate' => $rate,
                    'estimated_total_seconds' => $rate > 0 ? (int) round($end / $rate) : null,
                ];

                $this->statusService->fail('Execution timeout limit exceeded', $diagnostics);
                $this->logger->critical('Import-csv aborted due to timeout', $diagnostics);

                $io->error(sprintf(
                    "Execution time limit of %s reached. Processed %d/%d sets (%.1f%%) in %s (%.2f sets/s).\n" .
                    "Estimated total time required: %s.\n" .
                    "Aborted cleanly to prevent silent host kill and provide diagnostic context.",
                    TaskStatusService::formatDuration($timeout),
                    $processed,
                    $end,
                    $diagnostics['percentage'],
                    TaskStatusService::formatDuration($elapsed),
                    $rate,
                    $diagnostics['estimated_total_seconds'] !== null ? TaskStatusService::formatDuration($diagnostics['estimated_total_seconds']) : 'unknown'
                ));

                return Command::FAILURE;
            }

            $localStart = $localEnd;
            $localEnd += $numberAtOnce;
            if ($localEnd >= $end) {
                $localEnd = $end + 1;
            }

            $batchResult = $this->loader->loadSets($localStart, $localEnd);
            $sets = is_array($batchResult) ? count($batchResult) : $batchResult;
            $processed = min($end, $localEnd - 1);

            $metrics = $this->statusService->updateStep('import_sets', $processed, $end);

            $now = microtime(true);
            if (($now - $lastHeartbeat) >= $heartbeatInterval || $processed >= $end) {
                $lastHeartbeat = $now;
                $io->writeln(sprintf(
                    '[%s] %d/%d sets (%s%%) | Rate: %.1f sets/s | Elapsed: %s | ETA: %s | Mem: %.1f MB',
                    date('H:i:s'),
                    $processed,
                    $end,
                    number_format($metrics['percentage'], 1),
                    $metrics['rate_per_second'],
                    TaskStatusService::formatDuration($metrics['elapsed_seconds']),
                    $metrics['eta_seconds'] !== null ? TaskStatusService::formatDuration($metrics['eta_seconds']) : 'N/A',
                    $metrics['memory_mb']
                ));

                if (!$warnedSlowThroughput && $metrics['rate_per_second'] < 2.0 && $processed >= 100) {
                    $warnedSlowThroughput = true;
                    $io->warning(sprintf(
                        'Low throughput detected (%.2f sets/s). Estimated completion time is %s. Check CSV lookups or database persistence.',
                        $metrics['rate_per_second'],
                        $metrics['eta_seconds'] !== null ? TaskStatusService::formatDuration($metrics['eta_seconds']) : 'unknown'
                    ));
                    $this->logger->warning('Low CSV import throughput detected', [
                        'rate' => $metrics['rate_per_second'],
                        'eta' => $metrics['eta_seconds'],
                        'processed' => $processed,
                    ]);
                }
            }
        }

        $totalDuration = microtime(true) - $startTime;
        $finalRate = $processed > 0 && $totalDuration > 0 ? round($processed / $totalDuration, 1) : 0.0;

        $this->statusService->finish(true, [
            'total_imported' => $processed,
            'duration_seconds' => round($totalDuration, 2),
            'throughput_rate' => $finalRate,
        ]);

        $io->success(sprintf(
            'Successfully imported %d sets in %s (avg %.1f sets/s, peak mem %.1f MB).',
            $processed,
            TaskStatusService::formatDuration($totalDuration),
            $finalRate,
            round(memory_get_peak_usage(true) / 1024 / 1024, 1)
        ));

        return Command::SUCCESS;
    }

    protected function resetDatabase(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM piece;');
        $connection->executeStatement('DELETE FROM lego_set;');
        $connection->executeStatement('DELETE FROM item;');
    }

    protected function getLines(string $file): int
    {
        if (!file_exists($file)) {
            return 0;
        }

        $f = fopen($file, 'rb');
        if (!$f) {
            return 0;
        }

        $lines = 0;
        while (!feof($f)) {
            $chunk = fread($f, 8192);
            if ($chunk !== false) {
                $lines += substr_count($chunk, "\n");
            }
        }

        fclose($f);

        return $lines;
    }
}
