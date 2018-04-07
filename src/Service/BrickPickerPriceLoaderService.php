<?php

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Service\PriceLoaderServiceInterface;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Set;

/**
 * Description of BrickPickerPriceLoaderService
 *
 * @author timbernhard
 */
class BrickPickerPriceLoaderService implements PriceLoaderServiceInterface {

    private $em;
    private $logger;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger) {
        $this->logger = $logger;
        $this->em = $em;
    }

    public function loadPriceForSet($set_no) {
        $src = "https://www.brickpicker.com/bpms/set.cfm?set=$set_no";
        $crawler = new Crawler(file_get_contents($src));
        $priceList = $crawler->filter(".product-detail .retail-price ul li");
        $american = $priceList->first()->text();
        if (($price = $this->findPrice($american))) {
            return $price;
        } else {
            $this->logger->info('no price found in ' . $american . ' from ' . $src . '. Checking current price...');
            return $this->loadCurrentPrice($crawler);
        }
    }
    
    /**
     * extract a float value (price) from a string
     * 
     * @param string $string
     * @return float|NULL
     */
    protected function findPrice($string) {
        // TODO: this function only checks for price in america
        $matches = array();
        if (\preg_match('/\d+\.?\d*/', $string, $matches)) {
            return $matches[0];
        }
        return NULL;
    }
    
    /**
     * Load the price listed, not as retail price 
     * 
     * @param Crawler $crawler
     * @return float|NULL
     */
    protected function loadCurrentPrice(Crawler $crawler) {
        $priceList = $crawler->filter('.main .container .panel-body table tbody tr td strong');
        return $this->findPrice($priceList->first()->text());
    }

    public function loadPrices($all = FALSE) {
        $query = 'SELECT s FROM ' . Set::class . ' s';
        if (!$all) {
            $query .= ' WHERE s.price IS NULL';
        }
        $q = $this->em->createQuery($query);
        $batchSize = 50;
        $i = 0;
        $unsolved_sets = $q->iterate();
        foreach ($unsolved_sets as $row) {
            $set = $row[0];
            try {
                $set->setPrice($this->loadPriceForSet($set->getNo()));
                $this->em->persist($set);
            } catch (\Exception $e) {
                $this->logger->warn('error while loading price', array('error' => $e));
            }
            if (($i % $batchSize) === 0) {
                $this->em->flush(); // Executes all updates.
                $this->em->clear(); // Detaches all objects from Doctrine!
            }
            ++$i;
        }
        $this->em->flush();
        return $this;
    }

}
