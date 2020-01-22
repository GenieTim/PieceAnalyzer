<?php

namespace App\Repository;

use App\Entity\Piece;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

class PieceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Piece::class);
    }

    protected function findDistinct($what)
    {
        return $this->createQueryBuilder('p')
            ->select('distinct(' . $what . ')')->getQuery()->getResult();
    }

    public function findDistinctColors()
    {
        return $this->findDistinct('p.color');
    }

    public function findDistinctCategories()
    {
        return $this->findDistinct('p.category');
    }

    public function findDistinctTypes()
    {
        return $this->findDistinct('p.type');
    }
}
