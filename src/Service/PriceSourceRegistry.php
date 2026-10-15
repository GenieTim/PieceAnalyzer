<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Enum\PriceSourceProvider;
use App\Enum\Retailer;
use App\Service\Scraper\HeadlessScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Central registry and orchestrator for all price data sources:
 * - Brickset (API / Web MSRP in multi-currency: EUR, USD, GBP)
 * - BrickPicker (Secondary market / resale value in USD)
 * - Headless Browser Scrapers (Camoufox / Playwright for Toppreise, Galaxus, Brack, Amazon, LEGO.com)
 */
class PriceSourceRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BricksetPriceLoaderService $bricksetLoader,
        private readonly BrickPickerPriceLoaderService $brickPickerLoader,
        private readonly HeadlessScraperService $headlessScraper,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @return list<string>
     */
    public function getAvailableSources(): array
    {
        return ['brickset', 'brickpicker', 'scraper', 'all'];
    }

    /**
     * @return list<string>
     */
    public function getAvailableRetailers(): array
    {
        return array_map(static fn(Retailer $r): string => $r->value, Retailer::cases());
    }

    /**
     * Reload prices for a set from the requested source(s).
     *
     * @param list<string>|string $sources 'brickset', 'brickpicker', 'scraper', 'all'
     * @param list<Retailer>|null $retailers
     * @param array<string, mixed> $options
     * @return array{sourcesChecked: list<string>, pricesFound: int, errors: list<string>}
     */
    public function reloadPricesForSet(
        Set $set,
        array|string $sources = 'brickset',
        ?array $retailers = null,
        array $options = []
    ): array {
        $sourceList = is_array($sources) ? $sources : [$sources];
        if (in_array('all', $sourceList, true)) {
            $sourceList = ['brickset', 'brickpicker', 'scraper'];
        }

        $setNo = (string) $set->getNo();
        $pricesFound = 0;
        $sourcesChecked = [];
        $errors = [];

        // 1. Brickset (Primary multi-currency MSRP: EUR, USD, GBP)
        if (in_array('brickset', $sourceList, true)) {
            $sourcesChecked[] = 'brickset';
            try {
                $multiPrices = $this->bricksetLoader->loadPricesForSet($setNo);
                foreach ($multiPrices as $currCode => $val) {
                    $setPrice = new SetPrice(
                        set: $set,
                        amount: (int) round($val * 100),
                        currency: $currCode,
                        retailer: Retailer::LEGO_STORE,
                        provider: PriceSourceProvider::BRICKSET
                    );
                    $setPrice->setUrl('https://brickset.com/sets/' . urlencode($setNo));
                    $set->addPrice($setPrice);
                    $this->em->persist($setPrice);
                    $pricesFound++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Brickset reload error for set ' . $setNo, ['error' => $e->getMessage()]);
                $errors[] = 'Brickset: ' . $e->getMessage();
            }
        }

        // 2. BrickPicker (Secondary Market / Collector Value: USD)
        if (in_array('brickpicker', $sourceList, true)) {
            $sourcesChecked[] = 'brickpicker';
            if ($this->brickPickerLoader->isAvailable('BrickPicker')) {
                try {
                    $price = $this->brickPickerLoader->loadPriceForSet($setNo);
                    if ($price !== null && $price > 0) {
                        $setPrice = new SetPrice(
                            set: $set,
                            amount: (int) round($price * 100),
                            currency: 'USD',
                            retailer: Retailer::OTHER,
                            provider: PriceSourceProvider::BRICKPICKER
                        );
                        $setPrice->setUrl('https://www.brickpicker.com/bpms/set.cfm?set=' . urlencode($setNo));
                        $set->addPrice($setPrice);
                        $this->em->persist($setPrice);
                        $pricesFound++;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('BrickPicker reload error for set ' . $setNo, ['error' => $e->getMessage()]);
                    $errors[] = 'BrickPicker: ' . $e->getMessage();
                }
            }
        }

        // 3. Headless Scraper (Camoufox / Playwright for Galaxus, Brack, Amazon, Toppreise, LEGO Store)
        if (in_array('scraper', $sourceList, true)) {
            $sourcesChecked[] = 'scraper';
            $targetRetailers = $retailers ?? [
                Retailer::GALAXUS,
                Retailer::BRACK,
                Retailer::AMAZON_DE,
                Retailer::OTHER, // Toppreise
            ];

            try {
                $items = $this->headlessScraper->scrapeSet($set, $targetRetailers, $options);
                if ($items !== []) {
                    $ingestResult = $this->headlessScraper->ingestExtractedItems($items);
                    $pricesFound += $ingestResult['imported'];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Headless scraper error for set ' . $setNo, ['error' => $e->getMessage()]);
                $errors[] = 'Scraper: ' . $e->getMessage();
            }
        }

        if ($pricesFound > 0) {
            $set->updateCachedBestPrice($this->exchangeRateService);
            $this->em->persist($set);
        }

        return [
            'sourcesChecked' => $sourcesChecked,
            'pricesFound' => $pricesFound,
            'errors' => $errors,
        ];
    }
}
