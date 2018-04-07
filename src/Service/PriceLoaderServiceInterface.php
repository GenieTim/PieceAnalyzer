<?php

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

/**
 *
 * @author timbernhard
 */
interface PriceLoaderServiceInterface {
    public function loadPrices($all = FALSE);
    
    public function loadPriceForSet($set_no);
}
