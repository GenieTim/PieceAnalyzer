<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Set;
use App\Service\BricksetPriceLoaderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class BricksetPriceLoaderServiceTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new BricksetPriceLoaderService($em, new NullLogger(), null, 'my-key', 'USD');

        $this->assertSame('my-key', $service->getApiKey());
        $this->assertSame('USD', $service->getDefaultCurrency());

        $service->setApiKey('new-key');
        $service->setDefaultCurrency('gbp');

        $this->assertSame('new-key', $service->getApiKey());
        $this->assertSame('GBP', $service->getDefaultCurrency());
    }

    public function testLoadPricesForSetFromApi(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $apiResponseData = [
            'status' => 'success',
            'matches' => 1,
            'sets' => [
                [
                    'setID' => 28073,
                    'number' => '75192',
                    'numberVariant' => '1',
                    'name' => 'Millennium Falcon',
                    'LEGOCom' => [
                        'US' => ['retailPrice' => 849.99],
                        'UK' => ['retailPrice' => 734.99],
                        'DE' => ['retailPrice' => 849.99],
                        'CA' => ['retailPrice' => 1049.99],
                    ],
                ],
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn(json_encode($apiResponseData, JSON_THROW_ON_ERROR));

        $httpClient->method('request')
            ->willReturn($response);

        $service = new BricksetPriceLoaderService($em, new NullLogger(), $httpClient, 'brickset-key', 'EUR');

        $prices = $service->loadPricesForSet('75192-1');

        $this->assertSame([
            'EUR' => 849.99,
            'USD' => 849.99,
            'GBP' => 734.99,
            'CAD' => 1049.99,
        ], $prices);

        $this->assertSame(849.99, $service->loadPriceForSet('75192-1'));
        $this->assertSame(734.99, $service->loadPriceForSetInCurrency('75192-1', 'GBP'));
        $this->assertSame(1049.99, $service->loadPriceForSetInCurrency('75192-1', 'CAD'));
        $this->assertNull($service->loadPriceForSetInCurrency('75192-1', 'JPY'));
    }

    public function testLoadPricesForSetFromWebScraping(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $html = '<!DOCTYPE html><html><body><div class="featureblock"><dl><dt>RRP</dt><dd>$849.99 / 849.99€ / £734.99</dd></dl></div></body></html>';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($html);

        $httpClient->method('request')
            ->willReturn($response);

        // API key empty -> directly uses web scraping
        $service = new BricksetPriceLoaderService($em, new NullLogger(), $httpClient, '', 'EUR');

        $prices = $service->loadPricesForSet('75192');

        $this->assertSame([
            'USD' => 849.99,
            'GBP' => 734.99,
            'EUR' => 849.99,
        ], $prices);

        $this->assertSame(849.99, $service->loadPriceForSet('75192'));
        $this->assertSame(734.99, $service->loadPriceForSetInCurrency('75192', 'GBP'));
    }

    public function testParsePricesFromHtmlVariants(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new BricksetPriceLoaderService($em, new NullLogger(), null);

        $html1 = '<dl><dt>RRP</dt><dd>£17.99 / $19.99 / 19.99€ / 24.99 CAD</dd></dl>';
        $prices1 = $service->parsePricesFromHtml($html1);
        $this->assertSame(19.99, $prices1['USD'] ?? null);
        $this->assertSame(17.99, $prices1['GBP'] ?? null);
        $this->assertSame(19.99, $prices1['EUR'] ?? null);
        $this->assertSame(24.99, $prices1['CAD'] ?? null);

        $html2 = '<dl><dt>RRP</dt><dd>€49,99 / $59,99</dd></dl>';
        $prices2 = $service->parsePricesFromHtml($html2);
        $this->assertSame(49.99, $prices2['EUR'] ?? null);
        $this->assertSame(59.99, $prices2['USD'] ?? null);

        $htmlEmpty = '<dl><dt>Other</dt><dd>None</dd></dl>';
        $pricesEmpty = $service->parsePricesFromHtml($htmlEmpty);
        $this->assertSame([], $pricesEmpty);
    }

    public function testLoadPricesBatchUpdatesDatabase(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $set = new Set();
        $set->setNo('75192-1');
        $set->setName('Millennium Falcon');

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toIterable'])
            ->getMock();

        $query->method('toIterable')->willReturn(new \ArrayIterator([$set]));
        $em->method('createQuery')->willReturn($query);

        $apiResponseData = [
            'status' => 'success',
            'matches' => 1,
            'sets' => [
                [
                    'setID' => 28073,
                    'LEGOCom' => [
                        'DE' => ['retailPrice' => 849.99],
                    ],
                ],
            ],
        ];

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn(json_encode($apiResponseData, JSON_THROW_ON_ERROR));
        $httpClient->method('request')->willReturn($response);

        $em->expects($this->atLeastOnce())->method('persist')->with($set);
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new BricksetPriceLoaderService($em, new NullLogger(), $httpClient, 'api-key', 'EUR');
        $service->loadPrices(false);

        $this->assertSame(849.99, $set->getPrice());
    }

    public function testLoadPriceForSetReturnsNullWhenNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $httpClient->method('request')->willReturn($response);

        $service = new BricksetPriceLoaderService($em, new NullLogger(), $httpClient, '', 'EUR');
        $price = $service->loadPriceForSet('99999-1');

        $this->assertNull($price);
    }

    public function testWebScraping429RetrySuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $response429 = $this->createMock(ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn(['retry-after' => ['3']]);

        $html = '<!DOCTYPE html><html><body><div class="featureblock"><dl><dt>RRP</dt><dd>$19.99 / 19.99€</dd></dl></div></body></html>';
        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('getStatusCode')->willReturn(200);
        $response200->method('getContent')->willReturn($html);

        $calls = 0;
        $httpClient->method('request')->willReturnCallback(function () use (&$calls, $response429, $response200): \PHPUnit\Framework\MockObject\MockObject {
            ++$calls;
            return $calls === 1 ? $response429 : $response200;
        });

        $slept = [];
        $service = new BricksetPriceLoaderService($em, new NullLogger(), $httpClient, '', 'EUR');
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $prices = $service->loadPricesForSet('10047-1');

        $this->assertSame(2, $calls);
        $this->assertSame([3], $slept);
        $this->assertSame(['USD' => 19.99, 'EUR' => 19.99], $prices);
        $this->assertFalse($service->isRateLimited());
    }

    public function testWebScraping429PersistentEntersCooldown(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $response429 = $this->createMock(ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn([]);

        $httpClient->method('request')->willReturn($response429);

        $slept = [];
        $service = new BricksetPriceLoaderService($em, new NullLogger(), $httpClient, '', 'EUR');
        $service->setMaxRetries(2);
        $service->setBaseDelaySeconds(1);
        $service->setDefaultCooldownSeconds(60);
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $prices = $service->loadPricesForSet('10047-1');

        $this->assertSame([], $prices);
        $this->assertSame([1, 2], $slept);
        $this->assertTrue($service->isRateLimited());
    }

    public function testApi429RetrySuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $response429 = $this->createMock(ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn(['retry-after' => ['2']]);

        $apiData = [
            'status' => 'success',
            'matches' => 1,
            'sets' => [
                [
                    'setID' => 1234,
                    'LEGOCom' => [
                        'DE' => ['retailPrice' => 99.99],
                    ],
                ],
            ],
        ];
        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('getStatusCode')->willReturn(200);
        $response200->method('getContent')->willReturn(json_encode($apiData, JSON_THROW_ON_ERROR));

        $calls = 0;
        $httpClient->method('request')->willReturnCallback(function () use (&$calls, $response429, $response200): \PHPUnit\Framework\MockObject\MockObject {
            ++$calls;
            return $calls === 1 ? $response429 : $response200;
        });

        $slept = [];
        $service = new BricksetPriceLoaderService($em, new NullLogger(), $httpClient, 'api-key', 'EUR');
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $prices = $service->loadPricesForSet('1234-1');

        $this->assertSame(2, $calls);
        $this->assertSame([2], $slept);
        $this->assertSame(['EUR' => 99.99], $prices);
        $this->assertFalse($service->isRateLimited());
    }

    public function testParseCurrencyAndAmount(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new BricksetPriceLoaderService($em, new NullLogger());

        $this->assertSame(['currency' => 'CHF', 'amount' => 223.0], $service->parseCurrencyAndAmount('~Fr223'));
        $this->assertSame(['currency' => 'CHF', 'amount' => 63.50], $service->parseCurrencyAndAmount('Fr. 63.50'));
        $this->assertSame(['currency' => 'CHF', 'amount' => 79.80], $service->parseCurrencyAndAmount('~Fr79.80'));
        $this->assertSame(['currency' => 'USD', 'amount' => 29.99], $service->parseCurrencyAndAmount('$29.99'));
        $this->assertSame(['currency' => 'GBP', 'amount' => 26.99], $service->parseCurrencyAndAmount('£26.99'));
        $this->assertSame(['currency' => 'EUR', 'amount' => 49.99], $service->parseCurrencyAndAmount('49,99€'));
        $this->assertSame(['currency' => 'CAD', 'amount' => 34.99], $service->parseCurrencyAndAmount('CA$34.99'));
        $this->assertNull($service->parseCurrencyAndAmount('N/A'));
        $this->assertNull($service->parseCurrencyAndAmount(''));
    }

    public function testParseDetailedPricesFromHtmlWithTurboShredderSample(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new BricksetPriceLoaderService($em, new NullLogger());

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta property="og:image" content="https://images.brickset.com/sets/images/2263-1.jpg" />
</head>
<body>
    <div class="featureblock">
        <dl>
            <dt>RRP</dt>
            <dd>£26.99, $29.99</dd>
            <dt>Current value</dt>
            <dd>New: ~Fr223, Used: ~Fr63</dd>
        </dl>
    </div>
    <div class="bricklink-box">
        <h4>bricklink</h4>
        <p>BUY THIS SET: New ~Fr208, Used ~Fr74</p>
        <p>SWITZERLAND: N/A, N/A</p>
        <p>EUROPEAN UNION: N/A, ~Fr79.80</p>
    </div>
</body>
</html>
HTML;

        $detailed = $service->parseDetailedPricesFromHtml($html, '2263-1');

        $this->assertNotEmpty($detailed);

        // Find MSRP prices
        $msrpUsd = array_values(array_filter($detailed, fn($p): bool => $p['condition'] === 'msrp' && $p['currency'] === 'USD'));
        $this->assertCount(1, $msrpUsd);
        $this->assertSame(29.99, $msrpUsd[0]['amount']);
        $this->assertSame(\App\Enum\Retailer::LEGO_STORE, $msrpUsd[0]['retailer']);

        $msrpGbp = array_values(array_filter($detailed, fn($p): bool => $p['condition'] === 'msrp' && $p['currency'] === 'GBP'));
        $this->assertCount(1, $msrpGbp);
        $this->assertSame(26.99, $msrpGbp[0]['amount']);

        // Find Current Market Value (New: ~Fr223, Used: ~Fr63)
        $marketNew = array_values(array_filter($detailed, fn($p): bool => $p['condition'] === 'new' && $p['retailer'] === \App\Enum\Retailer::OTHER));
        $this->assertCount(1, $marketNew);
        $this->assertSame('CHF', $marketNew[0]['currency']);
        $this->assertSame(223.0, $marketNew[0]['amount']);

        $marketUsed = array_values(array_filter($detailed, fn($p): bool => $p['condition'] === 'used' && $p['retailer'] === \App\Enum\Retailer::OTHER));
        $this->assertCount(1, $marketUsed);
        $this->assertSame('CHF', $marketUsed[0]['currency']);
        $this->assertSame(63.0, $marketUsed[0]['amount']);

        // Find BrickLink prices (New ~Fr208, Used ~Fr74)
        $blNew = array_values(array_filter($detailed, fn($p): bool => $p['condition'] === 'new' && $p['retailer'] === \App\Enum\Retailer::BRICKLINK));
        $this->assertCount(1, $blNew);
        $this->assertSame('CHF', $blNew[0]['currency']);
        $this->assertSame(208.0, $blNew[0]['amount']);
        $this->assertStringContainsString('bricklink.com', $blNew[0]['url']);

        $blUsed = array_values(array_filter($detailed, fn($p): bool => $p['condition'] === 'used' && $p['retailer'] === \App\Enum\Retailer::BRICKLINK));
        $this->assertCount(1, $blUsed);
        $this->assertSame('CHF', $blUsed[0]['currency']);
        $this->assertSame(74.0, $blUsed[0]['amount']);

        // Check image URL
        $this->assertSame('https://images.brickset.com/sets/images/2263-1.jpg', $detailed[0]['imageUrl']);
    }
}
