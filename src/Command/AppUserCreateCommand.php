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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a new user with an API token for price reporting.')]
class AppUserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email address')
            ->addArgument('password', InputArgument::REQUIRED, 'User password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));
        $password = (string) $input->getArgument('password');
        $isAdmin = (bool) $input->getOption('admin');

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            $io->error(sprintf('User with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        $user = new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        if ($isAdmin) {
            $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('User "%s" created successfully!', $email));
        $io->table(['Email', 'Roles', 'API Token'], [
            [$user->getEmail(), implode(', ', $user->getRoles()), $user->getApiToken()],
        ]);

        return Command::SUCCESS;
    }
}
