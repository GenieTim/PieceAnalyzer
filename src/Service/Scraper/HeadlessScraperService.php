<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\PriceSourceProvider;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use App\Repository\SetRepository;
use App\Service\ExchangeRateService;
use App\Service\ProviderResilienceManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HeadlessScraperService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SetRepository $setRepo,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?ProviderResilienceManagerInterface $resilienceManager = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
        #[Autowire(env: 'SCRAPER_SERVICE_URL')]
        private readonly ?string $scraperServiceUrl = null
    ) {
    }

    /**
     * Scrape prices from an arbitrary target URL using the best available engine.
     *
     * @param array<string, mixed> $options
     * @return list<array{
     *     setNo: string,
     *     amountCents: int,
     *     price: float,
     *     currency: string,
     *     retailer: string,
     *     provider: string,
     *     url: string,
     *     title?: string,
     *     type?: string
     * }>
     */
    public function scrapeUrl(string $url, array $options = []): array
    {
        $providerName = $this->resolveProviderNameFromUrl($url);

        if ($this->resilienceManager instanceof \App\Service\ProviderResilienceManagerInterface && !$this->resilienceManager->isAvailable($providerName)) {
            $this->logger->warning('Circuit breaker is open for provider, skipping scrape', [
                'provider' => $providerName,
                'url' => $url,
            ]);
            return [];
        }

        // Apply pacing delay if configured
        if ($this->resilienceManager instanceof \App\Service\ProviderResilienceManagerInterface) {
            $this->resilienceManager->waitPacing($providerName, $this->logger);
        }

        $driver = (string) ($options['driver'] ?? 'auto');
        $items = [];

        try {
            if (($driver === 'service' || $driver === 'auto') && $this->scraperServiceUrl !== null && $this->scraperServiceUrl !== '') {
                $items = $this->scrapeViaHttpService($url, $options);
            } elseif ($driver === 'camoufox') {
                $items = $this->scrapeViaPython($url, $options);
            } elseif ($driver === 'playwright' || $driver === 'node') {
                $items = $this->scrapeViaNode($url, $options);
            } elseif ($this->scraperServiceUrl !== null && $this->scraperServiceUrl !== '') {
                // Auto-detect: prefer service -> python camoufox -> node playwright
                $items = $this->scrapeViaHttpService($url, $options);
            } elseif ($this->isPythonCamoufoxAvailable()) {
                $items = $this->scrapeViaPython($url, $options);
            } else {
                $items = $this->scrapeViaNode($url, $options);
            }

            if ($this->resilienceManager instanceof \App\Service\ProviderResilienceManagerInterface) {
                $this->resilienceManager->recordSuccess($providerName);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error running headless price scrape', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            if ($this->resilienceManager instanceof \App\Service\ProviderResilienceManagerInterface) {
                $this->resilienceManager->recordServerError($providerName, 500, $url);
            }

            throw $e;
        }

        return $items;
    }

    /**
     * Scrape prices for a given set across one or more retailers.
     *
     * @param list<Retailer>|Retailer $retailers
     * @param array<string, mixed> $options
     * @return list<array{
     *     setNo: string,
     *     amountCents: int,
     *     price: float,
     *     currency: string,
     *     retailer: string,
     *     provider: string,
     *     url: string,
     *     title?: string,
     *     type?: string
     * }>
     */
    public function scrapeSet(Set|string $set, Retailer|array $retailers, array $options = []): array
    {
        $setNo = $set instanceof Set ? (string) $set->getNo() : $set;
        $cleanNo = preg_replace('/-\d+$/', '', trim($setNo)) ?? trim($setNo);

        $retailerList = is_array($retailers) ? $retailers : [$retailers];
        $allExtracted = [];

        foreach ($retailerList as $retailer) {
            $targetUrl = match ($retailer) {
                Retailer::GALAXUS => 'https://www.galaxus.ch/en/search?q=lego+' . urlencode($cleanNo),
                Retailer::BRACK => 'https://www.brack.ch/search?query=lego+' . urlencode($cleanNo),
                Retailer::AMAZON_DE => 'https://www.amazon.de/s?k=lego+' . urlencode($cleanNo),
                Retailer::AMAZON_COM => 'https://www.amazon.com/s?k=lego+' . urlencode($cleanNo),
                Retailer::LEGO_STORE => 'https://www.lego.com/search?q=' . urlencode($cleanNo),
                Retailer::BRICKLINK => 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . urlencode(str_contains(trim($setNo), '-') ? trim($setNo) : trim($setNo) . '-1'),
                Retailer::OTHER => 'https://www.toppreise.ch/s?q=lego+' . urlencode($cleanNo),
            };

            try {
                $extracted = $this->scrapeUrl($targetUrl, $options);
                foreach ($extracted as $item) {
                    $allExtracted[] = $item;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed scraping set for retailer', [
                    'set' => $setNo,
                    'retailer' => $retailer->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $allExtracted;
    }

    /**
     * Ingest and persist raw extracted items into SetPrice entities.
     *
     * @param list<array<string, mixed>> $items
     * @return array{total: int, imported: int, skipped: int, errors: list<string>}
     */
    public function ingestExtractedItems(array $items, ?User $user = null): array
    {
        $total = count($items);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($items as $item) {
            $setNo = trim((string) ($item['setNo'] ?? ''));
            if ($setNo === '') {
                $skipped++;
                continue;
            }

            // Find matching set entity
            $set = $this->setRepo->findOneBy(['no' => $setNo]);
            if (!$set instanceof Set) {
                $normNo = str_contains($setNo, '-') ? $setNo : $setNo . '-1';
                $set = $this->setRepo->findOneBy(['no' => $normNo]);
            }

            if (!$set instanceof Set) {
                $skipped++;
                continue;
            }

            $amountCents = (int) ($item['amountCents'] ?? 0);
            if ($amountCents <= 0 && isset($item['price']) && is_numeric($item['price'])) {
                $amountCents = (int) round(((float) $item['price']) * 100);
            }

            if ($amountCents <= 0 || $amountCents > 10_000_000) {
                $skipped++;
                continue;
            }

            $currStr = strtoupper(trim((string) ($item['currency'] ?? 'CHF')));
            if (!in_array($currStr, ['CHF', 'EUR', 'USD', 'GBP'], true)) {
                $currStr = 'CHF';
            }

            $retailer = Retailer::tryFrom((string) ($item['retailer'] ?? 'other')) ?? Retailer::OTHER;
            $provider = PriceSourceProvider::tryFrom((string) ($item['provider'] ?? 'direct_crawl')) ?? PriceSourceProvider::DIRECT_CRAWL;
            $url = isset($item['url']) ? trim((string) $item['url']) : null;

            // Prevent spamming identical duplicate prices recorded on the exact same day
            $existing = $this->em->getRepository(SetPrice::class)->findOneBy([
                'set' => $set,
                'retailer' => $retailer,
                'amount' => $amountCents,
                'currency' => $currStr,
            ]);

            if ($existing instanceof SetPrice) {
                // If existing price is recent (less than 24h old), refresh timestamp and skip creating duplicate row
                $oneDayAgo = (new \DateTimeImmutable())->modify('-24 hours');
                if ($existing->getRecordedAt() > $oneDayAgo) {
                    $skipped++;
                    continue;
                }
            }

            $setPrice = new SetPrice(
                set: $set,
                amount: $amountCents,
                currency: $currStr,
                retailer: $retailer,
                provider: $provider
            );

            $status = ($user instanceof \App\Entity\User && $user->isTrusted()) || !$user instanceof \App\Entity\User
                ? PriceVerificationStatus::VERIFIED
                : PriceVerificationStatus::UNVERIFIED;

            $setPrice->setStatus($status);
            $setPrice->setUrl($url);
            if ($user instanceof \App\Entity\User) {
                $setPrice->setSubmittedBy($user);
            }

            $set->addPrice($setPrice);
            $set->updateCachedBestPrice($this->exchangeRateService);

            $this->em->persist($setPrice);
            $this->em->persist($set);
            $imported++;
        }

        if ($imported > 0) {
            $this->em->flush();
        }

        return [
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{
     *     setNo: string,
     *     amountCents: int,
     *     price: float,
     *     currency: string,
     *     retailer: string,
     *     provider: string,
     *     url: string,
     *     title?: string,
     *     type?: string
     * }>
     */
    private function scrapeViaHttpService(string $url, array $options): array
    {
        if (!$this->httpClient instanceof HttpClientInterface || $this->scraperServiceUrl === null) {
            return [];
        }

        $endpoint = rtrim($this->scraperServiceUrl, '/') . '/scrape';
        $response = $this->httpClient->request('POST', $endpoint, [
            'json' => [
                'url' => $url,
                'options' => $options === [] ? new \stdClass() : $options,
            ],
            'timeout' => 60.0,
        ]);

        if ($response->getStatusCode() !== 200) {
            $errorDetails = '';
            try {
                $errorData = $response->toArray(false);
                if (isset($errorData['error'])) {
                    $errorDetails = ': ' . (is_string($errorData['error']) ? $errorData['error'] : json_encode($errorData['error']));
                }
            } catch (\Throwable) {
                try {
                    $raw = $response->getContent(false);
                    if ($raw !== '') {
                        $errorDetails = ': ' . substr($raw, 0, 200);
                    }
                } catch (\Throwable) {
                }
            }
            throw new \RuntimeException(sprintf('Scraper HTTP service returned HTTP %d%s', $response->getStatusCode(), $errorDetails));
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray();
        return (array) ($data['items'] ?? $data);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{
     *     setNo: string,
     *     amountCents: int,
     *     price: float,
     *     currency: string,
     *     retailer: string,
     *     provider: string,
     *     url: string,
     *     title?: string,
     *     type?: string
     * }>
     */
    private function scrapeViaPython(string $url, array $options): array
    {
        $scriptPath = $this->projectDir . '/scripts/camoufox_scraper.py';
        $cmd = ['python3', $scriptPath, '--url', $url, '--json'];

        if (($options['scroll'] ?? true) === false) {
            $cmd[] = '--no-scroll';
        }
        if (isset($options['max_steps'])) {
            $cmd[] = '--max-steps';
            $cmd[] = (string) $options['max_steps'];
        }

        $process = new Process($cmd, $this->projectDir);
        $process->setTimeout(90.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Python Camoufox scraper failed: ' . $process->getErrorOutput());
        }

        $rawOutput = trim($process->getOutput());
        if ($rawOutput === '') {
            return [];
        }

        return json_decode($rawOutput, true) ?? [];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{
     *     setNo: string,
     *     amountCents: int,
     *     price: float,
     *     currency: string,
     *     retailer: string,
     *     provider: string,
     *     url: string,
     *     title?: string,
     *     type?: string
     * }>
     */
    private function scrapeViaNode(string $url, array $options): array
    {
        $scriptPath = $this->projectDir . '/scripts/camoufox-scraper.js';
        $cmd = ['node', $scriptPath, '--url', $url, '--json'];

        if (($options['scroll'] ?? true) === false) {
            $cmd[] = '--no-scroll';
        }
        if (isset($options['max_steps'])) {
            $cmd[] = '--max-steps';
            $cmd[] = (string) $options['max_steps'];
        }

        $process = new Process($cmd, $this->projectDir);
        $process->setTimeout(90.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Node.js Playwright scraper failed: ' . $process->getErrorOutput());
        }

        $rawOutput = trim($process->getOutput());
        if ($rawOutput === '') {
            return [];
        }

        return json_decode($rawOutput, true) ?? [];
    }

    private function isPythonCamoufoxAvailable(): bool
    {
        $process = new Process(['python3', '-c', 'import camoufox'], $this->projectDir);
        $process->run();
        return $process->isSuccessful();
    }

    private function resolveProviderNameFromUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_contains($host, 'toppreise')) {
            return 'toppreise';
        }
        if (str_contains($host, 'galaxus') || str_contains($host, 'digitec')) {
            return 'galaxus';
        }
        if (str_contains($host, 'brack')) {
            return 'brack';
        }
        if (str_contains($host, 'amazon')) {
            return 'amazon';
        }
        if (str_contains($host, 'lego.com')) {
            return 'lego_store';
        }

        return 'scraper_default';
    }
}
