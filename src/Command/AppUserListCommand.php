<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SetPrice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:list', description: 'List all registered users and their contributor details.')]
class AppUserListCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Filter by role (e.g. ROLE_ADMIN)')
            ->addOption('verified', null, InputOption::VALUE_NONE, 'Filter by verified contributor status')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $roleFilter = $input->getOption('role');
        $onlyVerified = (bool) $input->getOption('verified');

        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findBy([], ['createdAt' => 'ASC']);

        $priceRepo = $this->em->getRepository(SetPrice::class);
        $rows = [];

        foreach ($users as $user) {
            $roles = $user->getRoles();
            if (is_string($roleFilter) && !in_array(strtoupper($roleFilter), $roles, true)) {
                continue;
            }
            if ($onlyVerified && !$user->isVerifiedContributor()) {
                continue;
            }

            $submissions = $priceRepo->count(['submittedBy' => $user]);

            $rows[] = [
                $user->getId(),
                $user->getEmail(),
                implode(', ', $roles),
                $user->isVerifiedContributor() ? '✓ Yes' : 'No',
                $submissions,
                $user->getPreferredCurrency(),
                $user->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }

        if ($rows === []) {
            $io->warning('No users found matching the given filters.');
            return Command::SUCCESS;
        }

        $io->title('Registered Users (' . count($rows) . ')');
        $io->table(
            ['ID', 'Email', 'Roles', 'Verified', 'Submissions', 'Currency', 'Created At'],
            $rows
        );

        return Command::SUCCESS;
    }
}
