<?php

namespace App\Tests\Entity;

use App\Entity\Item;
use PHPUnit\Framework\TestCase;

class ItemTest extends TestCase
{
    public function testItemProperties(): void
    {
        $item = new Item();
        $item->setId(10);
        $item->setNo('ITEM-123');
        $item->setName('Sample Item');

        $this->assertSame(10, $item->getId());
        $this->assertSame('ITEM-123', $item->getNo());
        $this->assertSame('Sample Item', $item->getName());
    }
}
