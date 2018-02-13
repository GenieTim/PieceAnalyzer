<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Entity(repositoryClass="App\Repository\PieceRepository")
 */
class Piece extends Item
{

    /**
     * @ORM\ManyToMany(targetEntity="Set", mappedBy="pieces")
     */
    private $sets;

    /**
     * @ORM\Column(type="integer")
     */
    private $category;

    /**
     * @ORM\Column(type="string")
     */
    private $type;

    /**
     * @ORM\Column(type="integer")
     */
    private $color;

    /**
     * Get the value of sets
     */ 
    public function getSets()
    {
        return $this->sets;
    }

    /**
     * Set the value of sets
     *
     * @return  self
     */ 
    public function setSets(ArrayCollection $sets)
    {
        $this->sets = $sets;

        return $this;
    }
    
    public function addSet(Set $set) {
        $this->sets->add($set);
        
        return $this;
    }

    /**
     * Get the value of category
     */ 
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * Set the value of category
     *
     * @return  self
     */ 
    public function setCategory($category)
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Get the value of type
     */ 
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set the value of type
     *
     * @return  self
     */ 
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the value of color
     */ 
    public function getColor()
    {
        return $this->color;
    }

    /**
     * Set the value of color
     *
     * @return  self
     */ 
    public function setColor($color)
    {
        $this->color = $color;

        return $this;
    }
}
