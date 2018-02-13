<?php

namespace App\Repository;

use App\Entity\Set;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

class SetRepository extends ServiceEntityRepository
{
    
    private $allowedCriteria = array(
        'color', 'category'
    );
    
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Set::class);
    }
    
   private function getMostValuableQueryBuilder($limit = 10) {
       $qb = $this->createQueryBuilder('s');
       $qb->leftJoin('s.pieces', 'p')->addSelect('p');
       $qb->groupBy('s.id');
       $qb->addSelect('COUNT(p.id) AS HIDDEN num_p');
       $qb->orderBy('s.price / num_p', 'ASC');
       $qb->setMaxResults($limit);
       return $qb;
   }
   
   public function findMostValuableBy(array $criteria = array(), $limit = 10) {
       $qb = $this->getMostValuableQueryBuilder($limit);
       foreach ($criteria as $key => $value) {
           // assert or just continue?
           if(!in_array($key, $this->allowedCriteria)) {
               continue;
           }
           $qb->andWhere("p.$key = :$key");
           $qb->setParameter($key, $value);
       }
       return $qb->getQuery()->getResult();
   }
}
