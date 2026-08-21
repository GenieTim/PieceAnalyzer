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
}
