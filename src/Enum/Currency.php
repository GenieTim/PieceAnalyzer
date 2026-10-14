<?php

declare(strict_types=1);

namespace App\Enum;

enum Currency: string
{
    case CHF = 'CHF';
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';

    public function symbol(): string
    {
        return match ($this) {
            self::CHF => 'CHF ',
            self::EUR => '€',
            self::GBP => '£',
            self::USD => '$',
        };
    }

    public function minorUnit(): string
    {
        return match ($this) {
            self::CHF => 'Rp.',
            self::EUR => 'ct',
            self::GBP => 'p',
            self::USD => '¢',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CHF => 'Swiss Franc (CHF)',
            self::EUR => 'Euro (EUR)',
            self::USD => 'US Dollar (USD)',
            self::GBP => 'British Pound (GBP)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
