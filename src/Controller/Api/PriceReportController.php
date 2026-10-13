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

        // 1. Authenticate user: session or Bearer API token
        $user = $this->getUser();
        if (!$user instanceof User) {
            $authHeader = $request->headers->get('Authorization');
            $token = '';
            if (is_string($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            } elseif (isset($data['token']) && is_string($data['token'])) {
                $token = $data['token'];
            }

            if ($token !== '') {
                $user = $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);
            }
        }

        if (!$user instanceof User) {
            return $this->jsonError('Unauthorized. Valid contributor API token or login session required.', 401, $request);
        }

        if ($user->isApiTokenExpired()) {
            return $this->jsonError('Contributor API token has expired. Please regenerate your token in your profile.', 401, $request);
        }

        // 2. Enforce Rate Limiting (10 requests / minute per user)
        $limiter = $this->priceReportLimiter->create($user->getUserIdentifier());
        if (!$limiter->consume()->isAccepted()) {
            return $this->jsonError('Rate limit exceeded (max 10 price reports per minute). Please wait.', 429, $request);
        }

        $setNo = trim((string) ($data['setNo'] ?? ''));
        if ($setNo === '') {
            return $this->jsonError('Missing set number (setNo).', 400, $request);
        }

        // Match set by number, trying with and without '-1' suffix
        $set = $setRepo->findOneBy(['no' => $setNo]);
        if (!$set instanceof Set) {
            $normalizedNo = str_contains($setNo, '-') ? $setNo : $setNo . '-1';
            $set = $setRepo->findOneBy(['no' => $normalizedNo]);
        }

        if (!$set instanceof Set) {
            return $this->jsonError(sprintf('Set "%s" not found in database.', $setNo), 404, $request);
        }

        // Validate Retailer
        $retailerRaw = (string) ($data['retailer'] ?? 'other');
        $retailer = Retailer::tryFrom($retailerRaw) ?? Retailer::OTHER;

        // Validate Amount
        $amountCents = (int) ($data['amountCents'] ?? 0);
        if ($amountCents <= 0) {
            if (isset($data['price']) && is_numeric($data['price']) && (float) $data['price'] > 0) {
                $amountCents = (int) round(((float) $data['price']) * 100);
            } else {
                return $this->jsonError('Amount must be a positive number.', 400, $request);
            }
        }

        // Validate Currency
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'CHF')));
        if (!in_array($currency, ['CHF', 'EUR', 'USD', 'GBP'], true)) {
            $currency = 'CHF';
        }

        // Validate and sanitize URL
        $url = isset($data['url']) ? trim((string) $data['url']) : null;
        if ($url !== null && $url !== '') {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $url = null;
            } elseif (!$this->isValidRetailerUrl($retailer, $url)) {
                return $this->jsonError('Product URL does not match the specified retailer domain.', 400, $request);
            }
        }

        // Validate sanity bounds: price should not be absurd (e.g. 0.01 CHF for a 1000-piece set)
        $decimalPrice = $amountCents / 100;
        $pieceCount = $set->getPieceCount();
        if ($pieceCount > 50 && $decimalPrice < 2.0) {
            return $this->jsonError('Price is unreasonably low for this set size.', 422, $request);
        }

        $provider = PriceSourceProvider::USER_BOOKMARKLET;
        if (isset($data['provider'])) {
            $provider = PriceSourceProvider::tryFrom((string) $data['provider']) ?? PriceSourceProvider::USER_BOOKMARKLET;
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
        $em->flush();

        $response = new JsonResponse([
            'success' => true,
            'setNo' => $set->getNo(),
            'name' => $set->getName(),
            'price' => $setPrice->getFormattedPrice(),
            'retailer' => $retailer->label(),
            'status' => $setPrice->getStatus()->value,
            'submittedBy' => $user->getUserIdentifier(),
        ]);

        $this->addCorsHeaders($response, $request);
        return $response;
    }

    private function isValidRetailerUrl(Retailer $retailer, string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return match ($retailer) {
            Retailer::GALAXUS => str_ends_with($host, 'galaxus.ch') || str_ends_with($host, 'digitec.ch'),
            Retailer::BRACK => str_ends_with($host, 'brack.ch'),
            Retailer::AMAZON_DE => str_ends_with($host, 'amazon.de'),
            Retailer::AMAZON_COM => str_ends_with($host, 'amazon.com'),
            Retailer::LEGO_STORE => str_ends_with($host, 'lego.com'),
            Retailer::OTHER => true,
        };
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
