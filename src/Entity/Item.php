<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ItemRepository")
 * @ORM\Table(name="item", indexes={@ORM\Index(name="main_item_idx", columns={"no"})})
 * @ORM\InheritanceType("JOINED")
 * @ORM\DiscriminatorColumn(name="discriminator", type="string")
 * @ORM\DiscriminatorMap({"undefined" = "Item", "set" = "Set", "piece" = "Piece"})
 */
class Item
{

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * Bricklink identification number
     * @ORM\Column(type="string")
     */
    private $no;

    /**
     * @ORM\Column(type="string")
     */
    private $name;

    /**
     * Get bricklink identification number
     */
    public function getNo()
    {
        return $this->no;
    }

    /**
     * Set bricklink identification number
     *
     * @return  self
     */
    public function setNo($no)
    {
        $this->no = $no;

        return $this;
    }

    /**
     * Get the value of id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set the value of id
     *
     * @return  self
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the value of name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set the value of name
     *
     * @return  self
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }
}
