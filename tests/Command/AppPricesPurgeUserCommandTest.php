<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AppPricesPurgeUserCommand;
use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\PriceSourceProvider;
use App\Enum\Retailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class AppPricesPurgeUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPurgeRogueUserSubmissions(): void
    {
        // 1. Create a rogue user and a normal user
        $rogueUser = new User('rogue@example.com', 'pass');
        $rogueUser->setApiToken('rogue_token_123');
        $this->em->persist($rogueUser);

        $goodUser = new User('good@example.com', 'pass');
        $goodUser->setApiToken('good_token_456');
        $this->em->persist($goodUser);

        // 2. Create a test set
        $set = new Set();
        $set->setNo('88888-1');
        $set->setName('Test Purge Set');
        $this->em->persist($set);

        // 3. Add a bogus low price from rogue user
        $bogusPrice = new SetPrice(
            set: $set,
            amount: 1990,
            currency: 'CHF',
            retailer: Retailer::GALAXUS,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );
        $bogusPrice->setSubmittedBy($rogueUser);
        $set->addPrice($bogusPrice);
        $this->em->persist($bogusPrice);

        // 4. Add a legitimate price from good user
        $legitPrice = new SetPrice(
            set: $set,
            amount: 4990,
            currency: 'CHF',
            retailer: Retailer::BRACK,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );
        $legitPrice->setSubmittedBy($goodUser);
        $set->addPrice($legitPrice);
        $this->em->persist($legitPrice);

        $this->em->flush();

        // Initially best price is 19.90 (bogus)
        $this->assertSame(19.90, $set->getPrice());

        // 5. Run the purge command targeting the rogue user
        $command = new AppPricesPurgeUserCommand($this->em);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['identifier' => 'rogue@example.com']);
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Successfully purged 1 price submissions', $tester->getDisplay());

        // 6. Verify database state
        $this->em->clear();
        $reloadedSet = $this->em->getRepository(Set::class)->findOneBy(['no' => '88888-1']);
        $this->assertNotNull($reloadedSet);

        // Best price is now 49.90 (recalculated from good user)
        $this->assertSame(49.90, $reloadedSet->getPrice());
        $this->assertCount(1, $reloadedSet->getPrices());

        // Cleanup
        $this->em->remove($reloadedSet);
        $u1 = $this->em->getRepository(User::class)->findOneBy(['email' => 'rogue@example.com']);
        $u2 = $this->em->getRepository(User::class)->findOneBy(['email' => 'good@example.com']);
        if ($u1 !== null) {
            $this->em->remove($u1);
        }
        if ($u2 !== null) {
            $this->em->remove($u2);
        }
        $this->em->flush();
    }
}
