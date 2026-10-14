<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Currency;
use App\Service\ExchangeRateService;
use App\Service\UserPreferencesService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserPreferencesService $preferencesService,
        private readonly ExchangeRateService $exchangeRateService
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_currency', $this->getUserCurrency(...)),
            new TwigFunction('currency_symbol', $this->getCurrencySymbol(...)),
            new TwigFunction('minor_unit', $this->getMinorUnit(...)),
            new TwigFunction('convert_price', $this->convertPrice(...)),
            new TwigFunction('supported_currencies', $this->getSupportedCurrencies(...)),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('convert_currency', $this->convertPrice(...)),
            new TwigFilter('currency_symbol', $this->getCurrencySymbol(...)),
            new TwigFilter('minor_unit', $this->getMinorUnit(...)),
        ];
    }

    public function getUserCurrency(): string
    {
        return $this->preferencesService->getCurrency();
    }

    public function getCurrencySymbol(?string $currency = null): string
    {
        $curr = $currency !== null && $currency !== '' ? strtoupper(trim($currency)) : $this->getUserCurrency();
        $case = Currency::tryFrom($curr);

        return $case?->symbol() ?? ($curr . ' ');
    }

    public function getMinorUnit(?string $currency = null): string
    {
        $curr = $currency !== null && $currency !== '' ? strtoupper(trim($currency)) : $this->getUserCurrency();
        $case = Currency::tryFrom($curr);

        return $case?->minorUnit() ?? '¢';
    }

    /**
     * @return Currency[]
     */
    public function getSupportedCurrencies(): array
    {
        return Currency::cases();
    }

    public function convertPrice(?float $amount, ?string $fromCurrency = null, ?string $toCurrency = null): ?float
    {
        if ($amount === null || $amount <= 0.0) {
            return null;
        }

        $target = $toCurrency !== null && $toCurrency !== '' ? strtoupper(trim($toCurrency)) : $this->getUserCurrency();
        $source = $fromCurrency !== null && $fromCurrency !== '' ? strtoupper(trim($fromCurrency)) : 'CHF';

        if ($source === $target) {
            return round($amount, 2);
        }

        return round($this->exchangeRateService->convert($amount, $source, $target), 2);
    }
}
