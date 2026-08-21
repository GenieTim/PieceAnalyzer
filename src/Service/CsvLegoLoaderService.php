<?php

namespace App\Service;

use App\Entity\Item;
use App\Entity\Piece;
use App\Entity\Set;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service to load Lego data from CSV into Set & Piece entities.
 * Uses Rebrickable database CSV dumps.
 */
class CsvLegoLoaderService implements LegoLoaderServiceInterface, PriceLoaderServiceInterface
{
    private readonly string $source_path;
    /** @var array<string, array<int, array<string|int, string>>> */
    private array $cached_data = [];
    /** @var array<string, array<int, Item>>|null */
    private ?array $known_numbers = null;

    // fields in sets.csv: set_num, name, year, theme_id, num_parts, img_url
    public const SET_NUM_KEY = 0;
    public const SET_NAME_KEY = 1;
    public const SET_YEAR_KEY = 2;
    public const SET_THEME_KEY = 3;

    // fields in parts.csv: part_num, name, part_cat_id, part_material
    public const PART_NUM_KEY = 0;
    public const PART_NAME_KEY = 1;
    public const PART_CAT_KEY = 2;

    // fields in inventories.csv: id, version, set_num
    public const INVENTORY_ID = 0;
    public const INVENTORY_VERSION = 1;
    public const INVENTORY_SET_KEY = 2;

    // fields in inventory_sets.csv: inventory_id, set_num, quantity
    public const INVENTORY_SET_INVENTORY = 0;
    public const INVENTORY_SET_SET = 1;
    public const INVENTORY_SET_QUANTITY = 2;

    // fields in inventory_parts.csv: inventory_id, part_num, color_id, quantity, is_spare, img_url
    public const INVENTORY_PART_INVENTORY = 0;
    public const INVENTORY_PART_PART = 1;
    public const INVENTORY_PART_COLOR = 2;
    public const INVENTORY_PART_QUANTITY = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        string $import_save_path,
        private readonly ?HttpClientInterface $httpClient = null
    ) {
        if (!str_ends_with($import_save_path, '/')) {
            $import_save_path .= '/';
        }
        $this->source_path = $import_save_path;
    }

    /**
     * Read a CSV file into an array of rows (with headers as keys if headers exist)
     *
     * @return array<int, array<string|int, string>>
     */
    public function getCsvData(string $file): array
    {
        $filePath = $this->normalizeCsvPath($file);
        if (isset($this->cached_data[$file])) {
            return $this->cached_data[$file];
        }

        $rows = [];
        if (file_exists($filePath) && ($handle = fopen($filePath, 'r')) !== false) {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            if ($header !== false) {
                while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rows[] = count($data) === count($header) ? array_combine($header, $data) : $data;
                }
            }
            fclose($handle);
        }

        $this->cached_data[$file] = $rows;
        $this->logger->info('Read CSV File', [
            'path' => $filePath,
            'count' => count($rows),
        ]);

        return $this->cached_data[$file];
    }

    /**
     * Loop a CSV file and call a callback on each row
     *
     * @param callable(array<int, string>): mixed $callback
     * @return array<int, mixed>
     */
    private function loopCsv(string $file, callable $callback, int $start = 0, int|bool $end = false): array
    {
        $filePath = $this->normalizeCsvPath($file);
        $this->logger->info('Looping CSV file ' . $filePath . ' from ' . $start . ' to ' . ($end !== false ? (string) $end : 'end'));
        $results = [];
        $index = -1;

        if (!file_exists($filePath)) {
            $this->logger->warning('CSV file does not exist: ' . $filePath);
            return $results;
        }

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                ++$index;
                if ($index < $start) {
                    continue;
                }
                $result = $callback($data);
                if ($result !== null && $result !== false) {
                    $results[] = $result;
                } elseif ($result === null) {
                    $this->logger->info('Result NULL. Exiting loop.');
                    break;
                }
                if ($end !== false && $end !== 0 && $index >= $end) {
                    break;
                }
            }
            fclose($handle);
        } else {
            $this->logger->warning('Handle could not be set up for file ' . $filePath);
        }

        $this->logger->info('Found ' . count($results) . ' results while looping ' . $file);
        return $results;
    }

    /**
     * Find rows in a CSV file where $property index/key matches any in $values
     *
     * @param array<int, string|int> $values
     * @return array<int, array<int, string>>
     */
    private function findDataInCsv(string $file, int|string $property, array $values = []): array
    {
        if ($values === [] || $property === '') {
            return [];
        }

        $valueMap = array_flip(array_map('strval', $values));

        /** @var array<int, array<int, string>> */
        return $this->loopCsv($file, function (array $data) use ($property, $valueMap): ?array {
            if (isset($data[$property]) && isset($valueMap[$data[$property]])) {
                return $data;
            }
            return null;
        });
    }

    /**
     * Load all available sets from CSV file
     *
     * @return array<int, mixed>|int
     */
    public function loadSets(int|string $from = 1, int|string $to = 0): array|int
    {
        $fromInt = (int) $from;
        $toInt = (int) $to;

        $sets = $this->loopCsv('sets', fn(array $set): ?\App\Entity\Set => $this->loadSet($set, $toInt === 0), $fromInt, $toInt !== 0 ? $toInt : false);

        $this->em->flush();
        $this->em->clear();

        return $toInt !== 0 ? array_values(array_filter($sets)) : count($sets);
    }

    /**
     * @return array<string, array<int, Item>>
     */
    private function setKnownItems(): array
    {
        $this->known_numbers = [];
        $piece_repo = $this->em->getRepository(Item::class);
        $items = $piece_repo->findAll();
        foreach ($items as $item) {
            $this->known_numbers[(string) $item->getNo()][] = $item;
        }
        return $this->known_numbers;
    }

    /**
     * @return Item|array<int, Item>|false
     */
    public function loadItemLocally(string $set_no): Item|array|false
    {
        if ($this->known_numbers === null) {
            $this->setKnownItems();
        }
        if ($this->known_numbers !== null && isset($this->known_numbers[$set_no])) {
            $sets = $this->known_numbers[$set_no];
            $this->logger->info('Got item locally', ['no' => $set_no, 'count' => count($sets)]);
            if (count($sets) === 1) {
                return $sets[0];
            }
            return $sets;
        }
        return false;
    }

    public function loadSet(mixed $set_no, bool $flush = true): ?Set
    {
        if (!is_array($set_no) || !isset($set_no[self::SET_NUM_KEY])) {
            return null;
        }

        $setNo = (string) $set_no[self::SET_NUM_KEY];
        $this->logger->info('Loading set: ' . $setNo);

        $local = $this->loadItemLocally($setNo);
        if ($local instanceof Set) {
            return $local;
        }

        $set = $this->getSetFromAssoc($set_no);
        $this->em->persist($set);
        if ($flush) {
            $this->em->flush();
        }

        return $set;
    }

    /**
     * @param array<int|string, mixed> $set
     */
    public function getSetFromAssoc(array $set): Set
    {
        $new_set = new Set();
        $new_set->setSource(Set::SOURCE_REBRICKABLE);
        $new_set->setNo((string) ($set[self::SET_NUM_KEY] ?? ''));
        $new_set->setName((string) ($set[self::SET_NAME_KEY] ?? ''));
        $new_set->setObsolete(isset($set['is_obsolete']) ? (bool) $set['is_obsolete'] : null);

        $yearVal = $set[self::SET_YEAR_KEY] ?? 'now';
        try {
            $new_set->setYear(new \DateTime($yearVal . '-01-01'));
        } catch (\Throwable) {
            $new_set->setYear(new \DateTime());
        }

        $new_set->setImageUrl(isset($set['image_url']) ? (string) $set['image_url'] : null);

        $pieces = $this->getPiecesOfSet($new_set);
        $new_set->setPieces($pieces);

        return $new_set;
    }

    /**
     * @return Collection<int, Piece>
     */
    public function getPiecesOfSet(Set &$set, bool $flush = false): Collection
    {
        $set_no = (string) $set->getNo();
        $this->logger->info('Loading Pieces of Set ' . $set_no);

        $inventories = $this->findDataInCsv('inventories', self::INVENTORY_SET_KEY, [$set_no]);
        $inventory_ids = array_column($inventories, self::INVENTORY_ID);

        $partCollection = new ArrayCollection();

        foreach ($inventory_ids as $inventory_id) {
            $inventory_parts = $this->findDataInCsv('inventory_parts', self::INVENTORY_PART_INVENTORY, [$inventory_id]);

            if (count($inventory_parts) <= $partCollection->count()) {
                continue;
            }

            $part_ids = array_column($inventory_parts, self::INVENTORY_PART_PART);
            $parts = $this->findDataInCsv('parts', self::PART_NUM_KEY, $part_ids);

            $ordered_parts = [];
            foreach ($parts as $part) {
                $ordered_parts[(string) $part[self::PART_NUM_KEY]] = $part;
            }

            $pieces = [];
            foreach ($inventory_parts as $pieceData) {
                $partNum = (string) $pieceData[self::INVENTORY_PART_PART];
                $part = $ordered_parts[$partNum] ?? [
                    self::PART_NUM_KEY => $partNum,
                    self::PART_NAME_KEY => $partNum,
                    self::PART_CAT_KEY => 0,
                ];

                $p = static::getPieceFromAssoc($pieceData, $part);
                $p->setSet($set);
                $this->em->persist($p);
                $pieces[] = $p;

                if ($flush) {
                    $this->em->flush();
                }
            }
            $partCollection = new ArrayCollection($pieces);
        }

        return $partCollection;
    }

    public function loadPrices(bool $all = false): static
    {
        $set_repo = $this->em->getRepository(Set::class);
        $unsolved_sets = $all ? $set_repo->findAll() : $set_repo->findBy(['price' => null]);

        foreach ($unsolved_sets as $set) {
            try {
                $price = $this->loadPriceForSet($set);
                if ($price !== null) {
                    $set->setPrice($price);
                    $this->em->persist($set);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Error while loading price for set', ['error' => $e]);
            }
        }
        $this->em->flush();

        return $this;
    }

    public function loadPriceForSet(mixed $set_no): ?float
    {
        $no = $set_no instanceof Set ? (string) $set_no->getNo() : (string) $set_no;
        $this->logger->info('Loading Price from Bricksets for Set ' . $no);

        try {
            $url = 'https://www.brickset.com/api/?set=' . $no . '&get=rrp';
            if ($this->httpClient instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 5]);
                $content = $response->getContent(false);
            } else {
                $context = stream_context_create(['http' => ['timeout' => 5]]);
                $content = @file_get_contents($url, false, $context);
            }

            if ($content === false || $content === '') {
                return null;
            }

            $price = trim($content);
            if (is_numeric($price)) {
                return (float) $price;
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Price could not be loaded', ['set' => $no, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param array<int|string, mixed> $item Row from inventory_parts.csv
     * @param array<int|string, mixed> $piece Row from parts.csv
     */
    public static function getPieceFromAssoc($item, $piece): Piece
    {
        $new_piece = new Piece();
        $new_piece->setName((string) ($piece[self::PART_NAME_KEY] ?? ''));
        $new_piece->setNo((string) ($piece[self::PART_NUM_KEY] ?? ''));
        $new_piece->setCategory((int) ($piece[self::PART_CAT_KEY] ?? 0));
        $new_piece->setColor((int) ($item[self::INVENTORY_PART_COLOR] ?? 0));
        // Fix: Use INVENTORY_PART_QUANTITY (index 3), not INVENTORY_PART_PART (index 1)
        $new_piece->setCount((int) ($item[self::INVENTORY_PART_QUANTITY] ?? 1));

        return $new_piece;
    }

    /**
     * @return array<int, array<string|int, string>>
     */
    public function getColors(): array
    {
        return $this->getCsvData('colors');
    }

    /**
     * @return array<int, array<string|int, string>>
     */
    public function getCategories(): array
    {
        return $this->getCsvData('themes');
    }

    public function normalizeCsvPath(string $file): string
    {
        if (!str_ends_with($file, '.csv')) {
            $file .= '.csv';
        }
        return $this->source_path . $file;
    }
}
