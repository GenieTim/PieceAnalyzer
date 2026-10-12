<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

/**
 * @author timbernhard
 */
interface PriceLoaderServiceInterface
{
    public function loadPrices(bool $all = false): static;

    public function loadPriceForSet(mixed $set_no): ?float;
}
