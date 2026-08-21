<?php

namespace App\Repository;

use App\Entity\Piece;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Piece>
 */
class PieceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Piece::class);
    }

    /**
     * @return array<int, mixed>
     */
    protected function findDistinct(string $what): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT ' . $what)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map('current', $result));
    }

    /**
     * @return array<int, mixed>
     */
    public function findDistinctColors(): array
    {
        return $this->findDistinct('p.color');
    }

    /**
     * @return array<int, mixed>
     */
    public function findDistinctCategories(): array
    {
        return $this->findDistinct('p.category');
    }

    /**
     * @return array<int, mixed>
     */
    public function findDistinctTypes(): array
    {
        return $this->findDistinct('p.type');
    }
}
