<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Entity\Piece;
use App\Entity\Set;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * LegoLoaderServiceInterface implementation using the Rebrickable REST API v3.
 *
 * @see https://rebrickable.com/api/v3/docs/
 */
class RebrickableApiService implements LegoLoaderServiceInterface
{
    public const BASE_URL = 'https://rebrickable.com/api/v3/lego/';

    private readonly LoggerInterface $logger;
    /** @var array<string, array<int, Item>>|null */
    private ?array $known_numbers = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private string $apiKey = ''
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Load sets in a given range/page from Rebrickable API.
     *
     * @return array<int, Set>|int
     */
    public function loadSets(int|string $from, int|string $to): array|int
    {
        $fromPage = is_numeric($from) ? (int) $from : 1;
        $toPage = is_numeric($to) && (int) $to >= $fromPage ? (int) $to : $fromPage;

        $loadedSets = [];

        for ($page = $fromPage; $page <= $toPage; ++$page) {
            $data = $this->requestApi('sets/', [
                'page' => $page,
                'page_size' => 100,
            ]);

            if ($data === null || !isset($data['results']) || !is_array($data['results'])) {
                break;
            }

            foreach ($data['results'] as $setItem) {
                if (is_array($setItem) && isset($setItem['set_num']) && is_string($setItem['set_num'])) {
                    $set = $this->loadSet($setItem['set_num'], false);
                    if ($set instanceof Set) {
                        $loadedSets[] = $set;
                    }
                }
            }
        }

        $this->em->flush();

        return $loadedSets;
    }

    /**
     * Load a single set and its pieces by set number from Rebrickable API.
     */
    public function loadSet(mixed $set_no, bool $flush = true): ?Set
    {
        $setNo = (string) ($set_no instanceof Set ? $set_no->getNo() : $set_no);
        if ($setNo === '') {
            return null;
        }

        $local = $this->loadItemLocally($setNo);
        if ($local instanceof Set) {
            return $local;
        }

        $details = $this->getSetDetails($setNo);
        if ($details === null) {
            return null;
        }

        $set = $this->getSetFromAssoc($details);
        $this->em->persist($set);
        if ($flush) {
            $this->em->flush();
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $details
     */
    public function getSetFromAssoc(array $details): Set
    {
        $new_set = new Set();
        $new_set->setSource(Set::SOURCE_REBRICKABLE);
        $new_set->setNo((string) ($details['set_num'] ?? ''));
        $new_set->setName((string) ($details['name'] ?? ''));
        $new_set->setObsolete(isset($details['is_obsolete']) ? (bool) $details['is_obsolete'] : null);

        $yearVal = (string) ($details['year'] ?? 'now');
        try {
            $new_set->setYear(new DateTime($yearVal . '-01-01'));
        } catch (Throwable) {
            $new_set->setYear(new DateTime());
        }

        $imageUrl = isset($details['set_img_url']) && is_string($details['set_img_url']) ? $details['set_img_url'] : null;
        $new_set->setImageUrl($imageUrl);

        $pieces = $this->getPiecesOfSet($new_set);
        $new_set->setPieces($pieces);

        return $new_set;
    }

    /**
     * @return Collection<int, Piece>
     */
    public function getPiecesOfSet(Set &$set, bool $flush = false): Collection
    {
        $setNo = (string) $set->getNo();
        $this->logger->info('Loading pieces for set via Rebrickable API: ' . $setNo);

        $partsData = $this->getSetParts($setNo);
        $pieces = [];

        foreach ($partsData as $item) {
            /** @var array<string, mixed> $part */
            $part = is_array($item['part'] ?? null) ? $item['part'] : [];
            /** @var array<string, mixed> $color */
            $color = is_array($item['color'] ?? null) ? $item['color'] : [];
            $quantity = (int) ($item['quantity'] ?? 1);

            $piece = new Piece();
            $piece->setName((string) ($part['name'] ?? ''));
            $piece->setNo((string) ($part['part_num'] ?? ''));
            $piece->setCategory((int) ($part['part_cat_id'] ?? 0));
            $piece->setColor((int) ($color['id'] ?? 0));
            $piece->setCount($quantity);
            $piece->setSet($set);

            $this->em->persist($piece);
            $pieces[] = $piece;

            if ($flush) {
                $this->em->flush();
            }
        }

        $collection = new ArrayCollection($pieces);
        $set->setPieces($collection);

        return $collection;
    }

    public function loadPrices(bool $all = false): static
    {
        return $this;
    }

    /**
     * Fetch all Lego colors from Rebrickable API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getColors(): array
    {
        $colors = [];
        $page = 1;

        while (true) {
            $data = $this->requestApi('colors/', [
                'page' => $page,
                'page_size' => 1000,
            ]);

            if ($data === null || !isset($data['results']) || !is_array($data['results'])) {
                break;
            }

            foreach ($data['results'] as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $colors[] = $item;
                }
            }

            if (empty($data['next'])) {
                break;
            }
            ++$page;
            if ($page > 20) {
                break;
            }
        }

        return $colors;
    }

    /**
     * Fetch all Lego themes / categories from Rebrickable API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(): array
    {
        return $this->getThemes();
    }

    /**
     * Fetch all Lego themes from Rebrickable API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getThemes(): array
    {
        $themes = [];
        $page = 1;

        while (true) {
            $data = $this->requestApi('themes/', [
                'page' => $page,
                'page_size' => 1000,
            ]);

            if ($data === null || !isset($data['results']) || !is_array($data['results'])) {
                break;
            }

            foreach ($data['results'] as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $themes[] = $item;
                }
            }

            if (empty($data['next'])) {
                break;
            }
            ++$page;
            if ($page > 20) {
                break;
            }
        }

        return $themes;
    }

    /**
     * Fetch all Lego part categories from Rebrickable API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPartCategories(): array
    {
        $categories = [];
        $page = 1;

        while (true) {
            $data = $this->requestApi('part_categories/', [
                'page' => $page,
                'page_size' => 1000,
            ]);

            if ($data === null || !isset($data['results']) || !is_array($data['results'])) {
                break;
            }

            foreach ($data['results'] as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $categories[] = $item;
                }
            }

            if (empty($data['next'])) {
                break;
            }
            ++$page;
            if ($page > 20) {
                break;
            }
        }

        return $categories;
    }

    /**
     * Fetch set details by set number (e.g. '75192-1').
     *
     * @return array<string, mixed>|null
     */
    public function getSetDetails(string $setNum): ?array
    {
        $normalized = $this->normalizeSetNum($setNum);
        $data = $this->requestApi('sets/' . urlencode($normalized) . '/');

        if ($data === null && $normalized !== $setNum) {
            $data = $this->requestApi('sets/' . urlencode($setNum) . '/');
        }

        return $data;
    }

    /**
     * Fetch all parts/pieces for a set.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSetParts(string $setNum, int $pageSize = 1000): array
    {
        $normalized = $this->normalizeSetNum($setNum);
        $parts = [];
        $page = 1;

        while (true) {
            $data = $this->requestApi('sets/' . urlencode($normalized) . '/parts/', [
                'page' => $page,
                'page_size' => $pageSize,
            ]);

            if ($data === null || !isset($data['results']) || !is_array($data['results'])) {
                break;
            }

            foreach ($data['results'] as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $parts[] = $item;
                }
            }

            if (empty($data['next'])) {
                break;
            }
            ++$page;
            if ($page > 50) {
                break;
            }
        }

        return $parts;
    }

    /**
     * Fetch part details by part number.
     *
     * @return array<string, mixed>|null
     */
    public function getPart(string $partNum): ?array
    {
        return $this->requestApi('parts/' . urlencode($partNum) . '/');
    }

    /**
     * Fetch part image URL for a given part number and optional color ID.
     */
    public function getPartImageUrl(string $partNum, ?int $colorId = null): ?string
    {
        if ($colorId !== null) {
            $colorData = $this->requestApi('parts/' . urlencode($partNum) . '/colors/' . $colorId . '/');
            if ($colorData !== null && isset($colorData['part_img_url']) && is_string($colorData['part_img_url']) && $colorData['part_img_url'] !== '') {
                return $colorData['part_img_url'];
            }
        }

        $partData = $this->getPart($partNum);
        if ($partData !== null && isset($partData['part_img_url']) && is_string($partData['part_img_url']) && $partData['part_img_url'] !== '') {
            return $partData['part_img_url'];
        }

        return null;
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

    /**
     * @return array<string, array<int, Item>>
     */
    private function setKnownItems(): array
    {
        $this->known_numbers = [];
        $pieceRepo = $this->em->getRepository(Item::class);
        $items = $pieceRepo->findAll();
        foreach ($items as $item) {
            $this->known_numbers[(string) $item->getNo()][] = $item;
        }
        return $this->known_numbers;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    private function requestApi(string $endpoint, array $query = []): ?array
    {
        if ($this->httpClient === null) {
            $this->logger->warning('HttpClientInterface not available for Rebrickable API');
            return null;
        }

        $url = self::BASE_URL . ltrim($endpoint, '/');
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'key ' . $this->apiKey;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'query' => $query,
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 404) {
                $this->logger->info('Rebrickable API resource not found (404)', ['endpoint' => $endpoint]);
                return null;
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->warning('Rebrickable API returned non-2xx status', [
                    'endpoint' => $endpoint,
                    'status' => $statusCode,
                ]);
                return null;
            }

            $content = $response->getContent(false);
            $data = json_decode($content, true);

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            $this->logger->error('Error communicating with Rebrickable API', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function normalizeSetNum(string $setNum): string
    {
        $trimmed = trim($setNum);
        if (preg_match('/^\d+$/', $trimmed)) {
            return $trimmed . '-1';
        }
        return $trimmed;
    }
}
