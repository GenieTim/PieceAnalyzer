<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Item;
use App\Entity\Piece;
use App\Entity\Set;
use App\Repository\ItemRepository;
use App\Service\RebrickableApiService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class RebrickableApiServiceTest extends TestCase
{
    public function testApiKeyGetterSetter(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new RebrickableApiService($em, new NullLogger(), null, 'test-key');

        $this->assertSame('test-key', $service->getApiKey());
        $service->setApiKey('new-key');
        $this->assertSame('new-key', $service->getApiKey());
    }

    public function testLoadSetSuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findAll')->willReturn([]);
        $em->method('getRepository')->willReturn($repo);

        $setData = [
            'set_num' => '75192-1',
            'name' => 'Millennium Falcon',
            'year' => 2017,
            'theme_id' => 158,
            'num_parts' => 7541,
            'set_img_url' => 'https://cdn.rebrickable.com/media/sets/75192-1.jpg',
            'is_obsolete' => false,
        ];

        $partsData = [
            'count' => 2,
            'next' => null,
            'results' => [
                [
                    'part' => [
                        'part_num' => '3001',
                        'name' => 'Brick 2 x 4',
                        'part_cat_id' => 11,
                    ],
                    'color' => [
                        'id' => 15,
                        'name' => 'White',
                    ],
                    'quantity' => 4,
                ],
                [
                    'part' => [
                        'part_num' => '3002',
                        'name' => 'Brick 2 x 3',
                        'part_cat_id' => 11,
                    ],
                    'color' => [
                        'id' => 1,
                        'name' => 'Blue',
                    ],
                    'quantity' => 8,
                ],
            ],
        ];

        $httpClient = $this->createMock(HttpClientInterface::class);

        $setResponse = $this->createMock(ResponseInterface::class);
        $setResponse->method('getStatusCode')->willReturn(200);
        $setResponse->method('getContent')->willReturn(json_encode($setData, JSON_THROW_ON_ERROR));

        $partsResponse = $this->createMock(ResponseInterface::class);
        $partsResponse->method('getStatusCode')->willReturn(200);
        $partsResponse->method('getContent')->willReturn(json_encode($partsData, JSON_THROW_ON_ERROR));

        $httpClient->method('request')->willReturnCallback(function (string $method, string $url) use ($setResponse, $partsResponse) {
            if (str_contains($url, '/parts/')) {
                return $partsResponse;
            }
            return $setResponse;
        });

        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new RebrickableApiService($em, new NullLogger(), $httpClient, 'api-key-123');
        $set = $service->loadSet('75192-1', true);

        $this->assertInstanceOf(Set::class, $set);
        $this->assertSame('75192-1', $set->getNo());
        $this->assertSame('Millennium Falcon', $set->getName());
        $this->assertSame(Set::SOURCE_REBRICKABLE, $set->getSource());
        $this->assertSame('2017', $set->getYear()?->format('Y'));
        $this->assertSame('https://cdn.rebrickable.com/media/sets/75192-1.jpg', $set->getImageUrl());

        $pieces = $set->getPieces();
        $this->assertCount(2, $pieces);

        /** @var Piece $piece1 */
        $piece1 = $pieces[0];
        $this->assertSame('3001', $piece1->getNo());
        $this->assertSame('Brick 2 x 4', $piece1->getName());
        $this->assertSame(11, $piece1->getCategory());
        $this->assertSame(15, $piece1->getColor());
        $this->assertSame(4, $piece1->getCount());

        /** @var Piece $piece2 */
        $piece2 = $pieces[1];
        $this->assertSame('3002', $piece2->getNo());
        $this->assertSame('Brick 2 x 3', $piece2->getName());
        $this->assertSame(11, $piece2->getCategory());
        $this->assertSame(1, $piece2->getColor());
        $this->assertSame(8, $piece2->getCount());
    }

    public function testLoadSetReturnsNullOn404(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findAll')->willReturn([]);
        $em->method('getRepository')->willReturn($repo);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $httpClient->method('request')->willReturn($response);

        $service = new RebrickableApiService($em, new NullLogger(), $httpClient, 'api-key-123');
        $set = $service->loadSet('invalid-set');

        $this->assertNull($set);
    }

    public function testLoadSetsIteratesPages(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findAll')->willReturn([]);
        $em->method('getRepository')->willReturn($repo);

        $setsList = [
            'count' => 1,
            'results' => [
                [
                    'set_num' => '1001-1',
                    'name' => 'Test Set',
                    'year' => 2020,
                    'num_parts' => 10,
                ],
            ],
        ];

        $setItem = [
            'set_num' => '1001-1',
            'name' => 'Test Set',
            'year' => 2020,
        ];

        $partsData = [
            'count' => 0,
            'results' => [],
        ];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $listResponse = $this->createMock(ResponseInterface::class);
        $listResponse->method('getStatusCode')->willReturn(200);
        $listResponse->method('getContent')->willReturn(json_encode($setsList, JSON_THROW_ON_ERROR));

        $itemResponse = $this->createMock(ResponseInterface::class);
        $itemResponse->method('getStatusCode')->willReturn(200);
        $itemResponse->method('getContent')->willReturn(json_encode($setItem, JSON_THROW_ON_ERROR));

        $partsResponse = $this->createMock(ResponseInterface::class);
        $partsResponse->method('getStatusCode')->willReturn(200);
        $partsResponse->method('getContent')->willReturn(json_encode($partsData, JSON_THROW_ON_ERROR));

        $httpClient->method('request')->willReturnCallback(function (string $method, string $url, array $options = []) use ($listResponse, $itemResponse, $partsResponse) {
            if (isset($options['query']['page'])) {
                return $listResponse;
            }
            if (str_contains($url, '/parts/')) {
                return $partsResponse;
            }
            return $itemResponse;
        });

        $service = new RebrickableApiService($em, new NullLogger(), $httpClient, 'api-key');
        $result = $service->loadSets(1, 1);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Set::class, $result[0]);
    }

    public function testGetPartAndPartImageUrl(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $partResponse = $this->createMock(ResponseInterface::class);
        $partResponse->method('getStatusCode')->willReturn(200);
        $partResponse->method('getContent')->willReturn(json_encode([
            'part_num' => '3001',
            'name' => 'Brick 2 x 4',
            'part_img_url' => 'https://cdn.rebrickable.com/media/parts/elements/3001.jpg',
        ], JSON_THROW_ON_ERROR));

        $colorResponse = $this->createMock(ResponseInterface::class);
        $colorResponse->method('getStatusCode')->willReturn(200);
        $colorResponse->method('getContent')->willReturn(json_encode([
            'part_img_url' => 'https://cdn.rebrickable.com/media/parts/elements/300115.jpg',
        ], JSON_THROW_ON_ERROR));

        $httpClient->method('request')->willReturnCallback(function (string $method, string $url) use ($partResponse, $colorResponse) {
            if (str_contains($url, '/colors/15/')) {
                return $colorResponse;
            }
            return $partResponse;
        });

        $service = new RebrickableApiService($em, new NullLogger(), $httpClient, 'api-key');

        $part = $service->getPart('3001');
        $this->assertIsArray($part);
        $this->assertSame('Brick 2 x 4', $part['name']);

        $imageUrlWithColor = $service->getPartImageUrl('3001', 15);
        $this->assertSame('https://cdn.rebrickable.com/media/parts/elements/300115.jpg', $imageUrlWithColor);

        $imageUrlDefault = $service->getPartImageUrl('3001');
        $this->assertSame('https://cdn.rebrickable.com/media/parts/elements/3001.jpg', $imageUrlDefault);
    }

    public function testGetColorsAndCategories(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);

        $colorsResponse = $this->createMock(ResponseInterface::class);
        $colorsResponse->method('getStatusCode')->willReturn(200);
        $colorsResponse->method('getContent')->willReturn(json_encode([
            'results' => [
                ['id' => 1, 'name' => 'Blue', 'rgb' => '0055BF'],
            ],
            'next' => null,
        ], JSON_THROW_ON_ERROR));

        $themesResponse = $this->createMock(ResponseInterface::class);
        $themesResponse->method('getStatusCode')->willReturn(200);
        $themesResponse->method('getContent')->willReturn(json_encode([
            'results' => [
                ['id' => 158, 'name' => 'Star Wars'],
            ],
            'next' => null,
        ], JSON_THROW_ON_ERROR));

        $httpClient->method('request')->willReturnCallback(function (string $method, string $url) use ($colorsResponse, $themesResponse) {
            if (str_contains($url, '/colors/')) {
                return $colorsResponse;
            }
            return $themesResponse;
        });

        $service = new RebrickableApiService($em, new NullLogger(), $httpClient, 'api-key');

        $colors = $service->getColors();
        $this->assertCount(1, $colors);
        $this->assertSame('Blue', $colors[0]['name']);

        $categories = $service->getCategories();
        $this->assertCount(1, $categories);
        $this->assertSame('Star Wars', $categories[0]['name']);
    }

    public function testLoadItemLocallyReturnsExistingSet(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(ItemRepository::class);

        $existingSet = new Set();
        $existingSet->setNo('75192-1');
        $existingSet->setName('Cached Falcon');

        $repo->method('findAll')->willReturn([$existingSet]);
        $em->method('getRepository')->willReturn($repo);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $service = new RebrickableApiService($em, new NullLogger(), $httpClient, 'api-key');
        $result = $service->loadSet('75192-1');

        $this->assertSame($existingSet, $result);
    }
}
