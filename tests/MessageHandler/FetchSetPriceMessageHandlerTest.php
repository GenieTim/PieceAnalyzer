<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Set;
use App\Enum\PriceSourceProvider;
use App\Enum\Retailer;
use App\Message\FetchSetPriceMessage;
use App\MessageHandler\FetchSetPriceMessageHandler;
use App\Service\BrickPickerPriceLoaderService;
use App\Service\BricksetPriceLoaderService;
use App\Service\ExchangeRateService;
use App\Service\Scraper\HeadlessScraperService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FetchSetPriceMessageHandlerTest extends TestCase
{
    public function testFallbackToHeadlessScraperWhenTraditionalSourcesReturnNoPrices(): void
    {
        $set = new Set();
        $set->setNo('75192-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(Set::class, 42)->willReturn($set);

        $bricksetLoader = $this->createMock(BricksetPriceLoaderService::class);
        $bricksetLoader->method('loadPricesForSet')->willReturn([]); // Empty or 429

        $brickPickerLoader = $this->createMock(BrickPickerPriceLoaderService::class);
        $brickPickerLoader->method('loadPriceForSet')->willReturn(null); // Blocked 403

        $headlessScraper = $this->createMock(HeadlessScraperService::class);
        $headlessScraper->expects($this->once())
            ->method('scrapeSet')
            ->willReturn([
                [
                    'setNo' => '75192',
                    'amountCents' => 84900,
                    'price' => 849.0,
                    'currency' => 'CHF',
                    'retailer' => 'galaxus',
                    'provider' => 'direct_crawl',
                    'url' => 'https://www.galaxus.ch/en/search?q=lego+75192',
                ]
            ]);
        $headlessScraper->expects($this->once())
            ->method('ingestExtractedItems')
            ->willReturn(['imported' => 1]);

        $exchangeRateService = $this->createMock(ExchangeRateService::class);

        $handler = new FetchSetPriceMessageHandler(
            em: $em,
            bricksetLoader: $bricksetLoader,
            brickPickerLoader: $brickPickerLoader,
            headlessScraper: $headlessScraper,
            exchangeRateService: $exchangeRateService,
            logger: new NullLogger()
        );

        $handler(new FetchSetPriceMessage(42));
    }
}
