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
    use RateLimitRetryTrait;

    public const API_BASE_URL = 'https://brickset.com/api/v3.asmx/getSets';
    public const WEB_BASE_URL = 'https://brickset.com/sets/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?HttpClientInterface $httpClient = null,
        private string $apiKey = '',
        private string $defaultCurrency = 'EUR',
        ?ProviderResilienceManagerInterface $resilienceManager = null
    ) {
        if ($resilienceManager instanceof \App\Service\ProviderResilienceManagerInterface) {
            $this->setResilienceManager($resilienceManager);
        }
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
    #[\Override]
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
        if (!$this->isAvailable('Brickset')) {
            return [];
        }

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
     * Fetch detailed prices (MSRP, BrickLink, Current Market Value) for a set.
     *
     * @return list<array{
     *     amount: float,
     *     currency: string,
     *     retailer: \App\Enum\Retailer,
     *     provider: \App\Enum\PriceSourceProvider,
     *     condition: string,
     *     url: string,
     *     imageUrl?: string|null,
     * }>
     */
    public function loadDetailedPricesForSet(mixed $set_no): array
    {
        if (!$this->isAvailable('Brickset')) {
            return [];
        }

        $setNo = (string) ($set_no instanceof Set ? $set_no->getNo() : $set_no);
        $setNo = trim($setNo);
        if ($setNo === '') {
            return [];
        }

        $normalizedSetNo = $this->normalizeSetNum($setNo);
        $this->logger->info('Loading detailed Brickset prices for set ' . $normalizedSetNo);

        return $this->loadDetailedPricesFromWeb($normalizedSetNo);
    }

    /**
     * Batch reload prices for sets in the database.
     */
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

            if ($this->requestDelayMs > 0) {
                $this->sleep($this->requestDelayMs / 1000);
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
        if (!$this->httpClient instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
            return [];
        }

        try {
            $response = $this->executeWithRateLimitRetry(
                fn (): \Symfony\Contracts\HttpClient\ResponseInterface => $this->httpClient->request('GET', self::API_BASE_URL, [
                    'query' => [
                        'apiKey' => $this->apiKey,
                        'userHash' => '',
                        'params' => json_encode(['setNumber' => $setNo]),
                    ],
                    'timeout' => 10,
                ]),
                'Brickset API',
                self::API_BASE_URL,
                $this->logger
            );

            if (!$response instanceof \Symfony\Contracts\HttpClient\ResponseInterface || ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 0)) {
                if ($response instanceof \Symfony\Contracts\HttpClient\ResponseInterface && $response->getStatusCode() !== 429) {
                    $this->logger->warning('Brickset API returned non-200 status', [
                        'status' => $response->getStatusCode(),
                    ]);
                }
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
        $html = $this->fetchHtmlFromWeb($setNo);
        if ($html === '') {
            return [];
        }

        return $this->parsePricesFromHtml($html);
    }

    /**
     * Scrape public Brickset set page for detailed prices and metadata.
     *
     * @return list<array{
     *     amount: float,
     *     currency: string,
     *     retailer: \App\Enum\Retailer,
     *     provider: \App\Enum\PriceSourceProvider,
     *     condition: string,
     *     url: string,
     *     imageUrl?: string|null,
     * }>
     */
    private function loadDetailedPricesFromWeb(string $setNo): array
    {
        $html = $this->fetchHtmlFromWeb($setNo);
        if ($html === '') {
            return [];
        }

        return $this->parseDetailedPricesFromHtml($html, $setNo);
    }

    private function fetchHtmlFromWeb(string $setNo): string
    {
        $url = self::WEB_BASE_URL . urlencode($setNo);

        try {
            if ($this->httpClient instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
                $response = $this->executeWithRateLimitRetry(
                    fn (): \Symfony\Contracts\HttpClient\ResponseInterface => $this->httpClient->request('GET', $url, [
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        ],
                        'timeout' => 10,
                    ]),
                    'Brickset web page',
                    $url,
                    $this->logger
                );

                if (!$response instanceof \Symfony\Contracts\HttpClient\ResponseInterface || ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 0)) {
                    if ($response instanceof \Symfony\Contracts\HttpClient\ResponseInterface && $response->getStatusCode() !== 429) {
                        $this->logger->warning('Brickset web page returned non-200 status', [
                            'status' => $response->getStatusCode(),
                            'url' => $url,
                        ]);
                    }
                    return '';
                }

                return $response->getContent(false);
            }

            $content = $this->fetchUrlWithRateLimitRetry(
                $url,
                [
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'Brickset web page',
                $this->logger
            );

            return $content ?? '';
        } catch (Throwable $e) {
            $this->logger->warning('Failed to scrape price from Brickset web page', [
                'set' => $setNo,
                'error' => $e->getMessage(),
            ]);
            return '';
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
                while ($next instanceof \DOMNode && $next->nodeName !== 'dd') {
                    $next = $next->nextSibling;
                }
                if ($next instanceof \DOMNode) {
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

    /**
     * Parse detailed prices from Brickset HTML:
     * - MSRP (LEGO Store) in USD, GBP, EUR, CAD
     * - Current Value (Brickset Market Value: New, Used)
     * - BrickLink (Buy This Set: New, Used)
     *
     * @return list<array{
     *     amount: float,
     *     currency: string,
     *     retailer: \App\Enum\Retailer,
     *     provider: \App\Enum\PriceSourceProvider,
     *     condition: string,
     *     url: string,
     *     imageUrl?: string|null,
     * }>
     */
    public function parseDetailedPricesFromHtml(string $html, string $setNo): array
    {
        $crawler = new Crawler($html);
        $detailed = [];
        $imageUrl = $this->parseImageUrlFromHtml($html);

        // 1. MSRP (LEGO Store)
        $rrpPrices = $this->parsePricesFromHtml($html);
        foreach ($rrpPrices as $curr => $val) {
            $detailed[] = [
                'amount' => $val,
                'currency' => $curr,
                'retailer' => \App\Enum\Retailer::LEGO_STORE,
                'provider' => \App\Enum\PriceSourceProvider::BRICKSET,
                'condition' => 'msrp',
                'url' => self::WEB_BASE_URL . urlencode($setNo),
                'imageUrl' => $imageUrl,
            ];
        }

        // 2. Current Market Value: New / Used
        $dtNodes = $crawler->filter('dt');
        $currentValueText = '';
        foreach ($dtNodes as $dtNode) {
            if (stripos($dtNode->textContent, 'current value') !== false) {
                $next = $dtNode->nextSibling;
                while ($next instanceof \DOMNode && $next->nodeName !== 'dd') {
                    $next = $next->nextSibling;
                }
                if ($next instanceof \DOMNode) {
                    $currentValueText = $next->textContent;
                    break;
                }
            }
        }

        if ($currentValueText !== '') {
            // New: ~Fr223 or New: ~$240
            if (preg_match('/New:\s*([^,\n\r<]+)/i', $currentValueText, $newMatch)) {
                $parsed = $this->parseCurrencyAndAmount($newMatch[1]);
                if ($parsed !== null) {
                    $detailed[] = [
                        'amount' => $parsed['amount'],
                        'currency' => $parsed['currency'],
                        'retailer' => \App\Enum\Retailer::OTHER,
                        'provider' => \App\Enum\PriceSourceProvider::BRICKSET,
                        'condition' => 'new',
                        'url' => self::WEB_BASE_URL . urlencode($setNo),
                        'imageUrl' => $imageUrl,
                    ];
                }
            }
            // Used: ~Fr63 or Used: ~$65
            if (preg_match('/Used:\s*([^,\n\r<]+)/i', $currentValueText, $usedMatch)) {
                $parsed = $this->parseCurrencyAndAmount($usedMatch[1]);
                if ($parsed !== null) {
                    $detailed[] = [
                        'amount' => $parsed['amount'],
                        'currency' => $parsed['currency'],
                        'retailer' => \App\Enum\Retailer::OTHER,
                        'provider' => \App\Enum\PriceSourceProvider::BRICKSET,
                        'condition' => 'used',
                        'url' => self::WEB_BASE_URL . urlencode($setNo),
                        'imageUrl' => $imageUrl,
                    ];
                }
            }
        }

        // 3. BrickLink Block (Buy this set: New ~Fr208, Used ~Fr74)
        $bricklinkBoxText = '';
        $blNodes = $crawler->filter('aside, div, table, section');
        foreach ($blNodes as $node) {
            $t = $node->textContent;
            if (stripos($t, 'bricklink') !== false && (stripos($t, 'buy this set') !== false || stripos($t, 'switzerland') !== false || stripos($t, 'european union') !== false)) {
                $bricklinkBoxText = $t;
                break;
            }
        }

        // Fallback: check entire HTML if not matched in container
        if ($bricklinkBoxText === '' && (stripos($html, 'bricklink') !== false && stripos($html, 'buy this set') !== false)) {
            $bricklinkBoxText = $html;
        }

        if ($bricklinkBoxText !== '') {
            $blUrl = 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . urlencode($setNo);

            // Buy this set: New ~Fr208, Used ~Fr74
            if (preg_match('/(?:BUY THIS SET|Bricklink)[^:]*:\s*(?:New\s*[:~]?\s*([^,\n\r<]+))?(?:,\s*)?(?:Used\s*[:~]?\s*([^,\n\r<]+))?/i', $bricklinkBoxText, $blMatch)) {
                if (isset($blMatch[1]) && ($blMatch[1] !== '' && $blMatch[1] !== '0')) {
                    $parsed = $this->parseCurrencyAndAmount($blMatch[1]);
                    if ($parsed !== null) {
                        $detailed[] = [
                            'amount' => $parsed['amount'],
                            'currency' => $parsed['currency'],
                            'retailer' => \App\Enum\Retailer::BRICKLINK,
                            'provider' => \App\Enum\PriceSourceProvider::BRICKSET,
                            'condition' => 'new',
                            'url' => $blUrl,
                            'imageUrl' => $imageUrl,
                        ];
                    }
                }
                if (isset($blMatch[2]) && ($blMatch[2] !== '' && $blMatch[2] !== '0')) {
                    $parsed = $this->parseCurrencyAndAmount($blMatch[2]);
                    if ($parsed !== null) {
                        $detailed[] = [
                            'amount' => $parsed['amount'],
                            'currency' => $parsed['currency'],
                            'retailer' => \App\Enum\Retailer::BRICKLINK,
                            'provider' => \App\Enum\PriceSourceProvider::BRICKSET,
                            'condition' => 'used',
                            'url' => $blUrl,
                            'imageUrl' => $imageUrl,
                        ];
                    }
                }
            } elseif (preg_match('/New\s*(?:~|Fr\.?|\$|£|€)\s*[\d]+(?:[.,]\d+)?/i', $bricklinkBoxText, $directNew) && preg_match('/Used\s*(?:~|Fr\.?|\$|£|€)\s*[\d]+(?:[.,]\d+)?/i', $bricklinkBoxText, $directUsed)) {
                $parsedNew = $this->parseCurrencyAndAmount($directNew[0]);
                $parsedUsed = $this->parseCurrencyAndAmount($directUsed[0]);
                if ($parsedNew !== null) {
                    $detailed[] = [
                        'amount' => $parsedNew['amount'],
                        'currency' => $parsedNew['currency'],
                        'retailer' => \App\Enum\Retailer::BRICKLINK,
                        'provider' => \App\Enum\PriceSourceProvider::BRICKSET,
                        'condition' => 'new',
                        'url' => $blUrl,
                        'imageUrl' => $imageUrl,
                    ];
                }
                if ($parsedUsed !== null) {
                    $detailed[] = [
                        'amount' => $parsedUsed['amount'],
                        'currency' => $parsedUsed['currency'],
                        'retailer' => \App\Enum\Retailer::BRICKLINK,
                        'provider' => \App\Enum\PriceSourceProvider::BRICKSET,
                        'condition' => 'used',
                        'url' => $blUrl,
                        'imageUrl' => $imageUrl,
                    ];
                }
            }
        }

        return $detailed;
    }

    /**
     * Parse currency and decimal amount from text like "~Fr223", "~Fr79.80", "$29.99", "£26.99", "29.99€", "€29,99"
     *
     * @return array{currency: string, amount: float}|null
     */
    public function parseCurrencyAndAmount(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || stripos($text, 'n/a') !== false) {
            return null;
        }

        $currency = null;
        $amountStr = null;

        // 1. Swiss Francs: Fr, Fr., CHF (common on Brickset for CH locale)
        if (preg_match('/(?:~?\s*Fr\.?|CHF)\s*([\d]+(?:[.,]\d+)?)/i', $text, $m)) {
            $currency = 'CHF';
            $amountStr = $m[1];
        } elseif (preg_match('/([\d]+(?:[.,]\d+)?)\s*(?:Fr\.?|CHF)/i', $text, $m)) {
            $currency = 'CHF';
            $amountStr = $m[1];
        } elseif (preg_match('/(?:CA\$|CAD)\s*([\d]+(?:[.,]\d+)?)/i', $text, $m) || preg_match('/([\d]+(?:[.,]\d+)?)\s*CAD/i', $text, $m)) {
            // CAD: CAD / CA$ (must check before generic $)
            $currency = 'CAD';
            $amountStr = $m[1];
        } elseif (preg_match('/\$\s*([\d]+(?:[.,]\d+)?)/', $text, $m)) {
            // USD: $
            $currency = 'USD';
            $amountStr = $m[1];
        } elseif (preg_match('/(?:£|\x{00A3})\s*([\d]+(?:[.,]\d+)?)/u', $text, $m)) {
            // GBP: £
            $currency = 'GBP';
            $amountStr = $m[1];
        } elseif (preg_match('/(?:€|\x{20AC})\s*([\d]+(?:[.,]\d+)?)/u', $text, $m) || preg_match('/([\d]+(?:[.,]\d+)?)\s*(?:€|\x{20AC})/u', $text, $m)) {
            // EUR: €
            $currency = 'EUR';
            $amountStr = $m[1];
        } else {
            return null;
        }

        $cleanAmount = (float) str_replace(',', '.', $amountStr);
        if ($cleanAmount <= 0) {
            return null;
        }

        return [
            'currency' => $currency,
            'amount' => $cleanAmount,
        ];
    }

    public function parseImageUrlFromHtml(string $html): ?string
    {
        $crawler = new Crawler($html);
        $ogImage = $crawler->filter('meta[property="og:image"]');
        if ($ogImage->count() > 0) {
            $url = trim((string) $ogImage->attr('content'));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        $imgNode = $crawler->filter('a.image img, #set-image, img[src*="images.brickset.com/sets/images/"]');
        if ($imgNode->count() > 0) {
            $src = trim((string) $imgNode->attr('src'));
            if ($src !== '') {
                return $src;
            }
        }

        return null;
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
