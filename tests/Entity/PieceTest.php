<?php

namespace App\Tests\Entity;

use App\Entity\Piece;
use App\Entity\Set;
use PHPUnit\Framework\TestCase;

class PieceTest extends TestCase
{
    public function testPieceProperties(): void
    {
        $piece = new Piece();
        $set = new Set();
        $set->setName('Test Set');

        $piece->setNo('3001');
        $piece->setName('Brick 2x4');
        $piece->setCategory(11);
        $piece->setColor(1);
        $piece->setType('PART');
        $piece->setCount(42);
        $piece->setSet($set);

        $this->assertSame('3001', $piece->getNo());
        $this->assertSame('Brick 2x4', $piece->getName());
        $this->assertSame(11, $piece->getCategory());
        $this->assertSame(1, $piece->getColor());
        $this->assertSame('PART', $piece->getType());
        $this->assertSame(42, $piece->getCount());
        $this->assertSame($set, $piece->getSet());
    }
}
