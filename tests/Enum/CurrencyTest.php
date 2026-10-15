<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Currency;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    public function testSymbolsAndMinorUnits(): void
    {
        $this->assertSame('CHF ', Currency::CHF->symbol());
        $this->assertSame('Rp.', Currency::CHF->minorUnit());
        $this->assertSame('Swiss Franc (CHF)', Currency::CHF->label());

        $this->assertSame('€', Currency::EUR->symbol());
        $this->assertSame('ct', Currency::EUR->minorUnit());
        $this->assertSame('Euro (EUR)', Currency::EUR->label());

        $this->assertSame('$', Currency::USD->symbol());
        $this->assertSame('¢', Currency::USD->minorUnit());
        $this->assertSame('US Dollar (USD)', Currency::USD->label());

        $this->assertSame('£', Currency::GBP->symbol());
        $this->assertSame('p', Currency::GBP->minorUnit());
        $this->assertSame('British Pound (GBP)', Currency::GBP->label());
    }

    public function testValuesList(): void
    {
        $this->assertSame(['CHF', 'EUR', 'USD', 'GBP'], Currency::values());
    }
}
