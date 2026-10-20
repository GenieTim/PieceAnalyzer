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
    private array $allowedCriteria = [
        'color', 'category', 'type', 'part', 'partNo', 'year', 'minYear', 'maxYear',
        'set', 'setId', 'setNo', 'name', 'excludeUsed',
    ];

    public function __construct(
        ManagerRegistry $registry,
        private readonly \App\Service\ExchangeRateService $exchangeRateService = new \App\Service\ExchangeRateService()
    ) {
        parent::__construct($registry, Set::class);
    }

    private function getMostValuableQueryBuilder(
        int $limit = 10,
        ?User $currentUser = null,
        ?string $targetCurrency = null,
        bool $excludeUsed = false
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

        $normUserPrice = "(CASE
            WHEN sp.currency = 'EUR' THEN ((sp.amount / 100.0) * $eurRate)
            WHEN sp.currency = 'USD' THEN ((sp.amount / 100.0) * $usdRate)
            WHEN sp.currency = 'GBP' THEN ((sp.amount / 100.0) * $gbpRate)
            ELSE ((sp.amount / 100.0) * $chfRate)
        END)";

        if ($excludeUsed) {
            $qb->leftJoin('s.prices', 'sp_valid', 'WITH', "sp_valid.status = :verifiedStatus AND (sp_valid.itemCondition IS NULL OR sp_valid.itemCondition != 'used')");
            $qb->setParameter('verifiedStatus', PriceVerificationStatus::VERIFIED);
            $normValidPrice = "(CASE
                WHEN sp_valid.currency = 'EUR' THEN ((sp_valid.amount / 100.0) * $eurRate)
                WHEN sp_valid.currency = 'USD' THEN ((sp_valid.amount / 100.0) * $usdRate)
                WHEN sp_valid.currency = 'GBP' THEN ((sp_valid.amount / 100.0) * $gbpRate)
                ELSE ((sp_valid.amount / 100.0) * $chfRate)
            END)";
            $normGeneralPrice = "MIN($normValidPrice)";
            $unpricedCondition = 'MIN(sp_valid.amount) IS NULL';
        } else {
            $normGeneralPrice = $normPrice;
            $unpricedCondition = '(s.price IS NULL OR s.price = 0)';
        }

        if ($currentUser instanceof User && $currentUser->getId() !== null) {
            $userPriceCondition = 'sp.submittedBy = :currentUser AND sp.status != :rejectedStatus';
            if ($excludeUsed) {
                $userPriceCondition .= " AND (sp.itemCondition IS NULL OR sp.itemCondition != 'used')";
            }
            $qb->leftJoin('s.prices', 'sp', 'WITH', $userPriceCondition);
            $distinctPriceFactor = '(CASE WHEN COUNT(DISTINCT sp.id) > 0 THEN COUNT(DISTINCT sp.id) ELSE 1 END)';
            $qb->addSelect("(SUM(p.count) / $distinctPriceFactor) AS HIDDEN pieceCount");
            $pricePerPieceExpr = "((COALESCE(MIN($normUserPrice), $normGeneralPrice)) "
                . "/ (SUM(p.count) / $distinctPriceFactor)) AS HIDDEN pricePerPiece";
            $qb->addSelect($pricePerPieceExpr);
            $unpricedExpr = "(CASE WHEN $unpricedCondition AND MIN(sp.amount) IS NULL "
                . 'THEN 1 ELSE 0 END) AS HIDDEN isUnpriced';
            $qb->addSelect($unpricedExpr);
            $qb->setParameter('currentUser', $currentUser);
            $qb->setParameter('rejectedStatus', PriceVerificationStatus::REJECTED);
        } else {
            $qb->addSelect('SUM(p.count) AS HIDDEN pieceCount');
            $qb->addSelect("($normGeneralPrice / SUM(p.count)) AS HIDDEN pricePerPiece");
            $qb->addSelect("(CASE WHEN $unpricedCondition THEN 1 ELSE 0 END) AS HIDDEN isUnpriced");
        }

        $qb->leftJoin('s.pieces', 'p');
        $qb->groupBy('s.id, s.no, s.name, s.price, s.priceCurrency, s.pieceCount, s.source, s.obsolete, s.year, s.image_url');
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
        ?string $targetCurrency = null,
        bool $excludeUsed = false
    ): array {
        return $this->getMostValuableByQuery($criteria, $limit, $currentUser, $targetCurrency, $excludeUsed)->getResult();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function getMostValuableByQuery(
        array $criteria = [],
        int $limit = 0,
        ?User $currentUser = null,
        ?string $targetCurrency = null,
        bool $excludeUsed = false
    ): Query {
        if (isset($criteria['excludeUsed'])) {
            $excludeUsed = (bool) $criteria['excludeUsed'];
        }

        $qb = $this->getMostValuableQueryBuilder($limit, $currentUser, $targetCurrency, $excludeUsed);

        $setFilter = isset($criteria['setId']) && $criteria['setId'] !== '' && $criteria['setId'] !== null
            ? trim((string) $criteria['setId'])
            : (isset($criteria['set']) && $criteria['set'] !== '' && $criteria['set'] !== null
                ? trim((string) $criteria['set'])
                : (isset($criteria['setNo']) && $criteria['setNo'] !== '' && $criteria['setNo'] !== null
                    ? trim((string) $criteria['setNo'])
                    : ''));

        if ($setFilter !== '') {
            if (ctype_digit($setFilter)) {
                $qb->andWhere('(s.id = :setIdVal OR s.no = :setNoExact OR s.no LIKE :setNoPrefix)');
                $qb->setParameter('setIdVal', (int) $setFilter);
                $qb->setParameter('setNoExact', $setFilter);
                $qb->setParameter('setNoPrefix', $setFilter . '-%');
            } else {
                $qb->andWhere('(s.no = :setNoExact OR s.no LIKE :setNoLike OR LOWER(s.name) LIKE :setNameLike)');
                $qb->setParameter('setNoExact', $setFilter);
                $qb->setParameter('setNoLike', '%' . $setFilter . '%');
                $qb->setParameter('setNameLike', '%' . strtolower($setFilter) . '%');
            }
        }

        if (isset($criteria['name']) && trim((string) $criteria['name']) !== '') {
            $qb->andWhere('LOWER(s.name) LIKE :setNameDirect');
            $qb->setParameter('setNameDirect', '%' . strtolower(trim((string) $criteria['name'])) . '%');
        }

        $part = isset($criteria['part']) ? trim((string) $criteria['part']) : (isset($criteria['partNo']) ? trim((string) $criteria['partNo']) : '');
        if ($part !== '') {
            $qb->andWhere('(p.no = :partExact OR LOWER(p.name) LIKE :partLike)');
            $qb->setParameter('partExact', $part);
            $qb->setParameter('partLike', '%' . strtolower($part) . '%');
        }

        $minYear = null;
        if (isset($criteria['minYear']) && $criteria['minYear'] !== '' && $criteria['minYear'] !== 0 && $criteria['minYear'] !== '0') {
            $minYear = (int) $criteria['minYear'];
        } elseif (isset($criteria['year']) && $criteria['year'] !== '' && $criteria['year'] !== 0 && $criteria['year'] !== '0') {
            $minYear = (int) $criteria['year'];
        }

        if ($minYear !== null && $minYear > 0) {
            $qb->andWhere('s.year >= :minYearDate');
            $qb->setParameter('minYearDate', new \DateTimeImmutable(sprintf('%04d-01-01', $minYear)));
        }

        if (isset($criteria['maxYear']) && $criteria['maxYear'] !== '' && $criteria['maxYear'] !== 0 && $criteria['maxYear'] !== '0') {
            $maxYear = (int) $criteria['maxYear'];
            if ($maxYear > 0) {
                $qb->andWhere('s.year <= :maxYearDate');
                $qb->setParameter('maxYearDate', new \DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $maxYear)));
            }
        }

        foreach ($criteria as $key => $value) {
            if (!in_array($key, $this->allowedCriteria, true)) {
                continue;
            }
            if (
                in_array($key, [
                'part', 'partNo', 'year', 'minYear', 'maxYear',
                'set', 'setId', 'setNo', 'name', 'excludeUsed',
                ], true)
            ) {
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

    /**
     * Batch load all SetPrice entities for a given list of sets to prevent N+1 queries during listing.
     *
     * @param iterable<mixed> $sets
     */
    public function batchLoadPrices(iterable $sets): void
    {
        $setIds = [];
        foreach ($sets as $set) {
            if ($set instanceof Set && $set->getId() !== null) {
                $setIds[] = $set->getId();
            }
        }

        if ($setIds === []) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'SELECT sp FROM App\Entity\SetPrice sp WHERE sp.set IN (:setIds) ORDER BY sp.recordedAt DESC'
        )
        ->setParameter('setIds', $setIds)
        ->getResult();
    }
}
