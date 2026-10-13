<?php

declare(strict_types=1);

namespace App\Enum;

enum PriceVerificationStatus: string
{
    case VERIFIED = 'verified';
    case UNVERIFIED = 'reported_unverified';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::VERIFIED => 'Verified',
            self::UNVERIFIED => 'Community Report (Unverified)',
            self::REJECTED => 'Rejected',
        };
    }
}
