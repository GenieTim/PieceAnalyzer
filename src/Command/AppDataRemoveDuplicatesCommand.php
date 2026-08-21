<?php

namespace App\Command;

use App\Entity\Set;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:data:remove-duplicates', description: 'Purge duplicate sets from database')]
class AppDataRemoveDuplicatesCommand extends Command
{
    /** @var array<string, string> */
    protected array $uniqueSets = [];

    public function __construct(protected EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $qb = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Set::class, 's')
            ->groupBy('s.name, s.no')
            ->having('COUNT(s) > 1');

        /** @var array<int, Set|array<Set>> $rows */
        $rows = $qb->getQuery()->getResult();
        $io->progressStart(count($rows));
        $purgeNo = $this->loopDuplicates($rows, $io);
        $io->progressFinish();
        $this->em->flush();

        $io->success("Purged $purgeNo duplicates from the database.");

        return Command::SUCCESS;
    }

    /**
     * @param array<int, mixed> $duplicates
     */
    protected function loopDuplicates(array $duplicates, ?SymfonyStyle $io): int
    {
        $purgeNo = 0;
        $batchSize = 50;
        $i = 0;
        foreach ($duplicates as $set) {
            if (is_array($set)) {
                $purgeNo += $this->loopDuplicates($set, null);
            } elseif ($set instanceof Set) {
                $unique = true;
                $no = (string) $set->getNo();
                $name = (string) $set->getName();
                if (isset($this->uniqueSets[$no]) && $this->uniqueSets[$no] === $name) {
                    $this->em->remove($set);
                    $unique = false;
                    $purgeNo++;
                }
                if ($unique) {
                    $this->uniqueSets[$no] = $name;
                }
            }

            if (($i % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
            ++$i;
            $io?->progressAdvance();
        }
        return $purgeNo;
    }
}
