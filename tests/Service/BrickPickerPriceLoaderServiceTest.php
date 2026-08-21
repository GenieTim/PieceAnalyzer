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

        $service = new class($em, $logger) extends BrickPickerPriceLoaderService {
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
}
