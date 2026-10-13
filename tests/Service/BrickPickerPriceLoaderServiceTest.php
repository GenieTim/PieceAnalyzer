<?php

namespace App\Tests\Service;

use App\Service\BrickPickerPriceLoaderService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class BrickPickerPriceLoaderServiceTest extends TestCase
{
    public function testFindPrice(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = new NullLogger();

        $service = new class ($em, $logger) extends BrickPickerPriceLoaderService {
            public function testFindPriceMethod(?string $str): ?float
            {
                return $this->findPrice($str);
            }
        };

        $this->assertSame(99.99, $service->testFindPriceMethod('$99.99'));
        $this->assertSame(150.0, $service->testFindPriceMethod('Retail Price: $150.00'));
        $this->assertSame(25.5, $service->testFindPriceMethod('25.50'));
        $this->assertNull($service->testFindPriceMethod('No price available'));
        $this->assertNull($service->testFindPriceMethod(null));
    }

    public function test429RetrySuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);

        $response429 = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn(['retry-after' => ['2']]);

        $html = '<div class="product-detail"><div class="retail-price"><ul><li>Retail: $79.99</li></ul></div></div>';
        $response200 = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response200->method('getStatusCode')->willReturn(200);
        $response200->method('getContent')->willReturn($html);

        $calls = 0;
        $httpClient->method('request')->willReturnCallback(function () use (&$calls, $response429, $response200): \PHPUnit\Framework\MockObject\MockObject {
            ++$calls;
            return $calls === 1 ? $response429 : $response200;
        });

        $slept = [];
        $service = new BrickPickerPriceLoaderService($em, new NullLogger(), $httpClient);
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $price = $service->loadPriceForSet('10242-1');

        $this->assertSame(2, $calls);
        $this->assertSame([2], $slept);
        $this->assertSame(79.99, $price);
        $this->assertFalse($service->isRateLimited());
    }

    public function test429PersistentEntersCooldown(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);

        $response429 = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn([]);

        $httpClient->method('request')->willReturn($response429);

        $slept = [];
        $service = new BrickPickerPriceLoaderService($em, new NullLogger(), $httpClient);
        $service->setMaxRetries(2);
        $service->setBaseDelaySeconds(1);
        $service->setDefaultCooldownSeconds(60);
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $price = $service->loadPriceForSet('10242-1');

        $this->assertNull($price);
        $this->assertSame([1, 2], $slept);
        $this->assertTrue($service->isRateLimited());
    }

    public function testNon200ReturnsNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class);

        $response500 = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response500->method('getStatusCode')->willReturn(500);

        $httpClient->method('request')->willReturn($response500);

        $service = new BrickPickerPriceLoaderService($em, new NullLogger(), $httpClient);
        $price = $service->loadPriceForSet('10242-1');

        $this->assertNull($price);
    }
}
