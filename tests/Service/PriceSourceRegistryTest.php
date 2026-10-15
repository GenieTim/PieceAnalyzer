<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Set;
use App\Service\BrickPickerPriceLoaderService;
use App\Service\BricksetPriceLoaderService;
use App\Service\ExchangeRateService;
use App\Service\PriceSourceRegistry;
use App\Service\Scraper\HeadlessScraperService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PriceSourceRegistryTest extends TestCase
{
    public function testAvailableSourcesAndRetailers(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $brickset = $this->createMock(BricksetPriceLoaderService::class);
        $brickpicker = $this->createMock(BrickPickerPriceLoaderService::class);
        $scraper = $this->createMock(HeadlessScraperService::class);
        $rates = new ExchangeRateService();

        $registry = new PriceSourceRegistry(
            em: $em,
            bricksetLoader: $brickset,
            brickPickerLoader: $brickpicker,
            headlessScraper: $scraper,
            exchangeRateService: $rates,
            logger: new NullLogger()
        );

        $sources = $registry->getAvailableSources();
        $this->assertContains('brickset', $sources);
        $this->assertContains('brickpicker', $sources);
        $this->assertContains('scraper', $sources);
        $this->assertContains('all', $sources);

        $retailers = $registry->getAvailableRetailers();
        $this->assertContains('galaxus', $retailers);
        $this->assertContains('brack', $retailers);
        $this->assertContains('amazon_de', $retailers);
        $this->assertContains('lego_store', $retailers);
    }

    public function testReloadPricesForSetDispatchesSources(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $brickset = $this->createMock(BricksetPriceLoaderService::class);
        $brickpicker = $this->createMock(BrickPickerPriceLoaderService::class);
        $scraper = $this->createMock(HeadlessScraperService::class);
        $rates = new ExchangeRateService();

        $set = new Set();
        $set->setNo('10295');

        $brickset->expects($this->once())
            ->method('loadPricesForSet')
            ->with('10295')
            ->willReturn(['EUR' => 169.99, 'USD' => 179.99]);

        $brickpicker->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);
        $brickpicker->expects($this->once())
            ->method('loadPriceForSet')
            ->with('10295')
            ->willReturn(155.0);

        $scraper->expects($this->once())
            ->method('scrapeSet')
            ->willReturn([
                [
                    'setNo' => '10295',
                    'amountCents' => 14900,
                    'price' => 149.00,
                    'currency' => 'CHF',
                    'retailer' => 'galaxus',
                    'provider' => 'bookmarklet',
                    'url' => 'https://www.galaxus.ch/de/product/10295',
                ],
            ]);

        $scraper->expects($this->once())
            ->method('ingestExtractedItems')
            ->willReturn(['total' => 1, 'imported' => 1, 'skipped' => 0, 'errors' => []]);

        $em->expects($this->atLeastOnce())
            ->method('persist');

        $registry = new PriceSourceRegistry(
            em: $em,
            bricksetLoader: $brickset,
            brickPickerLoader: $brickpicker,
            headlessScraper: $scraper,
            exchangeRateService: $rates,
            logger: new NullLogger()
        );

        $result = $registry->reloadPricesForSet($set, 'all');

        $this->assertSame(['brickset', 'brickpicker', 'scraper'], $result['sourcesChecked']);
        $this->assertGreaterThanOrEqual(3, $result['pricesFound']);
        $this->assertEmpty($result['errors']);
    }
}
