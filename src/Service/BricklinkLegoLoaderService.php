<?php

namespace App\Service;

use App\Entity\Item;
use App\Entity\Piece;
use App\Entity\Set;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lego Loader Service to load data from BrickLink
 *
 * @deprecated v3
 */
class BricklinkLegoLoaderService implements LegoLoaderServiceInterface
{
    private readonly string $baseUrl;
    private readonly string $consumerKey;
    private readonly string $consumerSecret;
    private readonly string $tokenValue;
    private readonly string $tokenSecret;
    /** @var array<string, array<int, Item>>|null */
    private ?array $known_numbers = null;

    /**
     * @param array{consumer?: array{key?: string, secret?: string}, token?: array{value?: string, secret?: string}} $credentials
     */
    public function __construct(
        array $credentials,
        private readonly EntityManagerInterface $em,
        private readonly ?HttpClientInterface $httpClient = null
    ) {
        $this->baseUrl = 'https://api.bricklink.com/api/store/v1/';
        $this->consumerKey = (string) ($credentials['consumer']['key'] ?? '');
        $this->consumerSecret = (string) ($credentials['consumer']['secret'] ?? '');
        $this->tokenValue = (string) ($credentials['token']['value'] ?? '');
        $this->tokenSecret = (string) ($credentials['token']['secret'] ?? '');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function loadExtern(string $endpoint, string $method = 'GET', array $body = []): mixed
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        $headers = [
            'Authorization' => $this->buildOAuthHeader($method, $url, $method === 'GET' ? $body : []),
        ];

        if (!$this->httpClient instanceof \Symfony\Contracts\HttpClient\HttpClientInterface) {
            throw new RuntimeException('HTTP client not configured');
        }

        $options = ['headers' => $headers];
        if (strtoupper($method) === 'GET' && $body !== []) {
            $options['query'] = $body;
        } elseif ($body !== []) {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, $url, $options);
        $decoded = json_decode($response->getContent(), false);

        if (!is_object($decoded)) {
            throw new RuntimeException('API did not return a valid JSON object');
        }

        $meta = property_exists($decoded, 'meta') ? $decoded->meta : null;
        if (is_object($meta) && property_exists($meta, 'code') && (int) $meta->code !== 200) {
            throw new RuntimeException('API did not return properly: ' . ($meta->message ?? 'Unknown error'));
        }

        return $decoded->data ?? null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildOAuthHeader(string $method, string $url, array $params = []): string
    {
        $oauth = [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $this->tokenValue,
            'oauth_version' => '1.0',
        ];

        $allParams = array_merge($oauth, $params);
        ksort($allParams);

        $paramPairs = [];
        foreach ($allParams as $k => $v) {
            $paramPairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        $paramString = implode('&', $paramPairs);

        $baseString = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($paramString);
        $signingKey = rawurlencode($this->consumerSecret) . '&' . rawurlencode($this->tokenSecret);
        $signature = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

        $oauth['oauth_signature'] = $signature;

        $headerPairs = [];
        foreach ($oauth as $k => $v) {
            $headerPairs[] = rawurlencode((string) $k) . '="' . rawurlencode($v) . '"';
        }

        return 'OAuth ' . implode(', ', $headerPairs);
    }

    public function loadSets(int|string $from, int|string $to): array|int
    {
        $sets = [];
        $range = range((int) $from, (int) $to);
        foreach ($range as $item) {
            $sets[] = $this->loadSet((string) $item, false);
        }
        $this->em->flush();
        return array_values(array_filter($sets));
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
        if ($this->known_numbers !== null && array_key_exists($set_no, $this->known_numbers)) {
            $sets = $this->known_numbers[$set_no];
            if (count($sets) === 1) {
                return $sets[0];
            }
            return $sets;
        }
        return false;
    }

    public function loadSet(mixed $set_no, bool $flush = true): ?Set
    {
        $setNo = (string) $set_no;
        $local = $this->loadItemLocally($setNo);
        if ($local instanceof Set) {
            return $local;
        }

        $data = $this->loadExtern("items/SET/" . $setNo);
        if (!$data) {
            return null;
        }

        $set = $this->getSetFromAssoc($data);
        $this->em->persist($set);
        if ($flush) {
            $this->em->flush();
        }
        return $set;
    }

    public function loadInventory(int|string $inventory_id): ?Set
    {
        $inventory = $this->loadExtern('inventory', 'GET', ['inventory_id' => $inventory_id]);
        if (is_object($inventory) && isset($inventory->item) && $inventory->item->type === "SET") {
            return $this->loadSet($inventory->item->no);
        }
        return null;
    }

    /**
     * @param object|array<string, mixed> $set
     */
    public function getSetFromAssoc(object|array $set): Set
    {
        $new_set = new Set();
        $new_set->setSource(Set::SOURCE_BRICKLINK);
        $no = is_object($set) ? ($set->no ?? '') : ($set['no'] ?? '');
        $name = is_object($set) ? ($set->name ?? '') : ($set['name'] ?? '');
        $obsolete = is_object($set) ? ($set->is_obsolete ?? false) : ($set['is_obsolete'] ?? false);
        $imageUrl = is_object($set) ? ($set->image_url ?? null) : ($set['image_url'] ?? null);

        $new_set->setNo((string) $no);
        $new_set->setName((string) $name);
        $new_set->setObsolete((bool) $obsolete);
        $new_set->setImageUrl($imageUrl);
        $pieces = $this->getPiecesOfSet($new_set);
        $new_set->setPieces($pieces);
        return $new_set;
    }

    /**
     * @return Collection<int, Piece>
     */
    public function getPiecesOfSet(Set &$set, bool $flush = false, bool $force_load = false): Collection
    {
        $set_no = $set->getNo();
        if (!$force_load && $set->getPieces()->count() > 0) {
            return $set->getPieces();
        }

        $subset = $this->loadExtern('items/SET/' . $set_no . '/subsets');
        $pieces = [];
        if (is_object($subset) && isset($subset->entries) && is_iterable($subset->entries)) {
            foreach ($subset->entries as $piece) {
                $p = static::getPieceFromAssoc($piece);
                $p->setSet($set);
                $this->em->persist($p);
                $pieces[] = $p;
                if ($flush) {
                    $this->em->flush();
                }
            }
        }
        return new ArrayCollection($pieces);
    }

    /**
     * @param object|array<string, mixed> $piece
     */
    public static function getPieceFromAssoc(object|array $piece): Piece
    {
        $new_piece = new Piece();
        $item = is_object($piece) ? ($piece->item ?? null) : ($piece['item'] ?? null);
        $name = is_object($item) ? ($item->name ?? '') : ($item['name'] ?? '');
        $no = is_object($item) ? ($item->no ?? '') : ($item['no'] ?? '');
        $cat = is_object($item) ? ($item->categoryID ?? 0) : ($item['categoryID'] ?? 0);
        $type = is_object($item) ? ($item->type ?? null) : ($item['type'] ?? null);
        $color = is_object($piece) ? ($piece->color_id ?? 0) : ($piece['color_id'] ?? 0);
        $qty = is_object($piece) ? ($piece->quantity ?? 1) : ($piece['quantity'] ?? 1);

        $new_piece->setName((string) $name);
        $new_piece->setNo((string) $no);
        $new_piece->setCategory((int) $cat);
        $new_piece->setType($type !== null ? (string) $type : null);
        $new_piece->setColor((int) $color);
        $new_piece->setCount((int) $qty);
        return $new_piece;
    }

    public function getColors(): mixed
    {
        return $this->loadExtern('colors');
    }

    public function getCategories(): mixed
    {
        return $this->loadExtern('categories');
    }

    public function loadPrices(bool $all = false): static
    {
        return $this;
    }
}
