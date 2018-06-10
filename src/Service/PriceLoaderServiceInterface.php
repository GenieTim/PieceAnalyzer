<?php

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

/**
 *
 * @author timbernhard
 */
interface PriceLoaderServiceInterface
{
    public function loadPrices($all = false);
    
    public function loadPriceForSet($set_no);
}
