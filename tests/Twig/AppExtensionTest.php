<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\ExchangeRateService;
use App\Service\UserPreferencesService;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;

class AppExtensionTest extends TestCase
{
    public function testGetUserCurrency(): void
    {
        $prefs = $this->createMock(UserPreferencesService::class);
        $prefs->method('getCurrency')->willReturn('EUR');

        $rates = $this->createMock(ExchangeRateService::class);

        $extension = new AppExtension($prefs, $rates);
        $this->assertSame('EUR', $extension->getUserCurrency());
    }

    public function testGetCurrencySymbol(): void
    {
        $prefs = $this->createMock(UserPreferencesService::class);
        $prefs->method('getCurrency')->willReturn('CHF');

        $rates = $this->createMock(ExchangeRateService::class);

        $extension = new AppExtension($prefs, $rates);

        $this->assertSame('CHF ', $extension->getCurrencySymbol('CHF'));
        $this->assertSame('€', $extension->getCurrencySymbol('EUR'));
        $this->assertSame('$', $extension->getCurrencySymbol('USD'));
        $this->assertSame('£', $extension->getCurrencySymbol('GBP'));
        // fallback to default from preferences
        $this->assertSame('CHF ', $extension->getCurrencySymbol());
    }

    public function testConvertPrice(): void
    {
        $prefs = $this->createMock(UserPreferencesService::class);
        $prefs->method('getCurrency')->willReturn('CHF');

        $rates = $this->createMock(ExchangeRateService::class);
        $rates->method('convert')->with(100.0, 'USD', 'CHF')->willReturn(90.50);

        $extension = new AppExtension($prefs, $rates);

        $this->assertNull($extension->convertPrice(null));
        $this->assertNull($extension->convertPrice(0.0));
        // Same currency returns direct rounded amount without rate conversion
        $this->assertSame(50.0, $extension->convertPrice(50.0, 'CHF', 'CHF'));
        // Different currency calls exchangeRateService
        $this->assertSame(90.5, $extension->convertPrice(100.0, 'USD', 'CHF'));
    }
}
