<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Retailer;
use PHPUnit\Framework\TestCase;

class RetailerTest extends TestCase
{
    public function testLabelsAndDefaultCurrencies(): void
    {
        $this->assertSame('Galaxus.ch', Retailer::GALAXUS->label());
        $this->assertSame('CHF', Retailer::GALAXUS->defaultCurrency());

        $this->assertSame('Brack.ch', Retailer::BRACK->label());
        $this->assertSame('CHF', Retailer::BRACK->defaultCurrency());

        $this->assertSame('Amazon.de', Retailer::AMAZON_DE->label());
        $this->assertSame('EUR', Retailer::AMAZON_DE->defaultCurrency());

        $this->assertSame('Amazon.com', Retailer::AMAZON_COM->label());
        $this->assertSame('USD', Retailer::AMAZON_COM->defaultCurrency());

        $this->assertSame('LEGO Store (MSRP)', Retailer::LEGO_STORE->label());
        $this->assertSame('CHF', Retailer::LEGO_STORE->defaultCurrency());

        $this->assertSame('BrickLink', Retailer::BRICKLINK->label());
        $this->assertSame('CHF', Retailer::BRICKLINK->defaultCurrency());

        $this->assertSame('Other Merchant', Retailer::OTHER->label());
        $this->assertSame('CHF', Retailer::OTHER->defaultCurrency());
    }

    public function testSearchUrlGeneration(): void
    {
        $setNo = '75192-1';

        $this->assertSame('https://www.galaxus.ch/en/search?q=lego+75192', Retailer::GALAXUS->searchUrl($setNo));
        $this->assertSame('https://www.brack.ch/search?query=lego+75192', Retailer::BRACK->searchUrl($setNo));
        $this->assertSame('https://www.amazon.de/s?k=lego+75192', Retailer::AMAZON_DE->searchUrl($setNo));
        $this->assertSame('https://www.amazon.com/s?k=lego+75192', Retailer::AMAZON_COM->searchUrl($setNo));
        $this->assertSame('https://www.lego.com/search?q=75192', Retailer::LEGO_STORE->searchUrl($setNo));
        $this->assertSame('https://www.bricklink.com/v2/catalog/catalogitem.page?S=75192-1', Retailer::BRICKLINK->searchUrl($setNo));
        $this->assertSame('https://www.google.com/search?q=lego+75192', Retailer::OTHER->searchUrl($setNo));

        // When setNo has no suffix, bricklink appends -1
        $this->assertSame('https://www.bricklink.com/v2/catalog/catalogitem.page?S=10311-1', Retailer::BRICKLINK->searchUrl('10311'));
    }
}
