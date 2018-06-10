<?php

namespace App\Repository;

use App\Entity\Set;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SetRepository extends ServiceEntityRepository
{

    private $allowedCriteria = array(
        'color', 'category', 'type'
    );

    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Set::class);
    }

    private function getMostValuableQueryBuilder($limit = 10)
    {
        $qb = $this->createQueryBuilder('s');
        $qb->leftJoin('s.pieces', 'p')->addSelect('p');
        $qb->andWhere('s.price IS NOT NULL');
        $qb->andWhere('s.price != 0');
        $qb->groupBy('s.id');
        $qb->orderBy('s.price / SUM(p.count)', 'ASC');
        $qb->having('SUM(p.count) != 0');
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        return $qb;
    }

    public function findMostValuableBy(array $criteria = array(), $limit = 10)
    {
        return $this->getMostValuableByQuery($criteria, $limit)->getResult();
    }

    public function getMostValuableByQuery(array $criteria = array(), $limit = 0)
    {
        $qb = $this->getMostValuableQueryBuilder($limit);
        foreach ($criteria as $key => $value) {
            // assert or just continue?
            if (!in_array($key, $this->allowedCriteria)) {
                continue;
            }
            // skip unknown
            if ($value === 0) {
                continue;
            }
            $qb->andWhere("p.$key = :$key");
            $qb->setParameter($key, $value);
        }
        return $qb->getQuery();
    }
}
