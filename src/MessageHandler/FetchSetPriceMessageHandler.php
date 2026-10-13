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

        // Try Brickset (reliable MSRP in multi-currency: EUR, USD, GBP)
        if (!$provider instanceof \App\Enum\PriceSourceProvider || $provider === PriceSourceProvider::BRICKSET) {
            try {
                $prices = $this->bricksetLoader->loadPricesForSet($setNo);
                if ($prices !== []) {
                    foreach ($prices as $currency => $decimalPrice) {
                        $setPrice = new SetPrice(
                            set: $set,
                            amount: (int) round($decimalPrice * 100),
                            currency: $currency,
                            retailer: Retailer::LEGO_STORE,
                            provider: PriceSourceProvider::BRICKSET
                        );
                        $setPrice->setUrl('https://brickset.com/sets/' . urlencode($setNo));
                        $set->addPrice($setPrice);
                        $this->em->persist($setPrice);
                    }
                    $this->em->flush();
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Brickset fetch error in async worker', ['set' => $setNo, 'error' => $e->getMessage()]);
            }
        }

        // Fallback or explicit BrickPicker
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
                    $this->em->persist($setPrice);
                    $this->em->flush();
                }
            } catch (\Throwable $e) {
                $this->logger->warning('BrickPicker fetch error in async worker', ['set' => $setNo, 'error' => $e->getMessage()]);
            }
        }
    }
}
