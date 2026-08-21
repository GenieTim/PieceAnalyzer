<?php

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Entity\Set;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Description of BrickPickerPriceLoaderService
 *
 * @author timbernhard
 */
class BrickPickerPriceLoaderService implements PriceLoaderServiceInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {
    }

    public function loadPriceForSet(mixed $set_no): ?float
    {
        $set_no = (string) $set_no;
        $src = "https://www.brickpicker.com/bpms/set.cfm?set=$set_no";

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
            ]);
            $html = @file_get_contents($src, false, $context);
            if ($html === false) {
                return null;
            }

            $crawler = new Crawler($html);
            $priceList = $crawler->filter(".product-detail .retail-price ul li");
            $american = null;
            if (count($priceList) > 0) {
                $american = $priceList->first()->text();
            }

            $price = $this->findPrice($american);
            if ($price !== null) {
                return $price;
            }

            $this->logger->info('no price found in ' . ($american ?? 'empty') . ' from ' . $src . '. Checking current price...');
            return $this->loadCurrentPrice($crawler);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load price from BrickPicker for set ' . $set_no, ['error' => $e]);
            return null;
        }
    }

    /**
     * Extract a float value (price) from a string
     */
    protected function findPrice(?string $string): ?float
    {
        if ($string === null) {
            return null;
        }

        $matches = [];
        if (\preg_match('/\d+(\.\d+)?/', $string, $matches)) {
            return (float) $matches[0];
        }
        return null;
    }

    /**
     * Load the price listed, not as retail price
     */
    protected function loadCurrentPrice(Crawler $crawler): ?float
    {
        $priceList = $crawler->filter('.main .container .panel-body table tbody tr td strong');
        if (count($priceList) > 0) {
            return $this->findPrice($priceList->first()->text());
        }
        return null;
    }

    public function loadPrices(bool $all = false): static
    {
        $query = 'SELECT s FROM ' . Set::class . ' s';
        if (!$all) {
            $query .= ' WHERE s.price IS NULL';
        }
        $q = $this->em->createQuery($query);
        $batchSize = 50;
        $i = 0;
        $unsolved_sets = $q->toIterable();
        foreach ($unsolved_sets as $row) {
            $set = is_array($row) ? $row[0] : $row;
            if ($set instanceof Set) {
                try {
                    $set->setPrice($this->loadPriceForSet($set->getNo()));
                    $this->em->persist($set);
                } catch (\Throwable $e) {
                    $this->logger->warning('error while loading price', ['error' => $e]);
                }
            }
            if (($i % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
            ++$i;
        }
        $this->em->flush();
        return $this;
    }
}
