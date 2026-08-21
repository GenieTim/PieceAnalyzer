<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Set;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Service to fetch official multi-currency MSRP/RRP (EUR/USD/GBP) from Brickset.
 * Supports Brickset API v3 and falls back to public Brickset set page scraping.
 */
class BricksetPriceLoaderService implements PriceLoaderServiceInterface
{
    public const API_BASE_URL = 'https://brickset.com/api/v3.asmx/getSets';
    public const WEB_BASE_URL = 'https://brickset.com/sets/';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private string $apiKey = '',
        private string $defaultCurrency = 'EUR'
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function getDefaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    public function setDefaultCurrency(string $currency): static
    {
        $this->defaultCurrency = strtoupper(trim($currency));

        return $this;
    }

    /**
     * Fetch price for a set in the default currency (EUR by default).
     */
    public function loadPriceForSet(mixed $set_no): ?float
    {
        $prices = $this->loadPricesForSet($set_no);
        if ($prices === []) {
            return null;
        }

        $currency = $this->defaultCurrency;
        if (isset($prices[$currency])) {
            return $prices[$currency];
        }

        // Fallbacks in order of preference: EUR, USD, GBP, first available
        foreach (['EUR', 'USD', 'GBP'] as $fallbackCurrency) {
            if (isset($prices[$fallbackCurrency])) {
                return $prices[$fallbackCurrency];
            }
        }

        $firstKey = array_key_first($prices);
        return $firstKey !== null ? $prices[$firstKey] : null;
    }

    /**
     * Fetch price for a set in a specific currency (e.g. 'EUR', 'USD', 'GBP', 'CAD').
     */
    public function loadPriceForSetInCurrency(mixed $set_no, string $currency = 'EUR'): ?float
    {
        $prices = $this->loadPricesForSet($set_no);
        $curr = strtoupper(trim($currency));

        return $prices[$curr] ?? null;
    }

    /**
     * Fetch multi-currency MSRP/RRP prices for a set.
     *
     * @return array<string, float> Keyed by uppercase currency code (e.g. ['EUR' => 849.99, 'USD' => 849.99, 'GBP' => 734.99])
     */
    public function loadPricesForSet(mixed $set_no): array
    {
        $setNo = (string) ($set_no instanceof Set ? $set_no->getNo() : $set_no);
        $setNo = trim($setNo);
        if ($setNo === '') {
            return [];
        }

        $normalizedSetNo = $this->normalizeSetNum($setNo);
        $this->logger->info('Loading Brickset prices for set ' . $normalizedSetNo);

        // 1. Try Brickset API v3 if API key is provided
        if ($this->apiKey !== '') {
            $apiPrices = $this->loadPricesFromApi($normalizedSetNo);
            if ($apiPrices !== []) {
                return $apiPrices;
            }
        }

        // 2. Fall back to scraping public Brickset set page
        return $this->loadPricesFromWeb($normalizedSetNo);
    }

    /**
     * Batch reload prices for sets in the database.
     */
    public function loadPrices(bool $all = false): static
    {
        $query = 'SELECT s FROM ' . Set::class . ' s';
        if (!$all) {
            $query .= ' WHERE s.price IS NULL';
        }

        $q = $this->em->createQuery($query);
        $batchSize = 50;
        $i = 0;
        $unsolvedSets = $q->toIterable();

        foreach ($unsolvedSets as $row) {
            $set = is_array($row) ? $row[0] : $row;
            if ($set instanceof Set) {
                try {
                    $price = $this->loadPriceForSet($set->getNo());
                    if ($price !== null) {
                        $set->setPrice($price);
                        $this->em->persist($set);
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('Error loading price from Brickset for set', [
                        'set' => $set->getNo(),
                        'error' => $e->getMessage(),
                    ]);
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

    /**
     * Query Brickset API v3 for set prices.
     *
     * @return array<string, float>
     */
    private function loadPricesFromApi(string $setNo): array
    {
        if ($this->httpClient === null) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL, [
                'query' => [
                    'apiKey' => $this->apiKey,
                    'userHash' => '',
                    'params' => json_encode(['setNumber' => $setNo]),
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('Brickset API returned non-200 status', [
                    'status' => $response->getStatusCode(),
                ]);
                return [];
            }

            $data = json_decode($response->getContent(false), true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'success' || empty($data['sets'])) {
                return [];
            }

            /** @var array<string, mixed> $firstSet */
            $firstSet = $data['sets'][0];
            $legoCom = is_array($firstSet['LEGOCom'] ?? null) ? $firstSet['LEGOCom'] : [];

            $prices = [];

            // Extract EUR
            if (isset($legoCom['DE']['retailPrice']) && is_numeric($legoCom['DE']['retailPrice'])) {
                $prices['EUR'] = (float) $legoCom['DE']['retailPrice'];
            } elseif (isset($legoCom['FR']['retailPrice']) && is_numeric($legoCom['FR']['retailPrice'])) {
                $prices['EUR'] = (float) $legoCom['FR']['retailPrice'];
            }

            // Extract USD
            if (isset($legoCom['US']['retailPrice']) && is_numeric($legoCom['US']['retailPrice'])) {
                $prices['USD'] = (float) $legoCom['US']['retailPrice'];
            }

            // Extract GBP
            if (isset($legoCom['UK']['retailPrice']) && is_numeric($legoCom['UK']['retailPrice'])) {
                $prices['GBP'] = (float) $legoCom['UK']['retailPrice'];
            }

            // Extract CAD
            if (isset($legoCom['CA']['retailPrice']) && is_numeric($legoCom['CA']['retailPrice'])) {
                $prices['CAD'] = (float) $legoCom['CA']['retailPrice'];
            }

            return $prices;
        } catch (Throwable $e) {
            $this->logger->warning('Exception when calling Brickset API v3', [
                'set' => $setNo,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Scrape public Brickset set page for prices.
     *
     * @return array<string, float>
     */
    private function loadPricesFromWeb(string $setNo): array
    {
        $url = self::WEB_BASE_URL . urlencode($setNo);

        try {
            $html = '';
            if ($this->httpClient !== null) {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    ],
                    'timeout' => 10,
                ]);

                if ($response->getStatusCode() !== 200) {
                    $this->logger->warning('Brickset web page returned non-200 status', [
                        'status' => $response->getStatusCode(),
                        'url' => $url,
                    ]);
                    return [];
                }

                $html = $response->getContent(false);
            } else {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ],
                ]);
                $content = @file_get_contents($url, false, $context);
                if ($content === false) {
                    return [];
                }
                $html = $content;
            }

            if ($html === '') {
                return [];
            }

            return $this->parsePricesFromHtml($html);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to scrape price from Brickset web page', [
                'set' => $setNo,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Parse multi-currency prices from Brickset HTML content.
     *
     * @return array<string, float>
     */
    public function parsePricesFromHtml(string $html): array
    {
        $crawler = new Crawler($html);
        $prices = [];

        // Search for <dt> containing RRP and get its matching <dd>
        $rrpText = '';
        $dtNodes = $crawler->filter('dt');
        foreach ($dtNodes as $dtNode) {
            if (stripos($dtNode->textContent, 'RRP') !== false) {
                // Find next sibling dd
                $next = $dtNode->nextSibling;
                while ($next !== null && $next->nodeName !== 'dd') {
                    $next = $next->nextSibling;
                }
                if ($next !== null) {
                    $rrpText = $next->textContent;
                    break;
                }
            }
        }

        if ($rrpText === '') {
            // Fallback: look for any .price or feature text in page
            $featureNodes = $crawler->filter('.featureblock, .meta, .price');
            if ($featureNodes->count() > 0) {
                $rrpText = $featureNodes->text();
            }
        }

        if ($rrpText === '') {
            return [];
        }

        // 1. USD: $849.99
        if (preg_match('/\$\s*([\d]+(?:[.,]\d+)?)/', $rrpText, $usdMatches)) {
            $prices['USD'] = (float) str_replace(',', '.', $usdMatches[1]);
        }

        // 2. GBP: £734.99 or \u{00A3}
        if (preg_match('/(?:£|\x{00A3})\s*([\d]+(?:[.,]\d+)?)/u', $rrpText, $gbpMatches)) {
            $prices['GBP'] = (float) str_replace(',', '.', $gbpMatches[1]);
        }

        // 3. EUR: 849.99€ or €849.99 or \u{20AC}
        if (
            preg_match('/([\d]+(?:[.,]\d+)?)\s*(?:€|\x{20AC})/u', $rrpText, $eurMatches) ||
            preg_match('/(?:€|\x{20AC})\s*([\d]+(?:[.,]\d+)?)/u', $rrpText, $eurMatches)
        ) {
            $prices['EUR'] = (float) str_replace(',', '.', $eurMatches[1]);
        }

        // 4. CAD: CA$1049.99 or 1049.99 CAD
        if (
            preg_match('/(?:CA\$|CAD)\s*([\d]+(?:[.,]\d+)?)/i', $rrpText, $cadMatches) ||
            preg_match('/([\d]+(?:[.,]\d+)?)\s*CAD/i', $rrpText, $cadMatches)
        ) {
            $prices['CAD'] = (float) str_replace(',', '.', $cadMatches[1]);
        }

        return $prices;
    }

    private function normalizeSetNum(string $setNum): string
    {
        $trimmed = trim($setNum);
        if (preg_match('/^\d+$/', $trimmed)) {
            return $trimmed . '-1';
        }
        return $trimmed;
    }
}
