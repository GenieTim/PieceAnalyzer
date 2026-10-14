<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Entity\Set;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Description of BrickPickerPriceLoaderService
 *
 * @author timbernhard
 */
class BrickPickerPriceLoaderService implements PriceLoaderServiceInterface
{
    use RateLimitRetryTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ?HttpClientInterface $httpClient = null,
        ?ProviderResilienceManagerInterface $resilienceManager = null
    ) {
        if ($resilienceManager instanceof \App\Service\ProviderResilienceManagerInterface) {
            $this->setResilienceManager($resilienceManager);
        }
    }

    #[\Override]
    public function loadPriceForSet(mixed $set_no): ?float
    {
        if (!$this->isAvailable('BrickPicker')) {
            return null;
        }

        $set_no = (string) $set_no;
        $src = "https://www.brickpicker.com/bpms/set.cfm?set=" . urlencode($set_no);

        try {
            $html = '';
            if ($this->httpClient instanceof HttpClientInterface) {
                $response = $this->executeWithRateLimitRetry(
                    fn (): \Symfony\Contracts\HttpClient\ResponseInterface => $this->httpClient->request('GET', $src, [
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        ],
                        'timeout' => 5,
                    ]),
                    'BrickPicker',
                    $src,
                    $this->logger
                );

                if (!$response instanceof \Symfony\Contracts\HttpClient\ResponseInterface || ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 0)) {
                    if ($response instanceof \Symfony\Contracts\HttpClient\ResponseInterface && $response->getStatusCode() !== 429) {
                        $this->logger->warning('BrickPicker returned non-200 status', [
                            'status' => $response->getStatusCode(),
                            'url' => $src,
                        ]);
                    }
                    return null;
                }

                $html = $response->getContent(false);
            } else {
                $content = $this->fetchUrlWithRateLimitRetry(
                    $src,
                    [
                        'timeout' => 5,
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ],
                    'BrickPicker',
                    $this->logger
                );

                if ($content === null || $content === '') {
                    return null;
                }
                $html = $content;
            }

            if ($html === '') {
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

    #[\Override]
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
            if ($this->requestDelayMs > 0) {
                $this->sleep($this->requestDelayMs / 1000);
            }
            if ($i > 0 && ($i % $batchSize) === 0) {
                $this->em->flush();
                $this->em->clear();
            }
            ++$i;
        }
        $this->em->flush();
        return $this;
    }
}
