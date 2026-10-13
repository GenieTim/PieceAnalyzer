<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\PriceSourceProvider;

class FetchSetPriceMessage
{
    public function __construct(
        public readonly int $setId,
        public readonly ?PriceSourceProvider $provider = null
    ) {
    }
}
