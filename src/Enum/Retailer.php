<?php

declare(strict_types=1);

namespace App\Enum;

enum Retailer: string
{
    case GALAXUS = 'galaxus';
    case BRACK = 'brack';
    case AMAZON_DE = 'amazon_de';
    case AMAZON_COM = 'amazon_com';
    case LEGO_STORE = 'lego_store';
    case BRICKLINK = 'bricklink';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::GALAXUS => 'Galaxus.ch',
            self::BRACK => 'Brack.ch',
            self::AMAZON_DE => 'Amazon.de',
            self::AMAZON_COM => 'Amazon.com',
            self::LEGO_STORE => 'LEGO Store (MSRP)',
            self::BRICKLINK => 'BrickLink',
            self::OTHER => 'Other Merchant',
        };
    }

    public function defaultCurrency(): string
    {
        return match ($this) {
            self::GALAXUS, self::BRACK => 'CHF',
            self::AMAZON_DE => 'EUR',
            self::AMAZON_COM => 'USD',
            self::LEGO_STORE, self::BRICKLINK, self::OTHER => 'CHF',
        };
    }

    public function searchUrl(string $setNo): string
    {
        $cleanNo = preg_replace('/-\d+$/', '', trim($setNo)) ?? trim($setNo);
        $fullNo = str_contains(trim($setNo), '-') ? trim($setNo) : trim($setNo) . '-1';
        return match ($this) {
            self::GALAXUS => 'https://www.galaxus.ch/en/search?q=lego+' . urlencode($cleanNo),
            self::BRACK => 'https://www.brack.ch/search?query=lego+' . urlencode($cleanNo),
            self::AMAZON_DE => 'https://www.amazon.de/s?k=lego+' . urlencode($cleanNo),
            self::AMAZON_COM => 'https://www.amazon.com/s?k=lego+' . urlencode($cleanNo),
            self::LEGO_STORE => 'https://www.lego.com/search?q=' . urlencode($cleanNo),
            self::BRICKLINK => 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . urlencode($fullNo),
            self::OTHER => 'https://www.google.com/search?q=lego+' . urlencode($cleanNo),
        };
    }
}
