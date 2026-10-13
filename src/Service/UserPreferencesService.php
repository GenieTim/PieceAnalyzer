<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Retailer;
use Symfony\Component\HttpFoundation\RequestStack;

class UserPreferencesService
{
    public const SESSION_CURRENCY = 'pref_currency';
    public const SESSION_RETAILERS = 'pref_retailers';
    public const DEFAULT_CURRENCY = 'CHF';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getCurrency(): string
    {
        $session = $this->requestStack->getSession();
        $currency = (string) $session->get(self::SESSION_CURRENCY, self::DEFAULT_CURRENCY);
        return in_array($currency, ['CHF', 'EUR', 'USD', 'GBP'], true) ? $currency : self::DEFAULT_CURRENCY;
    }

    public function setCurrency(string $currency): void
    {
        $curr = strtoupper(trim($currency));
        if (in_array($curr, ['CHF', 'EUR', 'USD', 'GBP'], true)) {
            $session = $this->requestStack->getSession();
            $session->set(self::SESSION_CURRENCY, $curr);
        }
    }

    /**
     * @return string[]
     */
    public function getPreferredRetailers(): array
    {
        $session = $this->requestStack->getSession();
        $retailers = $session->get(self::SESSION_RETAILERS, []);
        return is_array($retailers) ? $retailers : [];
    }

    /**
     * @param string[] $retailers
     */
    public function setPreferredRetailers(array $retailers): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_RETAILERS, $retailers);
    }
}
