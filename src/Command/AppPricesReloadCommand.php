<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use App\Entity\Item;
use App\Entity\Set;
use App\Service\BrickPickerPriceLoaderService;

class AppPricesReloadCommand extends Command {

    protected static $defaultName = 'app:prices:reload';
    protected $loader;
    protected $em;
    protected $io;
    protected $logger;

    public function __construct(BrickPickerPriceLoaderService $loader, EntityManagerInterface $em, LoggerInterface $logger) {
        parent::__construct();
        $this->em = $em;
        $this->loader = $loader;
        $this->logger = $logger;
    }

    protected function configure() {
        $this
                ->setDescription('Reload the prices of the Items.')
                ->addOption('all', null, InputOption::VALUE_NONE, 'Reload prices for all items.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->io = new SymfonyStyle($input, $output);

        $qb = $this->em->createQueryBuilder()->select('s')->from(Set::class, 's');
        if (!$input->getOption('all')) {
            $qb->andWhere('s.price IS NULL');
        }
        $countQuery = clone $qb;
        $count = $countQuery->select('count(s.id)')->getQuery()->getSingleScalarResult();
        $this->io->progressStart($count);
        $q = $qb->getQuery();

        $unsolved_sets = $q->iterate();
        $this->loadPriceForSets($unsolved_sets);
        $this->io->progressFinish();

        $this->io->success('Prices have been reloaded.');
    }

    protected function loadPriceForSets($rows) {
        $batchSize = 50;
        $i = 0;
        foreach ($rows as $row) {
            $set = $row[0];
            $this->io->progressAdvance();
            try {
                $set->setPrice($this->loader->loadPriceForSet($set->getNo()));
                $this->em->persist($set);
            } catch (\Exception $e) {
                $this->logger->warn('error while loading price', array('error' => $e));
            }
            if (($i % $batchSize) === 0) {
                $this->em->flush(); // Executes all updates.
                $this->em->clear(); // Detaches all objects from Doctrine!
            }
            ++$i;
        }
        $this->em->flush();
    }

}
