<?php

namespace App\Service;

use Bacanu\BlWrap\Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use App\Entity\Item;
use App\Entity\Piece;
use App\Entity\Set;

/**
 * Service to load Lego data from (csv) into our Set & Piece entities
 * As CSV, data from Rebrickable is used
 */
class CsvLegoLoaderService implements LegoLoaderServiceInterface, PriceLoaderServiceInterface {

    private $source_path;
    private $serializer;
    private $em;
    private $cached_data = array();
    private $known_numbers = NULL;
    private $logger;

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

    public function __construct(SerializerInterface $serializer, EntityManagerInterface $em, LoggerInterface $logger, $import_save_path) {
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->em = $em;
        if (substr($import_save_path, -1) !== "/") {
            $import_save_path .= "/";
        }
        $this->source_path = $import_save_path;
        $this->logger = $logger;
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
        $this->logger->info('Looping CSV file ' . $file . ' from ' . $start . ' to ' . $end);
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
                    $this->logger->info("Result NULL. Exiting loop.");
                    break;
                }
                if ($end && $end < $index) {
                    break;
                }
            }
            fclose($handle);
        } else {
            $this->logger->warning('Handle could not be set up for file ' . $file);
        }
        $this->logger->info('Found ' . count($results) . ' results while looping ' . $file);
        return $results;
    }

    /**
     * Find data in CSV file where property == values
     *
     * @param string $file
     * @param string $property
     * @param array $values
     * @return array
     */
    private function findDataInCsv($file, $property = FALSE, array $values = array()) {
        if (!count($values) || $property === FALSE) {
            $this->logger->warning('Skipping ' . $file, array('property' => $property, 'values' => $values));
            return array();
        }

        return $this->loopCsv($file, function($data) use ($property, $values) {
                    if (array_key_exists($property, $data)) {
                        if (in_array($data[$property], $values)) {
                            return $data;
                        }
                    } else {
                        $this->logger->warning('Property ' . $property . ' does not exist in CSV', $data);
                    }
                    return FALSE;
                });
    }

    /**
     * Load all available sets in CSV file _sets_
     * 
     * @param integer $from
     * @param integer $to
     * @return array
     */
    public function loadSets($from = 1, $to = 0) {
        $self = $this;
        $sets = $this->loopCsv('sets', function ($set) use ($self, $to) {
            return $self->loadSet($set, $to > 0);
        }, $from, $to);

        $this->em->flush();
        return array_filter($sets);
    }

    /**
     * Initialize the cached sets data. To trade database space for memory, uncomment the code to load data from the 
     * database
     * 
     * @return array
     */
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
            $this->logger->info('got item locally', array('no' => $set_no, 'sets' => $sets));
            if (count($sets) === 1) {
                return $sets[0];
            } else if ($sets) {
                return $sets;
            }
        }
        return FALSE;
    }

    public function loadSet($set_assoc, $flush = TRUE) {
        $this->logger->info('Loading set: ', array('set' => $set_assoc));
        $set = $this->loadItemLocally($set_assoc[$this::SET_NUM_KEY]);
        if ($set === FALSE) {
            $set = $this->getSetFromAssoc($set_assoc);
            $this->em->persist($set);
            $this->cached_data[$set->getNo()][] = $set;
            if ($flush) {
                $this->em->flush();
            }
        } else if (is_array($set)) {
            $this->logger->warning('Got array for locally loaded Set. Please clean up soon.');
        }
        return $set;
    }

    public function getSetFromAssoc($set) {
        $this->logger->info('Loading set assoc: ', array('set' => $set));
        $new_set = new Set();
        $new_set->setSource(Set::SOURCE_REBRICKABLE);
        $new_set->setNo($set[$this::SET_NUM_KEY]);
        $new_set->setName($set[$this::PART_NAME_KEY]);
        $new_set->setObsolete(@$set["is_obsolete"]);
        $new_set->setYear(new \DateTime($set[$this::SET_YEAR_KEY]));
        $new_set->setImageUrl(@$set["image_url"]);
        $pieces = $this->getPiecesOfSet($new_set);
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
    public function getPiecesOfSet(Set &$set, $flush = false) {
        $set_no = $set->getNo();
        $this->logger->info('Loading Pieces of Set ' . $set_no);
        $inventories = $this->findDataInCsv('inventories', $this::INVENTORY_SET_KEY, array($set_no));
        $inventory_ids = array_map(function($inventory) {
            return $inventory[$this::INVENTORY_ID];
        }, $inventories);
        $inventory_sets = $this->findDataInCsv('inventory_sets', $this::INVENTORY_SET_SET, array($set_no));
        $inventory_ids = array_merge($inventory_ids, array_map(function($inventory) {
                    return $inventory[$this::INVENTORY_SET_INVENTORY];
                }, $inventory_sets));
        $partCollection = new ArrayCollection();

        // each inventory has the same parts listed over and over
        // a decision is necessary, which inventory should be used
        foreach ($inventory_ids as $inventory_id) {
            // inventory parts connects inventories/sets with parts, but each inventory_part could have another color as well as quantity
            $inventory_parts = $this->findDataInCsv('inventory_parts', $this::INVENTORY_PART_INVENTORY, $inventory_ids);
            // target for the inventory with the largest number of parts
            if (count($inventory_parts) <= $partCollection->count()) {
                continue;
            }

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
                // piece is from inventory_parts, part is from parts
                try {
                    $part = $ordered_parts[$piece[$this::INVENTORY_PART_PART]];
                } catch (\Exception $e) {
                    $this->logger->alert('Failed to get ordered part form pieces array with key ' . $this::INVENTORY_PART_PART, array($piece, 'error' => $e));
                    continue;
                }
                $p = $this->getPieceFromAssoc($piece, $part);
                $p->setSet($set);
                $this->em->persist($p);

                if ($flush) {
                    $this->em->flush();
                }
            }
            $partCollection = new ArrayCollection($pieces);
        }
        // return the part collection with the most parts
        return $partCollection;
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
            try {
                $set->setPrice($this->loadPriceForSet($set));
                $this->em->persist($set);
            } catch (\Exception $e) {
                $this->logger->warn('error while loading price', array('error' => $e));
            }
        }
        $this->em->flush();
        return $this;
    }

    public function loadPriceForSet($set_no) {
        $this->logger->info('Loading Price from Bricksets.nl for Set ' . $set_no);
        try {
            $price = file_get_contents("https://www.briksets.nl/api/?set=" . $set_no . "&get=rrp");
        } catch (\Exception $e) {
            $this->logger->warning('Price could not be loaded', array('error' => $e));
            $price = NULL;
        }
        return $price;
    }

    public static function getPieceFromAssoc($item, $piece) {
        $new_piece = new Piece();
        $new_piece->setName($piece[self::PART_NAME_KEY]);
        $new_piece->setNo($piece[self::PART_NUM_KEY]);
        $new_piece->setCategory($piece[self::PART_CAT_KEY]);
        $new_piece->setColor($item[self::INVENTORY_PART_COLOR]);
        $new_piece->setCount($item[self::INVENTORY_PART_PART]);
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
