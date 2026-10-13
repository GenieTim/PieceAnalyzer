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
     * Get the best (lowest) recorded price, optionally filtered by currency.
     */
    public function getBestPrice(?string $currency = null): ?SetPrice
    {
        $best = null;
        $targetCurrency = $currency !== null ? strtoupper(trim($currency)) : null;

        foreach ($this->prices as $p) {
            if ($p->getStatus() === PriceVerificationStatus::REJECTED) {
                continue;
            }
            if ($targetCurrency !== null && $p->getCurrency() !== $targetCurrency) {
                continue;
            }
            if ($best === null || $p->getDecimalPrice() < $best->getDecimalPrice()) {
                $best = $p;
            }
        }

        return $best;
    }

    /**
     * Automatically update cached best price and currency from existing SetPrice collection.
     */
    public function updateCachedBestPrice(): static
    {
        $best = $this->getBestPrice();
        if ($best instanceof \App\Entity\SetPrice) {
            $this->price = $best->getDecimalPrice();
            $this->priceCurrency = $best->getCurrency();
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

        return $this;
    }

    public function addPiece(Piece $p): static
    {
        if (!$this->pieces->contains($p)) {
            $this->pieces->add($p);
            $p->setSet($this);
        }

        return $this;
    }

    public function removePiece(Piece $p): static
    {
        if ($this->pieces->removeElement($p) && $p->getSet() === $this) {
            $p->setSet(null);
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
        $count = 0;
        foreach ($this->pieces as $piece) {
            $count += (int) $piece->getCount();
        }
        return $count;
    }
}
