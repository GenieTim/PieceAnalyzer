<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Set;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:data:recalculate-piece-counts',
    description: 'Recalculate and persist cached piece_count for LEGO sets'
)]
class AppDataRecalculatePieceCountsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Recalculate all sets, even those that already have a non-null piece_count'
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $dql = 'SELECT s.id, COALESCE(SUM(p.count), 0) AS totalCount
                FROM App\Entity\Set s
                LEFT JOIN s.pieces p';

        if (!$force) {
            $dql .= ' WHERE s.pieceCount IS NULL';
        }

        $dql .= ' GROUP BY s.id';

        /** @var array<int, array{id: int, totalCount: int|string}> $results */
        $results = $this->em->createQuery($dql)->getArrayResult();

        $totalSets = count($results);
        if ($totalSets === 0) {
            $io->info('No sets require piece count recalculation.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Recalculating piece counts for %d set(s)%s', $totalSets, $force ? ' (forced)' : ''));
        $io->progressStart($totalSets);

        $batchSize = 200;
        $updated = 0;

        $updateQuery = $this->em->createQuery(
            'UPDATE App\Entity\Set s SET s.pieceCount = :count WHERE s.id = :id'
        );

        foreach ($results as $row) {
            $updateQuery->execute([
                'id' => $row['id'],
                'count' => (int) $row['totalCount'],
            ]);
            $updated++;
            $io->progressAdvance();

            if ($updated % $batchSize === 0) {
                $this->em->clear();
            }
        }

        $this->em->clear();
        $io->progressFinish();
        $io->success(sprintf('Successfully updated piece_count for %d set(s).', $updated));

        return Command::SUCCESS;
    }
}
