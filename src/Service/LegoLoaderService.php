<?php

namespace App\Service;

use Bacanu\BlWrap\Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use RuntimeException;
use App\Entity\Item;
use App\Entity\Piece;
use App\Entity\Set;

class LegoLoaderService {

    private $client;
    private $em;
    private $known_numbers = NULL;

    public function __construct($credentials, EntityManagerInterface $em) {
        $config = [
            "consumerKey" => $credentials['consumer']['key'],
            "consumerSecret" => $credentials['consumer']['secret'],
            "tokenValue" => $credentials['token']['value'],
            "tokenSecret" => $credentials['token']['secret'],
        ];
        $this->client = new Client($config);
        $this->em = $em;
    }

    private function loadExtern($endpoint, $method = 'GET', $body = "") {
        $response = json_decode($this->client->execute($method, $endpoint, $body));
        $meta = array();
        if (property_exists($response, 'meta')) {
            $meta = $response->meta;
        }
        if (property_exists($meta, 'code') && $meta->code != 200) {
            throw new RuntimeException('API did not return properly');
        }
        return $response->data;
    }

    public function loadSets($from, $to) {
        $sets = array();
        $range = range($from, $to);
        foreach ($range as $item) {
            $sets[] = $this->loadSet($item, FALSE);
        }
        $this->em->flush();
        return array_filter($sets);
    }

    private function setKnownItems() {
        $this->known_numbers = array();
        $piece_repo = $this->em->getRepository(Item::class);
        $items = $piece_repo->findAll();
        foreach ($items as $item) {
            $this->known_numbers[$item->getNo()][] = $item;
        }
        return $this->known_numbers;
    }

    public function loadItemLocally($set_no) {
        if ($this->known_numbers === NULL) {
            $this->setKnownItems();
        }
        if (array_key_exists($set_no, $this->known_numbers)) {
            $sets = $this->known_numbers[$set_no];
            if (count($sets) === 1) {
                return $sets[0];
            } else {
                return $sets;
            }
        }
        return FALSE;
    }

    public function loadSet($set_no, $flush = TRUE) {
        $set = $this->loadItemLocally($set_no);
        if (!$set) {
            $set = $this->loadExtern("items/SET/" . $set_no);
            $set = $this->getSetFromAssoc($set);
            $this->em->persist($set);
            if ($flush) {
                $this->em->flush();
            }
        }
        return $set;
    }

    public function getSetFromAssoc($set) {
        $new_set = new Set();
        $new_set->setNo($set->no);
        $new_set->setName($set->name);
        $new_set->setObsolete($set->is_obsolete);
        $new_set->setImageUrl($set->image_url);
        $pieces = $this->getPiecesOfSet($new_set->getNo());
        $new_set->setPieces($pieces);
        return $new_set;
    }

    /**
     * 
     * @param integer|string $set_no
     * @param boolean $force_load
     * @param boolean $flush
     * @return ArrayCollection
     */
    public function getPiecesOfSet($set_no, $force_load = false, $flush = false) {
        $set = $this->loadItemLocally($set_no);
        if ($set && !$force_load) {
            return $set->getPieces();
        }
        $subset = $this->loadExtern('items/SET/' . $set_no . '/subsets', $method);
        $pieces = array();
        foreach ($subset->entries as $piece) {
            // careful when loading locally as pieces can have different color for same no
            $p = $this->loadItemLocally($piece->no);
            $is_loaded = $p;
            if (is_array($p)) {
                foreach ($p as $loaded_piece) {
                    if ($loaded_piece->getColor() == $piece->color_id) {
                        $p = $loaded_piece;
                        $is_loaded = TRUE;
                        break;
                    }
                }
            } else if ($p instanceof Piece) {
                $is_loaded = TRUE;
            }
            if (!$is_loaded) {
                $p = $this->getPieceFromAssoc($piece);
                $this->em->persist($p);
                if ($flush) {
                    $this->em->flush();
                }
            }
            for ($i = 0; $i < $piece->quantity; $i++) {
                $pieces[] = $p;
            }
        }
        return new ArrayCollection($pieces);
    }

    public static function getPieceFromAssoc($piece) {
        $new_piece = new Piece();
        $item = $piece->item;
        $new_piece->setName($item->name);
        $new_piece->setNo($item->no);
        $new_piece->setCategory($item->categoryID);
        $new_piece->setType($item->type);
        $new_piece->setColor($piece->color_id);
        return $new_piece;
    }

    public function getColors() {
        return $this->loadExtern('colors');
    }

    public function getCategories() {
        return $this->loadExtern('categories');
    }

}
