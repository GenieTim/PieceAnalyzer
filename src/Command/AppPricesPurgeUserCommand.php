<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:prices:purge-user', description: 'Bulk-delete all price submissions from a specific user and recalculate active set prices.')]
class AppPricesPurgeUserCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'User email or API token to purge contributions from')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = trim((string) $input->getArgument('identifier'));

        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => strtolower($identifier)])
            ?? $userRepo->findOneBy(['apiToken' => $identifier]);

        if (!$user instanceof User) {
            $io->error(sprintf('User with identifier "%s" not found.', $identifier));
            return Command::FAILURE;
        }

        $priceRepo = $this->em->getRepository(SetPrice::class);
        $userPrices = $priceRepo->findBy(['submittedBy' => $user]);

        $count = count($userPrices);
        if ($count === 0) {
            $io->warning(sprintf('User "%s" has no recorded price submissions.', $user->getEmail()));
            return Command::SUCCESS;
        }

        /** @var array<int, Set> $affectedSets */
        $affectedSets = [];
        foreach ($userPrices as $price) {
            $set = $price->getSet();
            if ($set instanceof Set && $set->getId() !== null) {
                $affectedSets[$set->getId()] = $set;
                $set->removePrice($price);
            }
            $this->em->remove($price);
        }

        // Recalculate best price for all affected sets
        foreach ($affectedSets as $set) {
            $set->updateCachedBestPrice();
            $this->em->persist($set);
        }

        $this->em->flush();

        $io->success(sprintf(
            'Successfully purged %d price submissions by user "%s" across %d sets.',
            $count,
            $user->getEmail(),
            count($affectedSets)
        ));

        return Command::SUCCESS;
    }
}
