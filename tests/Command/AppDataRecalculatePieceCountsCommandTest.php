<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Piece;
use App\Entity\Set;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AppDataRecalculatePieceCountsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testRecalculatePieceCountsCommand(): void
    {
        // 1. Create a set with pieces and null pieceCount
        $set = new Set();
        $set->setNo('TEST-RECALC-' . bin2hex(random_bytes(3)));
        $set->setName('Recalculate Test Set');
        $set->setPieceCount(null);
        $this->em->persist($set);

        $piece1 = new Piece();
        $piece1->setNo('PART1');
        $piece1->setName('Part 1');
        $piece1->setColor(1);
        $piece1->setCategory(1);
        $piece1->setCount(42);
        $piece1->setSet($set);
        $this->em->persist($piece1);

        $piece2 = new Piece();
        $piece2->setNo('PART2');
        $piece2->setName('Part 2');
        $piece2->setColor(2);
        $piece2->setCategory(1);
        $piece2->setCount(18);
        $piece2->setSet($set);
        $this->em->persist($piece2);

        $this->em->flush();
        $setId = $set->getId();
        $this->em->clear();

        // 2. Execute command
        $application = new Application(self::$kernel);
        $command = $application->find('app:data:recalculate-piece-counts');
        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute(['--force' => true]);
        $this->assertSame(0, $exitCode);

        // 3. Verify pieceCount was updated in database
        $reloaded = $this->em->getRepository(Set::class)->find($setId);
        $this->assertNotNull($reloaded);
        $this->assertSame(60, $reloaded->getPieceCount());

        // Cleanup
        $this->em->remove($reloaded);
        $this->em->flush();
    }
}
