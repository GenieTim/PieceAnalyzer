<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RebrickableDownloaderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:data:download-csv',
    description: 'Download the latest daily CSV data dumps from Rebrickable'
)]
class AppDataDownloadCsvCommand extends Command
{
    public function __construct(
        private readonly RebrickableDownloaderService $downloader
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force re-download even if dumps are up to date'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $dumpFiles = $this->downloader->getDumpFiles();
        $total = count($dumpFiles);

        $io->title('Rebrickable CSV Dumps Downloader');
        $io->writeln(sprintf('Target directory: %s', $this->downloader->getDataPath()));
        $io->writeln(sprintf('Downloading %d data dumps (force: %s)...', $total, $force ? 'yes' : 'no'));
        $io->newLine();

        $io->progressStart($total);

        $results = $this->downloader->downloadAll(
            $force,
            function (string $file, string $status, int $current, int $total) use ($io): void {
                if ($status === 'done' || $status === 'failed') {
                    $io->progressAdvance();
                }
            }
        );

        $io->progressFinish();
        $io->newLine();

        $rows = [];
        $hasFailures = false;

        foreach ($results as $file => $success) {
            if (!$success) {
                $hasFailures = true;
            }
            $rows[] = [
                $file,
                $success ? '<info>SUCCESS</info>' : '<error>FAILED</error>',
            ];
        }

        $io->table(['File', 'Status'], $rows);

        if ($hasFailures) {
            $io->warning('Some Rebrickable CSV dumps could not be downloaded.');
            return Command::FAILURE;
        }

        $io->success('All Rebrickable CSV dumps downloaded and decompressed successfully.');
        return Command::SUCCESS;
    }
}
