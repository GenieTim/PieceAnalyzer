<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Enum\PriceSourceProvider;
use App\Enum\Retailer;
use App\Repository\SetRepository;
use App\Service\ExchangeRateService;
use App\Service\ProviderResilienceManager;
use App\Service\Scraper\HeadlessScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HeadlessScraperServiceTest extends TestCase
{
    public function testIngestExtractedItemsCreatesAndUpdatesPrices(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $setRepo = $this->createMock(SetRepository::class);
        $setPriceRepo = $this->createMock(EntityRepository::class);
        $exchangeRateService = new ExchangeRateService();
        $resilienceManager = new ProviderResilienceManager();

        $set = new Set();
        $set->setNo('40809-1');
        $set->setName('Gingerbread House');

        $setRepo->expects($this->any())
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($set): ?\App\Entity\Set {
                if (($criteria['no'] ?? '') === '40809' || ($criteria['no'] ?? '') === '40809-1') {
                    return $set;
                }
                return null;
            });

        $em->expects($this->any())
            ->method('getRepository')
            ->with(SetPrice::class)
            ->willReturn($setPriceRepo);

        // No recent duplicates
        $setPriceRepo->expects($this->any())
            ->method('findOneBy')
            ->willReturn(null);

        $em->expects($this->atLeastOnce())
            ->method('persist');
        $em->expects($this->once())
            ->method('flush');

        $service = new HeadlessScraperService(
            em: $em,
            setRepo: $setRepo,
            exchangeRateService: $exchangeRateService,
            resilienceManager: $resilienceManager,
            logger: new NullLogger(),
            projectDir: sys_get_temp_dir()
        );

        $items = [
            [
                'setNo' => '40809',
                'amountCents' => 2890,
                'price' => 28.90,
                'currency' => 'CHF',
                'retailer' => 'galaxus',
                'provider' => 'bookmarklet',
                'url' => 'https://www.galaxus.ch/de/s5/product/lego-40809',
            ],
            [
                'setNo' => '40809',
                'amountCents' => 2419,
                'price' => 24.19,
                'currency' => 'CHF',
                'retailer' => 'other',
                'provider' => 'toppreise',
                'url' => 'https://www.toppreise.ch/preisvergleich/Lego/set-40809',
            ],
            [
                'setNo' => '99999', // Non-existent set
                'amountCents' => 5000,
                'currency' => 'CHF',
                'retailer' => 'brack',
                'provider' => 'bookmarklet',
                'url' => 'https://www.brack.ch/lego-99999',
            ],
        ];

        $res = $service->ingestExtractedItems($items);

        $this->assertSame(3, $res['total']);
        $this->assertSame(2, $res['imported']);
        $this->assertSame(1, $res['skipped']);
        $this->assertCount(2, $set->getPrices());

        // Verify best price updated on Set entity
        $bestPrice = $set->getBestPrice('CHF');
        $this->assertNotNull($bestPrice);
        $this->assertSame(24.19, $bestPrice->getDecimalPrice());
        $this->assertSame(Retailer::OTHER, $bestPrice->getRetailer());
    }
}
