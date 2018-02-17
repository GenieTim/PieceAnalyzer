<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Entity(repositoryClass="App\Repository\SetRepository")
 * @ORM\Table(name="lego_set")
 */
class Set extends Item
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="float")
     */
    private $price;

    /**
     * @ORM\ManyToMany(targetEntity="Piece", inversedBy="sets", cascade={"all"})
     */
    private $pieces;

    /**
     * @ORM\Column(type="boolean")
     */
    private $obsolete;
    
    /**
     * @ORM\Column(type="string")
     */
    private $image_url;

    /**
     * Get the value of price
     */ 
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Set the value of price
     *
     * @return  self
     */ 
    public function setPrice($price)
    {
        $this->price = $price;

        return $this;
    }

    /**
     * Get the value of pieces
     */ 
    public function getPieces()
    {
        return $this->pieces;
    }

    /**
     * Set the value of pieces
     *
     * @return  self
     */ 
    public function setPieces(ArrayCollection $pieces)
    {
        $this->pieces = $pieces;

        return $this;
    }
    
    public function addPiece(Piece $p) {
        $this->pieces->add($p);
        
        return $this;
    }

    /**
     * Get the value of obsolete
     */ 
    public function getObsolete()
    {
        return $this->obsolete;
    }

    /**
     * Set the value of obsolete
     *
     * @return  self
     */ 
    public function setObsolete($obsolete)
    {
        $this->obsolete = $obsolete;

        return $this;
    }
    
    /**
     * 
     * @return string
     */
    public function getImageUrl() {
        return $this->image_url;
    }
    
    /**
     * 
     * @param string $url
     * @return $this
     */
    public function setImageUrl($url) {
        $this->image_url = $url;
        
        return $this;
    }
}
