<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'item')]
#[ORM\Index(name: 'main_item_idx', columns: ['no'])]
#[ORM\Entity(repositoryClass: \App\Repository\ItemRepository::class)]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'discriminator', type: 'string')]
#[ORM\DiscriminatorMap(['undefined' => 'Item', 'set' => 'Set', 'piece' => 'Piece'])]
class Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER)]
    private ?int $id = null;

    /**
     * Bricklink identification number
     */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING)]
    private ?string $no = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING)]
    private ?string $name = null;

    public function getNo(): ?string
    {
        return $this->no;
    }

    public function setNo(?string $no): static
    {
        $this->no = $no;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
