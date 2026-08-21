<?php

namespace App\Command;

use App\Service\CsvLegoLoaderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:data:import-csv', description: 'Import the CSV files from the data directory')]
class AppDataImportCsvCommand extends Command
{
    public function __construct(
        protected CsvLegoLoaderService $loader,
        protected EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Number of sets to import', 0)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $end = (int) $input->getOption('count');

        $end = $end ?: $this->getLines($this->loader->normalizeCsvPath('sets'));
        $this->resetDatabase();
        $io->writeln("Starting to import $end sets.");

        $numberAtOnce = 50;
        $start = 1;
        $sets = 1;
        $localStart = $start;
        $localEnd = $start;
        $io->progressStart($end);
        while ($localEnd <= $end && $sets) {
            $localStart = $localEnd;
            $localEnd += $numberAtOnce;
            if ($localEnd >= $end) {
                $localEnd = $end + 1;
            }
            $sets = $this->loader->loadSets($localStart, $localEnd);
            $io->progressAdvance($numberAtOnce);
        }
        $io->progressFinish();
        $io->success("Successfully imported some sets");

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
