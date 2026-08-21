<?php

namespace App\Tests\Service;

use App\Entity\Piece;
use App\Entity\Set;
use App\Service\CsvLegoLoaderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CsvLegoLoaderServiceTest extends TestCase
{
    public function testNormalizeCsvPath(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = new NullLogger();
        $service = new CsvLegoLoaderService($em, $logger, '/app/data');

        $this->assertSame('/app/data/sets.csv', $service->normalizeCsvPath('sets'));
        $this->assertSame('/app/data/sets.csv', $service->normalizeCsvPath('sets.csv'));
    }

    public function testGetPieceFromAssocFixesQuantityBug(): void
    {
        // inventory_parts row: [0 => inventory_id, 1 => part_num, 2 => color_id, 3 => quantity, 4 => is_spare]
        $inventoryPartRow = [
            CsvLegoLoaderService::INVENTORY_PART_INVENTORY => '100',
            CsvLegoLoaderService::INVENTORY_PART_PART => '3001',
            CsvLegoLoaderService::INVENTORY_PART_COLOR => '15',
            CsvLegoLoaderService::INVENTORY_PART_QUANTITY => '8',
        ];

        // parts row: [0 => part_num, 1 => name, 2 => part_cat_id, 3 => part_material]
        $partRow = [
            CsvLegoLoaderService::PART_NUM_KEY => '3001',
            CsvLegoLoaderService::PART_NAME_KEY => 'Brick 2 x 4',
            CsvLegoLoaderService::PART_CAT_KEY => '11',
        ];

        $piece = CsvLegoLoaderService::getPieceFromAssoc($inventoryPartRow, $partRow);

        $this->assertInstanceOf(Piece::class, $piece);
        $this->assertSame('3001', $piece->getNo());
        $this->assertSame('Brick 2 x 4', $piece->getName());
        $this->assertSame(11, $piece->getCategory());
        $this->assertSame(15, $piece->getColor());
        // Verify count is 8 (the quantity), NOT '3001' (the part number)
        $this->assertSame(8, $piece->getCount());
    }

    public function testGetSetFromAssoc(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = new NullLogger();

        // Path to real data directory
        $dataPath = dirname(__DIR__, 2) . '/data';
        $service = new CsvLegoLoaderService($em, $logger, $dataPath);

        // sets row: [0 => set_num, 1 => name, 2 => year, 3 => theme_id, 4 => num_parts]
        $setRow = [
            CsvLegoLoaderService::SET_NUM_KEY => '001-1',
            CsvLegoLoaderService::SET_NAME_KEY => 'Gears',
            CsvLegoLoaderService::SET_YEAR_KEY => '1965',
            CsvLegoLoaderService::SET_THEME_KEY => '1',
            'is_obsolete' => false,
            'image_url' => 'https://example.com/001-1.jpg',
        ];

        $set = $service->getSetFromAssoc($setRow);

        $this->assertInstanceOf(Set::class, $set);
        $this->assertSame('001-1', $set->getNo());
        $this->assertSame('Gears', $set->getName());
        $this->assertSame(Set::SOURCE_REBRICKABLE, $set->getSource());
        $this->assertSame('https://example.com/001-1.jpg', $set->getImageUrl());
        $this->assertSame('1965', $set->getYear()?->format('Y'));
    }

    public function testLoadPriceForSetWithHttpClient(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $logger = new NullLogger();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('49.99');
        $httpClient->method('request')->willReturn($response);

        $service = new CsvLegoLoaderService($em, $logger, '/data', $httpClient);

        $price = $service->loadPriceForSet('75192-1');
        $this->assertSame(49.99, $price);
    }
}
