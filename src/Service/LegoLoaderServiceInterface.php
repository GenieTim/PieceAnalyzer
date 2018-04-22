<?php

namespace App\Service;

use App\Entity\Set;

/**
 * LegoLoaderServiceInterface
 *
 * @author timbernhard
 */
interface LegoLoaderServiceInterface {
    
    public function loadSets($from, $to);
    
    public function loadSet($set_no, $flush = true);
    
    public function getPiecesOfSet(Set &$set, $flush = false);
    
    public function loadPrices($all = false);
    
    public function getColors();
    
    public function getCategories();
    
}
