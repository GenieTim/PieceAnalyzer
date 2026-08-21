<?php

namespace App\Tests\Service;

use App\Entity\Piece;
use App\Entity\Set;
use App\Service\BricklinkLegoLoaderService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class BricklinkLegoLoaderServiceTest extends TestCase
{
    public function testGetPieceFromAssoc(): void
    {
        $pieceData = (object) [
            'color_id' => 1,
            'quantity' => 12,
            'item' => (object) [
                'name' => 'Plate 1x2',
                'no' => '3023',
                'categoryID' => 26,
                'type' => 'PART',
            ],
        ];

        $piece = BricklinkLegoLoaderService::getPieceFromAssoc($pieceData);

        $this->assertInstanceOf(Piece::class, $piece);
        $this->assertSame('3023', $piece->getNo());
        $this->assertSame('Plate 1x2', $piece->getName());
        $this->assertSame(26, $piece->getCategory());
        $this->assertSame(1, $piece->getColor());
        $this->assertSame('PART', $piece->getType());
        $this->assertSame(12, $piece->getCount());
    }

    public function testGetSetFromAssoc(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);

        $response->method('getContent')->willReturn(json_encode([
            'meta' => ['code' => 200],
            'data' => [
                'entries' => [
                    [
                        'color_id' => 11,
                        'quantity' => 4,
                        'item' => [
                            'name' => 'Wheel',
                            'no' => '56145',
                            'categoryID' => 10,
                            'type' => 'PART',
                        ],
                    ],
                ],
            ],
        ]));

        $httpClient->method('request')->willReturn($response);

        $credentials = [
            'consumer' => ['key' => 'test_key', 'secret' => 'test_secret'],
            'token' => ['value' => 'token_val', 'secret' => 'token_secret'],
        ];

        $service = new BricklinkLegoLoaderService($credentials, $em, $httpClient);

        $setData = (object) [
            'no' => '10265-1',
            'name' => 'Ford Mustang',
            'is_obsolete' => false,
            'image_url' => 'https://img.bricklink.com/ItemImage/SN/0/10265-1.png',
        ];

        $set = $service->getSetFromAssoc($setData);

        $this->assertInstanceOf(Set::class, $set);
        $this->assertSame('10265-1', $set->getNo());
        $this->assertSame('Ford Mustang', $set->getName());
        $this->assertSame(Set::SOURCE_BRICKLINK, $set->getSource());
        $this->assertFalse($set->getObsolete());
        $this->assertCount(1, $set->getPieces());
    }
}
