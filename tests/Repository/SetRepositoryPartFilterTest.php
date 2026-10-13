<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Piece;
use App\Entity\Set;
use App\Repository\SetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SetRepositoryPartFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SetRepository $setRepo;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->setRepo = static::getContainer()->get(SetRepository::class);
    }

    public function testSetMatchingPartCount(): void
    {
        $set = new Set();
        $set->setNo('TEST-SET-1');
        $set->setName('Test Set');
        $set->setPrice(50.0);

        // Piece 1: Part 3001, Color 1, Count 10
        $p1 = new Piece();
        $p1->setNo('3001');
        $p1->setName('Brick 2 x 4');
        $p1->setColor(1);
        $p1->setCount(10);
        $p1->setSet($set);
        $set->addPiece($p1);

        // Piece 2: Part 3001, Color 2, Count 15
        $p2 = new Piece();
        $p2->setNo('3001');
        $p2->setName('Brick 2 x 4');
        $p2->setColor(2);
        $p2->setCount(15);
        $p2->setSet($set);
        $set->addPiece($p2);

        // Piece 3: Part 3020, Color 1, Count 100
        $p3 = new Piece();
        $p3->setNo('3020');
        $p3->setName('Plate 2 x 4');
        $p3->setColor(1);
        $p3->setCount(100);
        $p3->setSet($set);
        $set->addPiece($p3);

        // Total pieces in set
        $this->assertSame(125, $set->getPieceCount());

        // Count for part 3001 across all colors: 10 + 15 = 25
        $this->assertSame(25, $set->getMatchingPartCount('3001'));

        // Count for part 3001 specifically in Color 1: 10
        $this->assertSame(10, $set->getMatchingPartCount('3001', 1));

        // Count for part 3001 in Color 2: 15
        $this->assertSame(15, $set->getMatchingPartCount('3001', 2));

        // Part name search "Brick 2 x 4"
        $this->assertSame(25, $set->getMatchingPartCount('Brick 2 x 4'));

        // Count for non-existent part: 0
        $this->assertSame(0, $set->getMatchingPartCount('99999'));
    }

    public function testFilterBySpecificPartRanksCheapestPerTargetPieceFirst(): void
    {
        // Set A: Price 60 CHF, contains 10x of Part 3001 -> 6.00 CHF / part
        $suffix = bin2hex(random_bytes(3));
        $partNo = 'P' . $suffix;

        $setA = new Set();
        $setA->setNo('SETA-' . $suffix);
        $setA->setName('Set A');
        $setA->setPrice(60.0);
        $setA->setPriceCurrency('CHF');
        $setA->setSource(1);

        $pieceA = new Piece();
        $pieceA->setNo($partNo);
        $pieceA->setName('Target Test Brick');
        $pieceA->setColor(1);
        $pieceA->setCount(10);
        $pieceA->setCategory(1);
        $pieceA->setSet($setA);
        $setA->addPiece($pieceA);

        // Set B: Price 80 CHF, contains 40x of Part 3001 -> 2.00 CHF / part (BEST VALUE!)
        $setB = new Set();
        $setB->setNo('SETB-' . $suffix);
        $setB->setName('Set B');
        $setB->setPrice(80.0);
        $setB->setPriceCurrency('CHF');
        $setB->setSource(1);

        $pieceB = new Piece();
        $pieceB->setNo($partNo);
        $pieceB->setName('Target Test Brick');
        $pieceB->setColor(1);
        $pieceB->setCount(40);
        $pieceB->setCategory(1);
        $pieceB->setSet($setB);
        $setB->addPiece($pieceB);

        // Set C: Price 20 CHF, does NOT contain target part
        $setC = new Set();
        $setC->setNo('SETC-' . $suffix);
        $setC->setName('Set C');
        $setC->setPrice(20.0);
        $setC->setPriceCurrency('CHF');
        $setC->setSource(1);

        $pieceC = new Piece();
        $pieceC->setNo('OTHER-' . $suffix);
        $pieceC->setName('Other Brick');
        $pieceC->setColor(1);
        $pieceC->setCount(50);
        $pieceC->setCategory(1);
        $pieceC->setSet($setC);
        $setC->addPiece($pieceC);

        $this->em->persist($setA);
        $this->em->persist($pieceA);
        $this->em->persist($setB);
        $this->em->persist($pieceB);
        $this->em->persist($setC);
        $this->em->persist($pieceC);
        $this->em->flush();

        // Query filtering by target part
        $results = $this->setRepo->findMostValuableBy(['part' => $partNo], 10, null, 'CHF');

        // Verify Set C is excluded because it does not have the target part
        $setNos = array_map(fn(Set $s): ?string => $s->getNo(), $results);
        $this->assertNotContains('SETC-' . $suffix, $setNos);

        // Verify Set B is ranked BEFORE Set A because 80/40 = 2.00 CHF/pc < 60/10 = 6.00 CHF/pc
        $this->assertCount(2, $results);
        $this->assertSame('SETB-' . $suffix, $results[0]->getNo(), 'Set B provides best value per target part and must be ranked first.');
        $this->assertSame('SETA-' . $suffix, $results[1]->getNo(), 'Set A has higher cost per target part and must be second.');

        // Clean up
        $this->em->remove($pieceA);
        $this->em->remove($setA);
        $this->em->remove($pieceB);
        $this->em->remove($setB);
        $this->em->remove($pieceC);
        $this->em->remove($setC);
        $this->em->flush();
    }
}
