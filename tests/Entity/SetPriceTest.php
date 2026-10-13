<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Enum\PriceSourceProvider;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;

class SetPriceTest extends TestCase
{
    public function testSetPricePropertiesAndMoney(): void
    {
        $set = new Set();
        $set->setNo('75192');

        $price = new SetPrice(
            set: $set,
            amount: 68900,
            currency: 'CHF',
            retailer: Retailer::GALAXUS,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );
        $price->setUrl('https://www.galaxus.ch/en/s1/product/lego-75192');

        $this->assertSame($set, $price->getSet());
        $this->assertSame(68900, $price->getAmount());
        $this->assertSame('CHF', $price->getCurrency());
        $this->assertSame(Retailer::GALAXUS, $price->getRetailer());
        $this->assertSame(PriceSourceProvider::USER_BOOKMARKLET, $price->getProvider());
        $this->assertSame(PriceVerificationStatus::VERIFIED, $price->getStatus());
        $this->assertSame(689.0, $price->getDecimalPrice());
        $this->assertSame('CHF 689.00', $price->getFormattedPrice());
        $this->assertSame('https://www.galaxus.ch/en/s1/product/lego-75192', $price->getUrl());

        // Test MoneyPHP integration
        $money = $price->getMoney();
        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame('68900', $money->getAmount());
        $this->assertSame('CHF', $money->getCurrency()->getCode());

        $newMoney = new Money(59950, new Currency('EUR'));
        $price->setMoney($newMoney);
        $this->assertSame(59950, $price->getAmount());
        $this->assertSame('EUR', $price->getCurrency());
        $this->assertSame(599.50, $price->getDecimalPrice());
    }

    public function testSetMultiPriceTrackingAndBestPrice(): void
    {
        $set = new Set();
        $set->setNo('10311');

        $galaxusPrice = new SetPrice(
            set: $set,
            amount: 4990,
            currency: 'CHF',
            retailer: Retailer::GALAXUS,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );

        $brackPrice = new SetPrice(
            set: $set,
            amount: 4490,
            currency: 'CHF',
            retailer: Retailer::BRACK,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );

        $amazonPrice = new SetPrice(
            set: $set,
            amount: 3999,
            currency: 'EUR',
            retailer: Retailer::AMAZON_DE,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );

        $set->addPrice($galaxusPrice);
        $set->addPrice($brackPrice);
        $set->addPrice($amazonPrice);

        $this->assertCount(3, $set->getPrices());

        // Test best price in CHF (should be Brack at 44.90)
        $bestChf = $set->getBestPrice('CHF');
        $this->assertNotNull($bestChf);
        $this->assertSame(Retailer::BRACK, $bestChf->getRetailer());
        $this->assertSame(44.90, $bestChf->getDecimalPrice());

        // Test latest price for specific retailer
        $latestBrack = $set->getLatestPriceFor(Retailer::BRACK);
        $this->assertNotNull($latestBrack);
        $this->assertSame(44.90, $latestBrack->getDecimalPrice());

        // Best overall price (39.99 EUR is lowest numeric decimal)
        $bestOverall = $set->getBestPrice();
        $this->assertNotNull($bestOverall);
        $this->assertSame(Retailer::AMAZON_DE, $bestOverall->getRetailer());

        // Cached price on set should be updated
        $this->assertSame(39.99, $set->getPrice());
        $this->assertSame('EUR', $set->getPriceCurrency());
    }
}
