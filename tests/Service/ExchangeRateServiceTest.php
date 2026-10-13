<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ExchangeRateService;
use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ExchangeRateServiceTest extends TestCase
{
    public function testFallbackRatesAndConversion(): void
    {
        $service = new ExchangeRateService();

        // 1 EUR to EUR
        $this->assertSame(1.0, $service->getRate('EUR', 'EUR'));

        // Conversion
        $this->assertSame(96.0, $service->convert(100.0, 'EUR', 'CHF'));
        $this->assertSame(108.0, $service->convert(100.0, 'EUR', 'USD'));

        // Inverse conversion (CHF to EUR: 1 / 0.96 ≈ 1.0416)
        $chfToEur = $service->convert(96.0, 'CHF', 'EUR');
        $this->assertEqualsWithDelta(100.0, $chfToEur, 0.1);
    }

    public function testConvertMoney(): void
    {
        $service = new ExchangeRateService();

        // 100.00 EUR (10000 cents) -> CHF
        $moneyEur = new Money(10000, new Currency('EUR'));
        $moneyChf = $service->convertMoney($moneyEur, 'CHF');

        $this->assertSame('CHF', $moneyChf->getCurrency()->getCode());
        $this->assertSame('9600', $moneyChf->getAmount());
    }

    public function testLiveFetchFromFrankfurterApi(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'rates' => [
                'CHF' => 0.9450,
                'USD' => 1.0920,
                'GBP' => 0.8550,
            ],
        ]);

        $http->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('frankfurter.dev'))
            ->willReturn($response);

        $service = new ExchangeRateService($http);
        $rates = $service->fetchLatestRates();

        $this->assertSame(0.9450, $rates['CHF']);
        $this->assertSame(1.0920, $rates['USD']);
        $this->assertSame(0.8550, $rates['GBP']);
        $this->assertSame(1.0, $rates['EUR']);
    }
}
