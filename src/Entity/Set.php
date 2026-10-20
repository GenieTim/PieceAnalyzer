<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'lego_set')]
#[ORM\Index(name: 'idx_set_price', columns: ['price'])]
#[ORM\Index(name: 'idx_set_year', columns: ['year'])]
#[ORM\Index(name: 'idx_set_source', columns: ['source'])]
#[ORM\Entity(repositoryClass: \App\Repository\SetRepository::class)]
class Set extends Item
{
    public const SOURCE_BRICKLINK = 1;
    public const SOURCE_REBRICKABLE = 2;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::FLOAT, nullable: true)]
    private ?float $price = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 3, nullable: true)]
    private ?string $priceCurrency = 'CHF';

    /**
     * @var Collection<int, SetPrice>
     */
    #[ORM\OneToMany(targetEntity: SetPrice::class, mappedBy: 'set', cascade: ['all'], orphanRemoval: true)]
    private Collection $prices;

    /**
     * @var Collection<int, Piece>
     */
    #[ORM\OneToMany(targetEntity: Piece::class, mappedBy: 'set', cascade: ['all'], orphanRemoval: true)]
    private Collection $pieces;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER, nullable: true)]
    private ?int $pieceCount = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER)]
    private int $source = 0;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::BOOLEAN, nullable: true)]
    private ?bool $obsolete = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $year = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, nullable: true)]
    private ?string $image_url = null;

    public function __construct()
    {
        $this->pieces = new ArrayCollection();
        $this->prices = new ArrayCollection();
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): static
    {
        $this->price = $price ?? null;

        return $this;
    }

    public function getPriceCurrency(): ?string
    {
        return $this->priceCurrency;
    }

    public function setPriceCurrency(?string $currency): static
    {
        $this->priceCurrency = $currency !== null ? strtoupper(trim($currency)) : null;

        return $this;
    }

    /**
     * @return Collection<int, SetPrice>
     */
    public function getPrices(): Collection
    {
        return $this->prices;
    }

    public function addPrice(SetPrice $setPrice): static
    {
        if (!$this->prices->contains($setPrice)) {
            $this->prices->add($setPrice);
            $setPrice->setSet($this);
            $this->updateCachedBestPrice();
        }

        return $this;
    }

    public function removePrice(SetPrice $setPrice): static
    {
        if ($this->prices->removeElement($setPrice) && $setPrice->getSet() === $this) {
            $setPrice->setSet(null);
            $this->updateCachedBestPrice();
        }

        return $this;
    }

    /**
     * Find the most recently recorded price for a specific retailer.
     */
    public function getLatestPriceFor(Retailer $retailer): ?SetPrice
    {
        $latest = null;
        foreach ($this->prices as $p) {
            if ($p->getRetailer() === $retailer && $p->getStatus() !== PriceVerificationStatus::REJECTED && ($latest === null || $p->getRecordedAt() > $latest->getRecordedAt())) {
                $latest = $p;
            }
        }

        return $latest;
    }

    /**
     * Find extremum (lowest or highest) price matching criteria with multi-currency awareness.
     */
    private function findExtremumPrice(
        bool $findLowest = true,
        ?string $currency = null,
        bool $verifiedOnly = false,
        ?User $submittedBy = null,
        ?\App\Service\ExchangeRateService $exchangeRateService = null,
        bool $excludeUsed = false
    ): ?SetPrice {
        $targetCurrency = $currency !== null ? strtoupper(trim($currency)) : null;

        // Collect valid candidates
        $candidates = [];
        foreach ($this->prices as $p) {
            if ($verifiedOnly && $p->getStatus() !== PriceVerificationStatus::VERIFIED) {
                continue;
            }
            if (!$verifiedOnly && $p->getStatus() === PriceVerificationStatus::REJECTED) {
                continue;
            }
            if ($submittedBy instanceof User && $p->getSubmittedBy()?->getId() !== $submittedBy->getId()) {
                continue;
            }
            if ($excludeUsed && $p->getItemCondition() === 'used') {
                continue;
            }
            $candidates[] = $p;
        }

        if ($candidates === []) {
            return null;
        }

        // 1. If a specific target currency is requested, prefer exact match in that currency
        if ($targetCurrency !== null) {
            $sameCurrPrices = array_values(array_filter(
                $candidates,
                static fn(SetPrice $p): bool => $p->getCurrency() === $targetCurrency
            ));

            if ($sameCurrPrices !== []) {
                $bestSame = null;
                foreach ($sameCurrPrices as $p) {
                    if ($bestSame === null) {
                        $bestSame = $p;
                    } elseif ($findLowest && $p->getDecimalPrice() < $bestSame->getDecimalPrice()) {
                        $bestSame = $p;
                    } elseif (!$findLowest && $p->getDecimalPrice() > $bestSame->getDecimalPrice()) {
                        $bestSame = $p;
                    }
                }
                return $bestSame;
            }
        }

        // 2. Normalized multi-currency comparison using target or base currency (CHF)
        $refCurrency = $targetCurrency ?? 'CHF';
        $result = null;
        $bestNormalizedVal = null;

        foreach ($candidates as $p) {
            $curr = $p->getCurrency();
            $decimal = $p->getDecimalPrice();

            if ($exchangeRateService instanceof \App\Service\ExchangeRateService) {
                $normVal = $exchangeRateService->convert($decimal, $curr, $refCurrency);
            } else {
                // Built-in reference conversion when ExchangeRateService is not injected directly into entity
                $chfVal = match ($curr) {
                    'EUR' => $decimal * 0.96,
                    'USD' => $decimal * 0.90,
                    'GBP' => $decimal * 1.15,
                    default => $decimal,
                };
                $normVal = match ($refCurrency) {
                    'EUR' => $chfVal / 0.96,
                    'USD' => $chfVal / 0.90,
                    'GBP' => $chfVal / 1.15,
                    default => $chfVal,
                };
            }

            if ($result === null || $bestNormalizedVal === null) {
                $result = $p;
                $bestNormalizedVal = $normVal;
            } elseif ($findLowest && $normVal < $bestNormalizedVal) {
                $result = $p;
                $bestNormalizedVal = $normVal;
            } elseif (!$findLowest && $normVal > $bestNormalizedVal) {
                $result = $p;
                $bestNormalizedVal = $normVal;
            }
        }

        return $result;
    }

    /**
     * Get the best (lowest) recorded price, optionally filtered or normalized by currency.
     */
    public function getBestPrice(
        ?string $currency = null,
        ?\App\Service\ExchangeRateService $exchangeRateService = null,
        bool $excludeUsed = false
    ): ?SetPrice {
        return $this->findExtremumPrice(
            findLowest: true,
            currency: $currency,
            verifiedOnly: true,
            exchangeRateService: $exchangeRateService,
            excludeUsed: $excludeUsed
        );
    }

    /**
     * Get the price submitted by a specific user (if any, excluding rejected).
     */
    public function getUserPrice(
        ?User $user,
        ?string $currency = null,
        ?\App\Service\ExchangeRateService $exchangeRateService = null,
        bool $excludeUsed = false
    ): ?SetPrice {
        if (!$user instanceof User || $user->getId() === null) {
            return null;
        }

        return $this->findExtremumPrice(
            findLowest: true,
            currency: $currency,
            verifiedOnly: false,
            submittedBy: $user,
            exchangeRateService: $exchangeRateService,
            excludeUsed: $excludeUsed
        );
    }

    /**
     * Get the lowest historical price recorded across all non-rejected prices.
     */
    public function getHistoricalLowestPrice(
        ?string $currency = null,
        ?\App\Service\ExchangeRateService $exchangeRateService = null,
        bool $excludeUsed = false
    ): ?SetPrice {
        return $this->findExtremumPrice(
            findLowest: true,
            currency: $currency,
            verifiedOnly: false,
            exchangeRateService: $exchangeRateService,
            excludeUsed: $excludeUsed
        );
    }

    /**
     * Get the highest historical price recorded across all non-rejected prices (e.g. MSRP baseline).
     */
    public function getHistoricalHighestPrice(
        ?string $currency = null,
        ?\App\Service\ExchangeRateService $exchangeRateService = null,
        bool $excludeUsed = false
    ): ?SetPrice {
        return $this->findExtremumPrice(
            findLowest: false,
            currency: $currency,
            verifiedOnly: false,
            exchangeRateService: $exchangeRateService,
            excludeUsed: $excludeUsed
        );
    }

    /**
     * Calculate savings delta between highest recorded price (MSRP/peak) and lowest recorded price.
     *
     * @return array{highest: SetPrice, lowest: SetPrice, savingsAmount: float, savingsPercent: float, targetCurrency?: string}|null
     */
    public function getHistoricalSavingsDelta(
        ?string $currency = null,
        ?\App\Service\ExchangeRateService $exchangeRateService = null,
        bool $excludeUsed = false
    ): ?array {
        $targetCurr = $currency !== null ? strtoupper(trim($currency)) : 'CHF';
        $lowest = $this->getHistoricalLowestPrice($targetCurr, $exchangeRateService, $excludeUsed);
        $highest = $this->getHistoricalHighestPrice($targetCurr, $exchangeRateService, $excludeUsed);

        if (!$lowest instanceof SetPrice || !$highest instanceof SetPrice) {
            return null;
        }

        if ($lowest->getId() !== null && $highest->getId() !== null && $lowest->getId() === $highest->getId()) {
            return null;
        }

        $convert = function (float $amt, string $curr) use ($targetCurr, $exchangeRateService): float {
            if ($curr === $targetCurr) {
                return $amt;
            }
            if ($exchangeRateService instanceof \App\Service\ExchangeRateService) {
                return $exchangeRateService->convert($amt, $curr, $targetCurr);
            }
            $chf = match ($curr) {
                'EUR' => $amt * 0.96,
                'USD' => $amt * 0.90,
                'GBP' => $amt * 1.15,
                default => $amt,
            };
            return match ($targetCurr) {
                'EUR' => $chf / 0.96,
                'USD' => $chf / 0.90,
                'GBP' => $chf / 1.15,
                default => $chf,
            };
        };

        $highVal = $convert($highest->getDecimalPrice(), $highest->getCurrency());
        $lowVal = $convert($lowest->getDecimalPrice(), $lowest->getCurrency());

        if ($highVal <= 0.0 || $lowVal >= $highVal) {
            return null;
        }

        $savingsAmount = round($highVal - $lowVal, 2);
        $savingsPercent = round(($savingsAmount / $highVal) * 100, 1);

        return [
            'highest' => $highest,
            'lowest' => $lowest,
            'savingsAmount' => $savingsAmount,
            'savingsPercent' => $savingsPercent,
            'targetCurrency' => $targetCurr,
        ];
    }

    /**
     * @return list<SetPrice>
     */
    public function getPricesSortedByDate(string $direction = 'DESC', bool $excludeUsed = false): array
    {
        $prices = $this->prices->toArray();
        if ($excludeUsed) {
            $prices = array_filter($prices, static fn(SetPrice $p): bool => $p->getItemCondition() !== 'used');
        }
        $asc = strtoupper($direction) === 'ASC';

        usort($prices, static function (SetPrice $a, SetPrice $b) use ($asc): int {
            $cmp = $a->getRecordedAt() <=> $b->getRecordedAt();
            return $asc ? $cmp : -$cmp;
        });

        return array_values($prices);
    }

    /**
     * Get the effective price for a user: their own price if they submitted one, otherwise best available price.
     */
    public function getEffectivePriceFor(?User $user = null, ?string $currency = null, bool $excludeUsed = false): ?SetPrice
    {
        return $this->getUserPrice($user, $currency, excludeUsed: $excludeUsed) ?? $this->getBestPrice($currency, excludeUsed: $excludeUsed);
    }

    /**
     * Automatically update cached best price and currency from existing SetPrice collection.
     */
    public function updateCachedBestPrice(?\App\Service\ExchangeRateService $exchangeRateService = null): static
    {
        $best = $this->getBestPrice(exchangeRateService: $exchangeRateService);
        if ($best instanceof \App\Entity\SetPrice) {
            $this->price = $best->getDecimalPrice();
            $this->priceCurrency = $best->getCurrency();
        } elseif ($this->prices->isEmpty()) {
            $this->price = null;
            $this->priceCurrency = null;
        }

        return $this;
    }

    /**
     * @return Collection<int, Piece>
     */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    /**
     * @param Collection<int, Piece> $pieces
     */
    public function setPieces(Collection $pieces): static
    {
        $this->pieces = $pieces;
        $this->pieceCount = null;

        return $this;
    }

    public function addPiece(Piece $p): static
    {
        if (!$this->pieces->contains($p)) {
            $this->pieces->add($p);
            $p->setSet($this);
            $this->pieceCount = null;
        }

        return $this;
    }

    public function removePiece(Piece $p): static
    {
        if ($this->pieces->removeElement($p) && $p->getSet() === $this) {
            $p->setSet(null);
            $this->pieceCount = null;
        }

        return $this;
    }

    public function getSource(): int
    {
        return $this->source;
    }

    public function setSource(int $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getObsolete(): ?bool
    {
        return $this->obsolete;
    }

    public function setObsolete(?bool $obsolete): static
    {
        $this->obsolete = $obsolete;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->image_url;
    }

    public function setImageUrl(?string $url): static
    {
        $this->image_url = $url;

        return $this;
    }

    public function getYear(): ?\DateTimeInterface
    {
        return $this->year;
    }

    public function setYear(?\DateTimeInterface $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getPieceCount(): int
    {
        if ($this->pieceCount !== null && $this->pieceCount > 0) {
            return $this->pieceCount;
        }

        $count = 0;
        foreach ($this->pieces as $piece) {
            $count += (int) $piece->getCount();
        }

        if ($count > 0 && $this->pieceCount === null) {
            $this->pieceCount = $count;
        }

        return $count;
    }

    public function setPieceCount(?int $pieceCount): static
    {
        $this->pieceCount = $pieceCount;

        return $this;
    }

    /**
     * Get the count of pieces in this set matching a specific part identifier and optional filters.
     */
    public function getMatchingPartCount(?string $part = null, ?int $color = null, ?int $category = null): int
    {
        $hasPart = $part !== null && trim($part) !== '';
        $hasColor = $color !== null && $color > 0;
        $hasCategory = $category !== null && $category > 0;

        if (!$hasPart && !$hasColor && !$hasCategory) {
            return $this->getPieceCount();
        }

        $partTrimmed = $hasPart ? trim((string) $part) : null;
        $partLower = $partTrimmed !== null ? strtolower($partTrimmed) : null;

        $count = 0;
        foreach ($this->pieces as $piece) {
            if ($partLower !== null) {
                $pNo = (string) $piece->getNo();
                $pName = strtolower((string) $piece->getName());
                if ($pNo !== $partTrimmed && !str_contains($pName, $partLower)) {
                    continue;
                }
            }
            if ($hasColor && $piece->getColor() !== $color) {
                continue;
            }
            if ($hasCategory && $piece->getCategory() !== $category) {
                continue;
            }
            $count += (int) $piece->getCount();
        }

        return $count;
    }
}
