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

    public function testHistoricalPricesAndSavingsDelta(): void
    {
        $set = new Set();
        $set->setNo('10311-1');

        $priceLow = new \App\Entity\SetPrice($set, 4500, 'CHF', \App\Enum\Retailer::BRACK, \App\Enum\PriceSourceProvider::USER_BOOKMARKLET);
        $priceHigh = new \App\Entity\SetPrice($set, 6000, 'CHF', \App\Enum\Retailer::LEGO_STORE, \App\Enum\PriceSourceProvider::DIRECT_CRAWL);

        $set->addPrice($priceLow);
        $set->addPrice($priceHigh);

        $lowest = $set->getHistoricalLowestPrice('CHF');
        $highest = $set->getHistoricalHighestPrice('CHF');

        $this->assertSame($priceLow, $lowest);
        $this->assertSame($priceHigh, $highest);

        $delta = $set->getHistoricalSavingsDelta('CHF');
        $this->assertNotNull($delta);
        $this->assertSame(15.0, $delta['savingsAmount']);
        $this->assertEqualsWithDelta(25.0, $delta['savingsPercent'], 0.1);

        // Sorting by date
        $sortedDesc = $set->getPricesSortedByDate('DESC');
        $this->assertCount(2, $sortedDesc);
    }

    public function testRemovePriceResetsCachedPriceWhenEmpty(): void
    {
        $set = new Set();
        $set->setNo('42115-1');

        $price = new \App\Entity\SetPrice($set, 35000, 'CHF', \App\Enum\Retailer::GALAXUS, \App\Enum\PriceSourceProvider::USER_BOOKMARKLET);
        $set->addPrice($price);

        $this->assertSame(350.0, $set->getPrice());
        $this->assertSame('CHF', $set->getPriceCurrency());

        $set->removePrice($price);
        $this->assertNull($set->getPrice());
        $this->assertNull($set->getPriceCurrency());
    }

    public function testExcludeUsedPricesFromPriceCalculations(): void
    {
        $set = new Set();
        $set->setNo('75257-1');

        // Used price (cheaper, from BrickLink)
        $usedPrice = new \App\Entity\SetPrice(
            set: $set,
            amount: 7000, // 70.00 CHF
            currency: 'CHF',
            retailer: \App\Enum\Retailer::BRICKLINK,
            provider: \App\Enum\PriceSourceProvider::BRICKSET,
            itemCondition: 'used'
        );

        // New price (MSRP/Retailer, 120.00 CHF)
        $newPrice = new \App\Entity\SetPrice(
            set: $set,
            amount: 12000, // 120.00 CHF
            currency: 'CHF',
            retailer: \App\Enum\Retailer::GALAXUS,
            provider: \App\Enum\PriceSourceProvider::USER_BOOKMARKLET,
            itemCondition: 'new'
        );

        $set->addPrice($usedPrice);
        $set->addPrice($newPrice);

        // Default: includes used prices, so best price is the used price (70.00 CHF)
        $this->assertSame($usedPrice, $set->getBestPrice('CHF'));
        $this->assertSame($usedPrice, $set->getHistoricalLowestPrice('CHF'));
        $this->assertCount(2, $set->getPricesSortedByDate('DESC', excludeUsed: false));

        // When excludeUsed is true: skips used price and selects the new price (120.00 CHF)
        $this->assertSame($newPrice, $set->getBestPrice('CHF', excludeUsed: true));
        $this->assertSame($newPrice, $set->getHistoricalLowestPrice('CHF', excludeUsed: true));
        $filteredPrices = $set->getPricesSortedByDate('DESC', excludeUsed: true);
        $this->assertCount(1, $filteredPrices);
        $this->assertSame($newPrice, $filteredPrices[0]);
    }

    public function testSourceDefaultsNullHandlingAndUninitializedSafety(): void
    {
        $set = new Set();
        $this->assertSame(0, $set->getSource());
        $this->assertFalse($set->isRebrickable());
        $this->assertFalse($set->isBricklink());

        $set->setSource(Set::SOURCE_REBRICKABLE);
        $this->assertSame(Set::SOURCE_REBRICKABLE, $set->getSource());
        $this->assertTrue($set->isRebrickable());
        $this->assertFalse($set->isBricklink());

        $set->setSource(Set::SOURCE_BRICKLINK);
        $this->assertSame(Set::SOURCE_BRICKLINK, $set->getSource());
        $this->assertFalse($set->isRebrickable());
        $this->assertTrue($set->isBricklink());

        $set->setSource(null);
        $this->assertSame(0, $set->getSource());

        // Test uninitialized / unset property safety (as caused by reflection or lazy ghost hydration)
        $refProp = new \ReflectionProperty(Set::class, 'source');
        $refProp->setValue($set, null);
        $this->assertSame(0, $set->getSource());
        $this->assertFalse($set->isRebrickable());

        // Unset directly to verify null coalescing handles uninitialized state without FatalError
        $unsetter = \Closure::bind(function (Set $s): void {
            unset($s->source);
        }, null, Set::class);
        $unsetter($set);

        $this->assertSame(0, $set->getSource());
        $this->assertFalse($set->isRebrickable());
        $this->assertFalse($set->isBricklink());
    }
}
