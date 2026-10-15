<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\PriceSourceProvider;
use App\Enum\Retailer;

class FetchSetPriceMessage
{
    public function __construct(
        public readonly int $setId,
        public readonly ?PriceSourceProvider $provider = null,
        public readonly ?Retailer $retailer = null
    ) {
    }
}
