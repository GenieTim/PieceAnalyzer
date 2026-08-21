<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'lego_set')]
#[ORM\Entity(repositoryClass: \App\Repository\SetRepository::class)]
class Set extends Item
{
    public const SOURCE_BRICKLINK = 1;
    public const SOURCE_REBRICKABLE = 2;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::FLOAT, nullable: true)]
    private ?float $price = null;

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
            // unset parent

        }

        return $this;
    }

    public function getSource(): int
    {
        return $this->source;
    }

    public function setSource(int $source): void
    {
        $this->source = $source;
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
