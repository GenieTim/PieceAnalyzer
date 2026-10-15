<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\PriceSourceProvider;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use App\Repository\SetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class PriceReportController extends AbstractController
{
    public function __construct(private readonly RateLimiterFactory $priceReportLimiter)
    {
    }

    #[Route(path: '/api/prices/report', name: 'api_price_report', methods: ['POST', 'OPTIONS'])]
    public function report(Request $request, SetRepository $setRepo, EntityManagerInterface $em): Response
    {
        // Handle CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            $this->addCorsHeaders($response, $request);
            return $response;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $request->getContent(), true) ?? [];

        // 1. Authenticate user: Bearer API token or payload token (disallow naked session cookie to prevent CSRF)
        $authHeader = $request->headers->get('Authorization');
        $token = '';
        if (is_string($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        } elseif (isset($data['token']) && is_string($data['token'])) {
            $token = $data['token'];
        }

        $user = null;
        if ($token !== '') {
            $user = $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);
        }

        if (!$user instanceof User) {
            // Rate limit unauthenticated attempts by IP to prevent token brute forcing & DoS
            $clientIp = $request->getClientIp() ?? 'unknown';
            $this->priceReportLimiter->create('ip_unauth_' . $clientIp)->consume();
            return $this->jsonError('Unauthorized. Valid contributor API token required.', 401, $request);
        }

        if ($user->isApiTokenExpired()) {
            return $this->jsonError('Contributor API token has expired. Please regenerate your token in your profile.', 401, $request);
        }


        if (!$user->isEmailVerified()) {
            return $this->jsonError('Email address must be verified before reporting prices.', 403, $request);
        }

        // 3. Enforce User Rate Limiting (10 requests / minute per user)
        $limiter = $this->priceReportLimiter->create($user->getUserIdentifier());
        if (!$limiter->consume()->isAccepted()) {
            return $this->jsonError('Rate limit exceeded (max 10 price reports per minute). Please wait.', 429, $request);
        }


        $payloadData = $data;
        unset($payloadData['token']);
        $isBatch = isset($payloadData['items']) && is_array($payloadData['items']);
        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = $isBatch ? $payloadData['items'] : (empty($payloadData) ? [] : [$payloadData]);

        if (empty($rawItems)) {
            return $this->jsonError('No price report data provided.', 400, $request);
        }

        if (count($rawItems) > 100) {
            return $this->jsonError('Batch exceeds maximum limit of 100 items per request.', 400, $request);
        }

        $results = [];
        $recordedCount = 0;
        $lastSet = null;
        $lastSetPrice = null;
        $lastRetailer = null;

        foreach ($rawItems as $itemData) {
            $setNo = trim((string) ($itemData['setNo'] ?? ''));
            if ($setNo === '') {
                if (!$isBatch) {
                    return $this->jsonError('Missing set number (setNo).', 400, $request);
                }
                $results[] = ['success' => false, 'error' => 'Missing set number (setNo).'];
                continue;
            }

            // Match set by number, trying with and without '-1' suffix
            $set = $setRepo->findOneBy(['no' => $setNo]);
            if (!$set instanceof Set) {
                $normalizedNo = str_contains($setNo, '-') ? $setNo : $setNo . '-1';
                $set = $setRepo->findOneBy(['no' => $normalizedNo]);
            }

            if (!$set instanceof Set) {
                if (!$isBatch) {
                    return $this->jsonError(sprintf('Set "%s" not found in database.', $setNo), 404, $request);
                }
                $results[] = ['setNo' => $setNo, 'success' => false, 'error' => sprintf('Set "%s" not found in database.', $setNo)];
                continue;
            }

            // Validate Retailer
            $retailerRaw = (string) ($itemData['retailer'] ?? 'other');
            $retailer = Retailer::tryFrom($retailerRaw) ?? Retailer::OTHER;

            // Validate Amount
            $amountCents = (int) ($itemData['amountCents'] ?? 0);
            if ($amountCents <= 0) {
                if (isset($itemData['price']) && is_numeric($itemData['price']) && (float) $itemData['price'] > 0) {
                    $amountCents = (int) round(((float) $itemData['price']) * 100);
                } else {
                    if (!$isBatch) {
                        return $this->jsonError('Amount must be a positive number.', 400, $request);
                    }
                    $results[] = ['setNo' => $setNo, 'success' => false, 'error' => 'Amount must be a positive number.'];
                    continue;
                }
            }

            // Validate Currency
            $currency = strtoupper(trim((string) ($itemData['currency'] ?? 'CHF')));
            if (!in_array($currency, ['CHF', 'EUR', 'USD', 'GBP'], true)) {
                $currency = 'CHF';
            }

            // Validate Provider
            $provider = PriceSourceProvider::USER_BOOKMARKLET;
            if (isset($itemData['provider'])) {
                $provider = PriceSourceProvider::tryFrom((string) $itemData['provider']) ?? PriceSourceProvider::USER_BOOKMARKLET;
            }

            // Validate and sanitize URL
            $url = isset($itemData['url']) ? trim((string) $itemData['url']) : null;
            if ($url !== null && $url !== '') {
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                    if (!$isBatch) {
                        return $this->jsonError('Product URL must be a valid HTTP or HTTPS web address.', 400, $request);
                    }
                    $results[] = ['setNo' => $setNo, 'success' => false, 'error' => 'Product URL must be a valid HTTP or HTTPS web address.'];
                    continue;
                }
                if (!$this->isValidRetailerUrl($retailer, $url, $provider)) {
                    if (!$isBatch) {
                        return $this->jsonError('Product URL does not match the specified retailer domain.', 400, $request);
                    }
                    $results[] = ['setNo' => $setNo, 'success' => false, 'error' => 'Product URL does not match the specified retailer domain.'];
                    continue;
                }
            }

            // Validate sanity bounds: price should not be absurd (e.g. 0.01 CHF for a 1000-piece set or > 100,000)
            $decimalPrice = $amountCents / 100;
            if ($amountCents > 10_000_000) {
                if (!$isBatch) {
                    return $this->jsonError('Reported price exceeds maximum allowed bounds.', 422, $request);
                }
                $results[] = ['setNo' => $setNo, 'success' => false, 'error' => 'Reported price exceeds maximum allowed bounds.'];
                continue;
            }
            $pieceCount = $set->getPieceCount();
            if ($pieceCount > 50 && $decimalPrice < 2.0) {
                if (!$isBatch) {
                    return $this->jsonError('Price is unreasonably low for this set size.', 422, $request);
                }
                $results[] = ['setNo' => $setNo, 'success' => false, 'error' => 'Price is unreasonably low for this set size.'];
                continue;
            }

            $setPrice = new SetPrice(
                set: $set,
                amount: $amountCents,
                currency: $currency,
                retailer: $retailer,
                provider: $provider
            );
            $status = $user->isTrusted()
                ? PriceVerificationStatus::VERIFIED
                : PriceVerificationStatus::UNVERIFIED;
            $setPrice->setStatus($status);
            $setPrice->setUrl($url);
            $setPrice->setSubmittedBy($user);

            $set->addPrice($setPrice);
            $em->persist($setPrice);
            $em->persist($set);

            $recordedCount++;
            $lastSet = $set;
            $lastSetPrice = $setPrice;
            $lastRetailer = $retailer;

            $results[] = [
                'success' => true,
                'setNo' => $set->getNo(),
                'name' => $set->getName(),
                'price' => $setPrice->getFormattedPrice(),
                'retailer' => $retailer->label(),
                'status' => $setPrice->getStatus()->value,
            ];
        }

        if ($recordedCount > 0) {
            $em->flush();
        }

        if ($isBatch) {
            $response = new JsonResponse([
                'success' => true,
                'count' => $recordedCount,
                'results' => $results,
                'submittedBy' => $user->getUserIdentifier(),
            ]);
            $this->addCorsHeaders($response, $request);
            return $response;
        }

        $response = new JsonResponse([
            'success' => true,
            'setNo' => $lastSet?->getNo(),
            'name' => $lastSet?->getName(),
            'price' => $lastSetPrice?->getFormattedPrice(),
            'retailer' => $lastRetailer?->label(),
            'status' => $lastSetPrice?->getStatus()->value,
            'submittedBy' => $user->getUserIdentifier(),
        ]);

        $this->addCorsHeaders($response, $request);
        return $response;
    }

    private function isValidRetailerUrl(Retailer $retailer, string $url, ?PriceSourceProvider $provider = null): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        // If prices are reported from a price comparison aggregator like Toppreise:
        if ($provider === PriceSourceProvider::TOPPREISE || $this->isHostMatching($host, 'toppreise.ch')) {
            return $this->isHostMatching($host, 'toppreise.ch')
                || $this->isHostMatching($host, 'galaxus.ch')
                || $this->isHostMatching($host, 'digitec.ch')
                || $this->isHostMatching($host, 'brack.ch')
                || $this->isHostMatching($host, 'lego.com')
                || str_contains($host, 'amazon.');
        }

        return match ($retailer) {
            Retailer::GALAXUS => $this->isHostMatching($host, 'galaxus.ch') || $this->isHostMatching($host, 'digitec.ch'),
            Retailer::BRACK => $this->isHostMatching($host, 'brack.ch'),
            Retailer::AMAZON_DE => $this->isHostMatching($host, 'amazon.de'),
            Retailer::AMAZON_COM => $this->isHostMatching($host, 'amazon.com'),
            Retailer::LEGO_STORE => $this->isHostMatching($host, 'lego.com'),
            Retailer::BRICKLINK => $this->isHostMatching($host, 'bricklink.com'),
            Retailer::OTHER => true,
        };
    }

    private function isHostMatching(string $host, string $expectedDomain): bool
    {
        return $host === $expectedDomain || str_ends_with($host, '.' . $expectedDomain);
    }

    private function jsonError(string $message, int $status, Request $request): JsonResponse
    {
        $response = new JsonResponse(['success' => false, 'error' => $message], $status);
        $this->addCorsHeaders($response, $request);
        return $response;
    }

    private const array ALLOWED_ORIGIN_PATTERNS = [
        '#^https?://([a-z0-9-]+\.)*(galaxus\.(ch|de|at)|digitec\.ch|brack\.ch|amazon\.(de|com|co\.uk|fr|it|es)|toppreise\.ch|lego\.com)$#i',
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i',
    ];

    private function addCorsHeaders(Response $response, Request $request): void
    {
        $origin = $request->headers->get('Origin') ?? '';
        $allowed = false;
        if ($origin !== '') {
            foreach (self::ALLOWED_ORIGIN_PATTERNS as $pattern) {
                if (preg_match($pattern, $origin)) {
                    $allowed = true;
                    break;
                }
            }
        }

        if ($allowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }
    }
}
