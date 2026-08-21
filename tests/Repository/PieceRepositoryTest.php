<?php

namespace App\Tests\Repository;

use App\Entity\Piece;
use App\Repository\PieceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class PieceRepositoryTest extends TestCase
{
    public function testFindDistinctMethods(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $classMetadata = new ClassMetadata(Piece::class);

        $registry->method('getManagerForClass')->willReturn($em);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();

        $em->method('createQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getScalarResult')->willReturn([
            ['color' => 1],
            ['color' => 2],
            ['color' => 5],
        ]);

        $repo = new PieceRepository($registry);

        $colors = $repo->findDistinctColors();
        $this->assertSame([1, 2, 5], $colors);
    }
}
