<?php

namespace App\Tests\Entity;

use App\Entity\Piece;
use App\Entity\Set;
use PHPUnit\Framework\TestCase;

class SetTest extends TestCase
{
    public function testSetPropertiesAndPieceCount(): void
    {
        $set = new Set();
        $set->setNo('75192-1');
        $set->setName('Millennium Falcon');
        $set->setPrice(799.99);
        $set->setSource(Set::SOURCE_REBRICKABLE);
        $set->setObsolete(false);
        $set->setImageUrl('https://example.com/falcon.jpg');

        $year = new \DateTime('2017-01-01');
        $set->setYear($year);

        $piece1 = new Piece();
        $piece1->setNo('3001');
        $piece1->setName('Brick 2x4');
        $piece1->setCount(50);

        $piece2 = new Piece();
        $piece2->setNo('3003');
        $piece2->setName('Brick 2x2');
        $piece2->setCount(25);

        $set->addPiece($piece1);
        $set->addPiece($piece2);

        $this->assertSame('75192-1', $set->getNo());
        $this->assertSame('Millennium Falcon', $set->getName());
        $this->assertSame(799.99, $set->getPrice());
        $this->assertSame(Set::SOURCE_REBRICKABLE, $set->getSource());
        $this->assertFalse($set->getObsolete());
        $this->assertSame('https://example.com/falcon.jpg', $set->getImageUrl());
        $this->assertSame($year, $set->getYear());
        $this->assertCount(2, $set->getPieces());
        $this->assertSame(75, $set->getPieceCount());

        $set->removePiece($piece1);
        $this->assertCount(1, $set->getPieces());
        $this->assertSame(25, $set->getPieceCount());
    }
}
