<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Enum\PriceSourceProvider;
use App\Enum\Retailer;
use App\Message\FetchSetPriceMessage;
use App\Service\BrickPickerPriceLoaderService;
use App\Service\BricksetPriceLoaderService;
use App\Service\ExchangeRateService;
use App\Service\Scraper\HeadlessScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchSetPriceMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BricksetPriceLoaderService $bricksetLoader,
        private readonly BrickPickerPriceLoaderService $brickPickerLoader,
        private readonly HeadlessScraperService $headlessScraper,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(FetchSetPriceMessage $message): void
    {
        $set = $this->em->find(Set::class, $message->setId);
        if (!$set instanceof Set) {
            $this->logger->warning('Set not found for FetchSetPriceMessage', ['setId' => $message->setId]);
            return;
        }

        $setNo = (string) $set->getNo();
        $provider = $message->provider;
        $retailer = $message->retailer;

        // 1. If a specific retailer was targeted for headless scrape:
        if ($retailer instanceof Retailer) {
            try {
                $items = $this->headlessScraper->scrapeSet($set, $retailer);
                if ($items !== []) {
                    $this->headlessScraper->ingestExtractedItems($items);
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Retailer scraper error in async worker', [
                    'set' => $setNo,
                    'retailer' => $retailer->value,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 2. Try Brickset (reliable MSRP, BrickLink, Market Values)
        if (!$provider instanceof \App\Enum\PriceSourceProvider || $provider === PriceSourceProvider::BRICKSET) {
            try {
                $detailedPrices = $this->bricksetLoader->loadDetailedPricesForSet($setNo);
                if ($detailedPrices === []) {
                    $legacy = $this->bricksetLoader->loadPricesForSet($setNo);
                    foreach ($legacy as $curr => $val) {
                        $detailedPrices[] = [
                            'amount' => $val,
                            'currency' => $curr,
                            'retailer' => Retailer::LEGO_STORE,
                            'provider' => PriceSourceProvider::BRICKSET,
                            'condition' => 'msrp',
                            'url' => 'https://brickset.com/sets/' . urlencode($setNo),
                        ];
                    }
                }
                if ($detailedPrices !== []) {
                    $oneDayAgo = (new \DateTimeImmutable())->modify('-24 hours');
                    foreach ($detailedPrices as $dp) {
                        $amountCents = (int) round($dp['amount'] * 100);
                        $currency = $dp['currency'];
                        $retailerEnum = $dp['retailer'];
                        $condition = $dp['condition'] ?? 'msrp';

                        // Deduplicate within 24h
                        $existing = $this->em->getRepository(SetPrice::class)->findOneBy([
                            'set' => $set,
                            'retailer' => $retailerEnum,
                            'amount' => $amountCents,
                            'currency' => $currency,
                            'itemCondition' => $condition,
                        ]);

                        if ($existing instanceof SetPrice && $existing->getRecordedAt() > $oneDayAgo) {
                            continue;
                        }

                        $setPrice = new SetPrice(
                            set: $set,
                            amount: $amountCents,
                            currency: $currency,
                            retailer: $retailerEnum,
                            provider: $dp['provider'] ?? PriceSourceProvider::BRICKSET,
                            itemCondition: $condition
                        );
                        $setPrice->setUrl($dp['url'] ?? ('https://brickset.com/sets/' . urlencode($setNo)));
                        $set->addPrice($setPrice);
                        $this->em->persist($setPrice);

                        if (($set->getImageUrl() === null || $set->getImageUrl() === '') && !empty($dp['imageUrl'])) {
                            $set->setImageUrl($dp['imageUrl']);
                        }
                    }
                    $set->updateCachedBestPrice($this->exchangeRateService);
                    $this->em->persist($set);
                    $this->em->flush();
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Brickset fetch error in async worker', ['set' => $setNo, 'error' => $e->getMessage()]);
            }
        }

        // 3. Fallback or explicit BrickPicker
        if (!$provider instanceof \App\Enum\PriceSourceProvider || $provider === PriceSourceProvider::BRICKPICKER) {
            try {
                $decimalPrice = $this->brickPickerLoader->loadPriceForSet($setNo);
                if ($decimalPrice !== null && $decimalPrice > 0) {
                    $setPrice = new SetPrice(
                        set: $set,
                        amount: (int) round($decimalPrice * 100),
                        currency: 'USD',
                        retailer: Retailer::OTHER,
                        provider: PriceSourceProvider::BRICKPICKER
                    );
                    $setPrice->setUrl('https://www.brickpicker.com/bpms/set.cfm?set=' . urlencode($setNo));
                    $set->addPrice($setPrice);
                    $set->updateCachedBestPrice($this->exchangeRateService);
                    $this->em->persist($setPrice);
                    $this->em->persist($set);
                    $this->em->flush();
                }
            } catch (\Throwable $e) {
                $this->logger->warning('BrickPicker fetch error in async worker', ['set' => $setNo, 'error' => $e->getMessage()]);
            }
        }

        // 4. Fallback or explicit headless browser scraper (Camoufox / Retailers)
        if ($set->getPrices()->isEmpty() || $provider === PriceSourceProvider::DIRECT_CRAWL || $provider === PriceSourceProvider::TOPPREISE) {
            try {
                $targetRetailers = $retailer instanceof Retailer
                    ? [$retailer]
                    : [Retailer::GALAXUS, Retailer::BRACK, Retailer::LEGO_STORE, Retailer::OTHER];
                $items = $this->headlessScraper->scrapeSet($set, $targetRetailers);
                if ($items !== []) {
                    $this->headlessScraper->ingestExtractedItems($items);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Headless scraper fallback error in async worker', [
                    'set' => $setNo,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
