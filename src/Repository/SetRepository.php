<?php

namespace App\Repository;

use App\Entity\Set;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Set>
 */
class SetRepository extends ServiceEntityRepository
{
    /** @var string[] */
    private array $allowedCriteria = ['color', 'category', 'type'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Set::class);
    }

    private function getMostValuableQueryBuilder(int $limit = 10): QueryBuilder
    {
        $qb = $this->createQueryBuilder('s');
        $qb->leftJoin('s.pieces', 'p')->addSelect('p');
        $qb->andWhere('s.price IS NOT NULL');
        $qb->andWhere('s.price != 0');
        $qb->groupBy('s.id');
        $qb->orderBy('s.price / SUM(p.count)', 'ASC');
        $qb->having('SUM(p.count) != 0');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        return $qb;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<int, Set>
     */
    public function findMostValuableBy(array $criteria = [], int $limit = 10): array
    {
        return $this->getMostValuableByQuery($criteria, $limit)->getResult();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function getMostValuableByQuery(array $criteria = [], int $limit = 0): Query
    {
        $qb = $this->getMostValuableQueryBuilder($limit);
        foreach ($criteria as $key => $value) {
            if (!in_array($key, $this->allowedCriteria, true)) {
                continue;
            }
            if ($value === 0 || $value === '0' || $value === null || $value === '') {
                continue;
            }
            $qb->andWhere("p.$key = :$key");
            $qb->setParameter($key, $value);
        }
        return $qb->getQuery();
    }
}
