<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:promote', description: 'Promote, demote, or change contributor status for a user.')]
class AppUserPromoteCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email address')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN')
            ->addOption('unadmin', null, InputOption::VALUE_NONE, 'Revoke ROLE_ADMIN')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Mark as verified contributor')
            ->addOption('unverify', null, InputOption::VALUE_NONE, 'Revoke verified contributor status')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error(sprintf('User with email "%s" not found.', $email));
            return Command::FAILURE;
        }

        $roles = $user->getRoles();
        $changes = [];

        if ($input->getOption('admin')) {
            if (!in_array('ROLE_ADMIN', $roles, true)) {
                $roles[] = 'ROLE_ADMIN';
                $user->setRoles($roles);
                $changes[] = 'Granted ROLE_ADMIN';
            }
        } elseif ($input->getOption('unadmin')) {
            $roles = array_values(array_diff($roles, ['ROLE_ADMIN']));
            $user->setRoles($roles);
            $changes[] = 'Revoked ROLE_ADMIN';
        }

        if ($input->getOption('verify')) {
            $user->setIsVerifiedContributor(true);
            $changes[] = 'Marked as verified contributor';
        } elseif ($input->getOption('unverify')) {
            $user->setIsVerifiedContributor(false);
            $changes[] = 'Revoked verified contributor status';
        }

        if ($changes === []) {
            $io->warning('No changes requested. Specify --admin, --unadmin, --verify, or --unverify.');
            return Command::SUCCESS;
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Updated user "%s": %s', $user->getEmail(), implode(', ', $changes)));
        return Command::SUCCESS;
    }
}
