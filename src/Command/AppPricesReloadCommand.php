<?php

namespace App\Command;

use App\Entity\Set;
use App\Service\BrickPickerPriceLoaderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:prices:reload', description: 'Reload the prices of the Items.')]
class AppPricesReloadCommand extends Command
{
    public function __construct(
        protected BrickPickerPriceLoaderService $loader,
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Reload prices for all items.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $qb = $this->em->createQueryBuilder()->select('s')->from(Set::class, 's');
        if (!$input->getOption('all')) {
            $qb->andWhere('s.price IS NULL');
        }
        $countQuery = clone $qb;
        $count = (int) $countQuery->select('count(s.id)')->getQuery()->getSingleScalarResult();
        $io->progressStart($count);
        $q = $qb->getQuery();

        $unsolved_sets = $q->toIterable();
        $this->loadPriceForSets($unsolved_sets, $io);
        $io->progressFinish();

        $io->success('Prices have been reloaded.');

        return Command::SUCCESS;
    }

    /**
     * @param iterable<mixed> $rows
     */
    protected function loadPriceForSets(iterable $rows, SymfonyStyle $io): void
    {
        $batchSize = 50;
        $i = 0;
        foreach ($rows as $row) {
            $set = is_array($row) ? $row[0] : $row;
            $io->progressAdvance();
            if ($set instanceof Set) {
                try {
                    $set->setPrice($this->loader->loadPriceForSet($set->getNo()));
                    $this->em->persist($set);
                } catch (\Throwable $e) {
                    $this->logger->warning('error while loading price', ['error' => $e]);
                }
            }
            if (($i % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
            ++$i;
        }
        $this->em->flush();
    }
}
