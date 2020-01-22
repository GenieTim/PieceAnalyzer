<?php

namespace App\Command;

use App\Service\CsvLegoLoaderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AppDataImportCsvCommand extends Command
{

    public static $defaultName = 'app:data:import-csv';
    protected $loader;
    protected $em;

    public function __construct(CsvLegoLoaderService $loader, EntityManagerInterface $em)
    {
        $this->loader = $loader;
        $this->em = $em;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Import the CSV files from the data directory')
            ->addOption('count', 'nu', InputOption::VALUE_OPTIONAL, 'Number of sets to import', 0)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $end = $input->getOption('count');

        $end = $end ? $end : $this->getLines($this->loader->normalizeCsvPath('sets'));
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
    }

    protected function resetDatabase()
    {
        $query = '
            DELETE FROM piece;
            DELETE FROM lego_set;
            DELETE FROM item;
           ';
        return $this->em->getConnection()->prepare($query)->execute();
    }

    protected function getLines($file)
    {
        $f = fopen($file, 'rb');
        $lines = 0;

        while (!feof($f)) {
            $lines += substr_count(fread($f, 8192), "\n");
        }

        fclose($f);

        return $lines;
    }
}
