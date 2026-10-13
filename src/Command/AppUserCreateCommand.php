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
            ->addArgument('password', InputArgument::OPTIONAL, 'User password (prompted interactively if omitted)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN')
            ->addOption('verified', null, InputOption::VALUE_NONE, 'Mark as verified contributor (reports are auto-verified)')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));
        $password = (string) ($input->getArgument('password') ?? '');
        if ($password === '') {
            $password = (string) $io->askHidden('User password:');
            if ($password === '') {
                $io->error('Password cannot be empty.');
                return Command::FAILURE;
            }
        }
        $isAdmin = (bool) $input->getOption('admin');
        $isVerified = (bool) $input->getOption('verified');

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            $io->error(sprintf('User with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        $user = new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->setIsEmailVerified(true);
        if ($isAdmin) {
            $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
            $user->setIsVerifiedContributor(true);
        }
        if ($isVerified) {
            $user->setIsVerifiedContributor(true);
        }

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('User "%s" created successfully!', $email));
        $io->table(['Email', 'Roles', 'Verified Contributor', 'API Token'], [
            [$user->getEmail(), implode(', ', $user->getRoles()), $user->isVerifiedContributor() ? 'Yes' : 'No', $user->getApiToken()],
        ]);

        return Command::SUCCESS;
    }
}
