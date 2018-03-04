<?php

namespace App\Service;

use Bacanu\BlWrap\Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\SerializerInterface;
use RuntimeException;
use App\Entity\Item;
use App\Entity\Piece;
use App\Entity\Set;

/**
 * Service to load Lego data from (csv) into our Set & Piece entities
 */
class CsvLegoLoaderService implements LegoLoaderServiceInterface {

    private $source_path;
    private $serializer;
    private $em;
    private $cached_data = array();
    private $known_numbers = NULL;

    // fields in sets.csv
    const SET_NUM_KEY = 0;
    const SET_NAME_KEY = 1;
    const SET_YEAR_KEY = 2;
    const SET_THEME_KEY = 3;
    // fields in parts.csv
    const PART_NUM_KEY = 0;
    const PART_NAME_KEY = 1;
    const PART_CAT_KEY = 2;
    // fields in ininventories.csv
    const INVENTORY_ID = 0;
    const INVENTORY_SET_KEY = 2;
    // fields in inventory_sets.csv
    const INVENTORY_SET_INVENTORY = 0;
    const INVENTORY_SET_SET = 1;
    const INVENTORY_SET_QUANTITY = 2;
    // fields in inventory_parts.csv
    const INVENTORY_PART_INVENTORY = 0;
    const INVENTORY_PART_PART = 1;
    const INVENTORY_PART_COLOR = 2;
    const INVENTORY_PART_QUANTITY = 3;

    public function __construct(SerializerInterface $serializer, EntityManagerInterface $em, $import_save_path) {
        $this->serializer = $serializer;
        $this->em = $em;
        if (substr($import_save_path, -1) !== "/") {
            $import_save_path .= "/";
        }
        $this->source_path = $import_save_path;
    }

    /**
     * dump a whole CSV file at once
     * 
     * @param type $file
     * @return type
     */
    private function getCsvData($file) {
        $file_path = $this->normalizeCsvPath($file);
        if (array_key_exists($file, $this->cached_data)) {
            return $this->cached_data[$file];
        }
        $this->cached_data[$file] = $this->serializer->decode(file_get_contents($file_path), 'csv');
        return $this->cached_data[$file];
    }

    /**
     * Loop a csv file to call a function on each element
     * 
     * @param string $file name of the csv file to load
     * @param callable $callback function to call with each csv line. function should return the desired object to be pushed
     *                                              in the returned array
     * @return array $results all the return values of the callback != false
     */
    private function loopCsv($file, $callback, $start = 0, $end = false) {
        $file = $this->normalizeCsvPath($file);
        $results = array();
        $index = -1;
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle)) !== FALSE) {
                ++$index;
                if ($index < $start) {
                    continue;
                }
                $result = call_user_func($callback, $data);
                if ($result) {
                    $results[] = $result;
                } else if ($result === NULL) {
                    break;
                }
                if ($end && $end < $index) {
                    break;
                }
            }
            fclose($handle);
        }
        return $results;
    }

    /**
     * 
     * @param string $file
     * @param string $property
     * @param array $values
     * @return array
     */
    private function findDataInCsv($file, $property, array $values = array()) {
        if (!count($values) || !$property) {
            return array();
        }

        return $this->loopCsv($file, function($data) use ($property, $values) {
                    if (array_key_exists($property, $data)) {
                        if (in_array($data[$property], $values)) {
                            return $data;
                        }
                    }
                    return FALSE;
                });
    }

    public function loadSets($from = 1, $to = 0) {
        $self = $this;
        $sets = $this->loopCsv('sets', function ($set) use ($self) {
            return $self->loadSet($set, FALSE);
        }, $from, $to);

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

    public function loadSet($set_assoc, $flush = TRUE) {
        $set = $this->loadItemLocally($set_assoc[$this::SET_NUM_KEY]);
        if (!$set) {
            $set = $this->getSetFromAssoc($set_assoc);
            $this->em->persist($set);
            $this->cached_data[$set->getNo()][] = $set;
            if ($flush) {
                $this->em->flush();
            }
        }
        return $set;
    }

    public function getSetFromAssoc($set) {
        $new_set = new Set();
        $new_set->setSource(Set::SOURCE_REBRICKABLE);
        $new_set->setNo($set[$this::SET_NUM_KEY]);
        $new_set->setName($set[$this::PART_NAME_KEY]);
        $new_set->setObsolete(@$set["is_obsolete"]);
        $new_set->setYear(new \DateTime($set[$this::SET_YEAR_KEY]));
        $new_set->setImageUrl(@$set["image_url"]);
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
        $inventories = $this->findDataInCsv('inventories', $this::INVENTORY_SET_KEY, array($set_no));
        $inventory_ids = array_map(function($inventory) {
            return $inventory[$this::INVENTORY_ID];
        }, $inventories);
        $inventory_sets = $this->findDataInCsv('inventory_sets', $this::INVENTORY_SET_SET, array($set_no));
        $inventory_ids = array_merge($inventory_ids, array_map(function($inventory) {
            return $inventory[$this::INVENTORY_SET_INVENTORY];
        }, $inventory_sets));
        // inventory parts connects inventories/sets with parts, but each inventory_part could have another color as well as quantity
        $inventory_parts = $this->findDataInCsv('inventory_parts', $this::INVENTORY_PART_INVENTORY, $inventory_ids);

        $part_ids = array_map(function($inventory_part) {
            return $inventory_part[$this::INVENTORY_PART_PART];
        }, $inventory_parts);

        $parts = $this->findDataInCsv('parts', $this::PART_NUM_KEY, $part_ids);

        $ordered_parts = array();
        foreach ($parts as $part) {
            $ordered_parts[$part[$this::PART_NUM_KEY]] = $part;
        }

        $pieces = array();
        foreach ($inventory_parts as $piece) {
            // careful when loading locally as pieces can have different color for same no
            $p = $this->loadItemLocally($piece[$this::PART_NUM_KEY]);
            $is_loaded = $p;
            if (is_array($p)) {
                foreach ($p as $loaded_piece) {
                    if ($loaded_piece->getColor() == $piece[$this::INVENTORY_PART_COLOR]) {
                        $p = $loaded_piece;
                        $is_loaded = TRUE;
                        break;
                    }
                }
            } else if ($p instanceof Piece) {
                $is_loaded = TRUE;
            }
            if (!$is_loaded) {
                $p = $this->getPieceFromAssoc($piece, $parts[$piece[$this::INVENTORY_PART_PART]]);
                $this->em->persist($p);
                $this->cached_data[$p->getNo()][] = $p;
                if ($flush) {
                    $this->em->flush();
                }
            }
            for ($i = 0; $i < $piece[$this::INVENTORY_PART_PART]; $i++) {
                $pieces[] = $p;
            }
        }
        return new ArrayCollection($pieces);
    }

    public function loadPrices($all = false) {
        $set_repo = $this->em->getRepository(Set::class);
        $unsolved_sets = array();
        if ($all) {
            $unsolved_sets = $set_repo->findAll();
        } else {
            $unsolved_sets = $set_repo->findBy(array(
                'price' => NULL
            ));
        }
        foreach ($unsolved_sets as $set) {
            $set->setPrice($this->loadPriceForSet($set));
            $this->em->persist($set);
        }
        $this->em->flush();
        return $this;
    }

    protected function loadPriceForSet(Set $set) {
        return htp_get("https://www.briksets.nl/api/?set=" . $set->getNo() . "&get=rrp");
    }

    public static function getPieceFromAssoc($item, $piece) {
        $new_piece = new Piece();
        $new_piece->setName($piece[$this::PART_NAME_KEY]);
        $new_piece->setNo($piece[$this::PART_NUM_KEY]);
        $new_piece->setCategory($piece[$this::PART_CAT_KEY]);
        $new_piece->setColor($item[$this::INVENTORY_PART_COLOR]);
        return $new_piece;
    }

    public function getColors() {
        return $this->getCsvData('colors');
    }

    public function getCategories() {
        return $this->getCsvData('themes');
    }

    private function normalizeCsvPath($file) {
        if (substr($file, -4) != ".csv") {
            $file .= ".csv";
        }
        return $this->source_path . $file;
    }

}
