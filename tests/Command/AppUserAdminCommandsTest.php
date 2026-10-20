<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AppUserAdminCommandsTest extends KernelTestCase
{
    private function cleanDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\SetPrice')->execute();
        $em->createQuery('DELETE FROM App\Entity\Piece')->execute();
        $em->createQuery('DELETE FROM App\Entity\Set')->execute();
        $em->createQuery('DELETE FROM App\Entity\Item')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testUserListCommand(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $user1 = new User('alice@example.com', 'pass1');
        $user1->setRoles(['ROLE_ADMIN']);
        $user1->setIsVerifiedContributor(true);

        $user2 = new User('bob@example.com', 'pass2');

        $em->persist($user1);
        $em->persist($user2);
        $em->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('app:user:list');
        $tester = new CommandTester($command);

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();

        $this->assertStringContainsString('alice@example.com', $output);
        $this->assertStringContainsString('bob@example.com', $output);
        $this->assertStringContainsString('ROLE_ADMIN', $output);

        // Filter by verified
        $tester->execute(['--verified' => true]);
        $outputVerified = $tester->getDisplay();
        $this->assertStringContainsString('alice@example.com', $outputVerified);
        $this->assertStringNotContainsString('bob@example.com', $outputVerified);
    }

    public function testUserPromoteCommand(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $user = new User('charlie@example.com', 'pass');
        $em->persist($user);
        $em->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('app:user:promote');
        $tester = new CommandTester($command);

        // Promote to admin and verify
        $tester->execute([
            'email' => 'charlie@example.com',
            '--admin' => true,
            '--verify' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        $updated = $em->getRepository(User::class)->findOneBy(['email' => 'charlie@example.com']);
        $this->assertContains('ROLE_ADMIN', $updated?->getRoles());
        $this->assertTrue($updated?->isVerifiedContributor());

        // Revoke admin
        $tester->execute([
            'email' => 'charlie@example.com',
            '--unadmin' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        $revoked = $em->getRepository(User::class)->findOneBy(['email' => 'charlie@example.com']);
        $this->assertNotContains('ROLE_ADMIN', $revoked?->getRoles());
    }

    public function testUserDeleteCommand(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $user = new User('david@example.com', 'pass');
        $em->persist($user);
        $em->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('app:user:delete');
        $tester = new CommandTester($command);

        $tester->execute([
            'email' => 'david@example.com',
            '--force' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        $deleted = $em->getRepository(User::class)->findOneBy(['email' => 'david@example.com']);
        $this->assertNull($deleted);
    }

    public function testExchangeRatesCommand(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('app:prices:rates');
        $tester = new CommandTester($command);

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Multi-Currency Exchange Rates', $output);
        $this->assertStringContainsString('CHF', $output);
        $this->assertStringContainsString('EUR', $output);
        $this->assertStringContainsString('USD', $output);
    }

    public function testUserCreateCommandWithUsername(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $application = new Application(self::$kernel);
        $command = $application->find('app:user:create');
        $tester = new CommandTester($command);

        $tester->execute([
            'email' => 'create_user@example.com',
            'password' => 'secretPassword123!',
            '--username' => 'MyCliUser',
            '--verified' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'create_user@example.com']);
        $this->assertNotNull($user);
        $this->assertSame('MyCliUser', $user->getUsername());
        $this->assertSame('MyCliUser', $user->getDisplayName());
        $this->assertTrue($user->isVerifiedContributor());
    }
}
