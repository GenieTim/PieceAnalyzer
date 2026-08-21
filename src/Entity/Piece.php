<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'piece')]
#[ORM\Index(name: 'piece_color_idx', columns: ['color'])]
#[ORM\Index(name: 'piece_count_idx', columns: ['count'])]
#[ORM\Index(name: 'piece_sort_idx', columns: ['category', 'type'])]
#[ORM\Entity(repositoryClass: \App\Repository\PieceRepository::class)]
class Piece extends Item
{
    #[ORM\ManyToOne(targetEntity: Set::class, inversedBy: 'pieces')]
    private ?Set $set = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER)]
    private ?int $category = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER)]
    private ?int $color = null;
    
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER)]
    private ?int $count = null;

    public function getSet(): ?Set
    {
        return $this->set;
    }

    public function setSet(?Set $set): static
    {
        $this->set = $set;

        return $this;
    }

    public function getCategory(): ?int
    {
        return $this->category;
    }

    public function setCategory(?int $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getColor(): ?int
    {
        return $this->color;
    }

    public function setColor(?int $color): static
    {
        $this->color = $color;

        return $this;
    }
    
    public function getCount(): ?int
    {
        return $this->count;
    }
    
    public function setCount(?int $count): static
    {
        $this->count = $count;
        
        return $this;
    }
}
