<?php

namespace App\Tests\Repository;

use App\Entity\Piece;
use App\Entity\Set;
use App\Repository\PieceRepository;
use App\Repository\SetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class SetRepositoryTest extends TestCase
{
    public function testGetMostValuableByQuery(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $classMetadata = new ClassMetadata(Set::class);

        $registry->method('getManagerForClass')->willReturn($em);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->getMock();

        $em->method('createQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('addSelect')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('groupBy')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('having')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = new SetRepository($registry);
        $resultQuery = $repo->getMostValuableByQuery(['color' => 1, 'category' => 2, 'invalid_key' => 'skip'], 10);

        $this->assertSame($query, $resultQuery);
    }
}
