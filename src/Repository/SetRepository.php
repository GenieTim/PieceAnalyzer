<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\PriceVerificationStatus;
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

    public function __construct(
        ManagerRegistry $registry,
        private readonly \App\Service\ExchangeRateService $exchangeRateService = new \App\Service\ExchangeRateService()
    ) {
        parent::__construct($registry, Set::class);
    }

    private function getMostValuableQueryBuilder(
        int $limit = 10,
        ?User $currentUser = null,
        ?string $targetCurrency = null
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('s');
        $target = $targetCurrency !== null
            ? strtoupper(trim($targetCurrency))
            : ($currentUser?->getPreferredCurrency() ?? 'CHF');

        $eurRate = number_format($this->exchangeRateService->getRate('EUR', $target), 6, '.', '');
        $usdRate = number_format($this->exchangeRateService->getRate('USD', $target), 6, '.', '');
        $gbpRate = number_format($this->exchangeRateService->getRate('GBP', $target), 6, '.', '');
        $chfRate = number_format($this->exchangeRateService->getRate('CHF', $target), 6, '.', '');

        $normPrice = "(CASE
            WHEN s.priceCurrency = 'EUR' THEN (s.price * $eurRate)
            WHEN s.priceCurrency = 'USD' THEN (s.price * $usdRate)
            WHEN s.priceCurrency = 'GBP' THEN (s.price * $gbpRate)
            ELSE (s.price * $chfRate)
        END)";

        if ($currentUser instanceof User && $currentUser->getId() !== null) {
            $qb->leftJoin('s.prices', 'sp', 'WITH', 'sp.submittedBy = :currentUser AND sp.status != :rejectedStatus');
            $distinctPriceFactor = '(CASE WHEN COUNT(DISTINCT sp.id) > 0 THEN COUNT(DISTINCT sp.id) ELSE 1 END)';
            $qb->addSelect("(SUM(p.count) / $distinctPriceFactor) AS HIDDEN pieceCount");
            $pricePerPieceExpr = "((COALESCE(MIN(sp.amount) / 100.0, $normPrice)) "
                . "/ (SUM(p.count) / $distinctPriceFactor)) AS HIDDEN pricePerPiece";
            $qb->addSelect($pricePerPieceExpr);
            $unpricedExpr = '(CASE WHEN (s.price IS NULL OR s.price = 0) AND MIN(sp.amount) IS NULL '
                . 'THEN 1 ELSE 0 END) AS HIDDEN isUnpriced';
            $qb->addSelect($unpricedExpr);
            $qb->setParameter('currentUser', $currentUser);
            $qb->setParameter('rejectedStatus', PriceVerificationStatus::REJECTED);
        } else {
            $qb->addSelect('SUM(p.count) AS HIDDEN pieceCount');
            $qb->addSelect("($normPrice / SUM(p.count)) AS HIDDEN pricePerPiece");
            $qb->addSelect('(CASE WHEN s.price IS NULL OR s.price = 0 THEN 1 ELSE 0 END) AS HIDDEN isUnpriced');
        }

        $qb->leftJoin('s.pieces', 'p');
        $qb->groupBy('s.id, s.no, s.name, s.price, s.priceCurrency, s.source, s.obsolete, s.year, s.image_url');
        $qb->orderBy('isUnpriced', 'ASC');
        $qb->addOrderBy('pricePerPiece', 'ASC');
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
    public function findMostValuableBy(
        array $criteria = [],
        int $limit = 10,
        ?User $currentUser = null,
        ?string $targetCurrency = null
    ): array {
        return $this->getMostValuableByQuery($criteria, $limit, $currentUser, $targetCurrency)->getResult();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function getMostValuableByQuery(
        array $criteria = [],
        int $limit = 0,
        ?User $currentUser = null,
        ?string $targetCurrency = null
    ): Query {
        $qb = $this->getMostValuableQueryBuilder($limit, $currentUser, $targetCurrency);
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
