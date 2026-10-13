<?php

declare(strict_types=1);

namespace App\Service;

use Money\Currency;
use Money\Money;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRateService
{
    public const BASE_CURRENCY = 'EUR';

    /**
     * Fallback exchange rates against base currency EUR.
     *
     * @var array<string, float>
     */
    private const array FALLBACK_RATES = [
        'EUR' => 1.0,
        'CHF' => 0.96,
        'USD' => 1.08,
        'GBP' => 0.86,
    ];

    /**
     * @var array<string, float>
     */
    private array $rates = self::FALLBACK_RATES;

    public function __construct(private readonly ?HttpClientInterface $httpClient = null, private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Refresh exchange rates from external API (Frankfurter API / ECB).
     *
     * @return array<string, float>
     */
    public function fetchLatestRates(): array
    {
        if (!$this->httpClient instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
            return $this->rates;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                'https://api.frankfurter.dev/v1/latest?base=EUR&symbols=CHF,USD,GBP',
                ['timeout' => 5.0]
            );

            if ($response->getStatusCode() === 200) {
                /** @var array{rates?: array<string, float>} $data */
                $data = $response->toArray();
                if (isset($data['rates']) && is_array($data['rates'])) {
                    $this->rates['EUR'] = 1.0;
                    foreach ($data['rates'] as $curr => $rate) {
                        $code = strtoupper(trim((string) $curr));
                        if (is_numeric($rate) && in_array($code, ['CHF', 'USD', 'GBP'], true)) {
                            $this->rates[$code] = (float) $rate;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch live exchange rates, using fallback: ' . $e->getMessage());
        }

        return $this->rates;
    }

    /**
     * @return array<string, float>
     */
    public function getRates(): array
    {
        return $this->rates;
    }

    /**
     * Calculate exchange rate from one currency to another.
     */
    public function getRate(string $fromCurrency, string $toCurrency): float
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return 1.0;
        }

        $fromEurRate = $this->rates[$from] ?? (self::FALLBACK_RATES[$from] ?? 1.0);
        $toEurRate = $this->rates[$to] ?? (self::FALLBACK_RATES[$to] ?? 1.0);

        if ($fromEurRate <= 0.0) {
            return 1.0;
        }

        // e.g. 1 EUR = 0.96 CHF, 1 EUR = 1.08 USD
        // 1 CHF = (1.08 / 0.96) USD
        return $toEurRate / $fromEurRate;
    }

    /**
     * Convert a decimal amount from one currency to another.
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        $rate = $this->getRate($fromCurrency, $toCurrency);
        return round($amount * $rate, 2);
    }

    /**
     * Convert a Money object into another currency.
     */
    public function convertMoney(Money $money, string $toCurrency): Money
    {
        $fromCode = $money->getCurrency()->getCode();
        $toCode = strtoupper(trim($toCurrency));
        if ($toCode === '') {
            $toCode = self::BASE_CURRENCY;
        }

        if ($fromCode === $toCode) {
            return $money;
        }

        $rate = $this->getRate($fromCode, $toCode);
        $convertedMinor = (int) round(((float) $money->getAmount()) * $rate);

        assert($toCode !== '');
        return new Money($convertedMinor, new Currency($toCode));
    }
}
