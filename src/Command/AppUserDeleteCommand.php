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

#[AsCommand(name: 'app:user:delete', description: 'Safely delete a user account.')]
class AppUserDeleteCommand extends Command
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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));
        $force = (bool) $input->getOption('force');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error(sprintf('User with email "%s" not found.', $email));
            return Command::FAILURE;
        }

        if (!$force && $input->isInteractive()) {
            $confirmed = $io->confirm(
                sprintf('Are you sure you want to permanently delete user "%s"?', $user->getEmail()),
                false
            );
            if (!$confirmed) {
                $io->note('User deletion cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->em->remove($user);
        $this->em->flush();

        $io->success(sprintf('User "%s" has been deleted.', $email));
        return Command::SUCCESS;
    }
}
