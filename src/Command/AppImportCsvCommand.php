<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\CsvLegoLoaderService;

class AppImportCsvCommand extends Command
{
    protected static $defaultName = 'app:import-csv';
    protected $loader;
    
    public function __construct(CsvLegoLoaderService $loader) {
        $this->loader = $loader;
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
        
        $cnt = $end ? $end : 'all';
        $io->writeln("Starting to import $cnt sets.");
        
        $this->loader->loadSets(1, $end);
        
        $io->success("Successfully imported sets");
    }
}
