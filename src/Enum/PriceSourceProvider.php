<?php

declare(strict_types=1);

namespace App\Enum;

enum PriceSourceProvider: string
{
    case TOPPREISE = 'toppreise';
    case BRICKSET = 'brickset';
    case BRICKPICKER = 'brickpicker';
    case USER_BOOKMARKLET = 'bookmarklet';
    case DIRECT_CRAWL = 'direct_crawl';
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::TOPPREISE => 'Toppreise.ch',
            self::BRICKSET => 'Brickset',
            self::BRICKPICKER => 'BrickPicker',
            self::USER_BOOKMARKLET => 'Bookmarklet (User Report)',
            self::DIRECT_CRAWL => 'Direct Store Fetch',
            self::MANUAL => 'Manual Entry',
        };
    }
}
