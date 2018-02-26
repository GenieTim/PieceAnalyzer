<?php

namespace App\Service;

/**
 * LegoLoaderServiceInterface
 *
 * @author timbernhard
 */
interface LegoLoaderServiceInterface {
    
    public function loadSets($from, $to);
    
    public function loadSet($set_no, $flush = true);
    
    public function getPiecesOfSet($set_no, $force_load = false);
    
    public function loadPrices($all = false);
    
    public function getColors();
    
    public function getCategories();
    
}
