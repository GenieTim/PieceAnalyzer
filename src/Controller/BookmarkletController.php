<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Scraper\BookmarkletGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class BookmarkletController extends AbstractController
{
    public function __construct(
        private readonly BookmarkletGenerator $bookmarkletGenerator
    ) {
    }

    #[Route(path: '/bookmarklet', name: 'bookmarklet')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        $apiUrl = $this->generateUrl('api_price_report', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $submitUrl = $this->generateUrl('bookmarklet_submit', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $apiToken = $user->getApiToken();

        $bookmarkletHref = $this->bookmarkletGenerator->generateBookmarklet($apiUrl, $apiToken, $submitUrl);

        return $this->render('bookmarklet/index.html.twig', [
            'bookmarkletHref' => $bookmarkletHref,
            'apiUrl' => $apiUrl,
            'submitUrl' => $submitUrl,
            'apiToken' => $apiToken,
            'chromeDownloadUrl' => $this->generateUrl('bookmarklet_extension_download', ['browser' => 'chrome']),
            'firefoxDownloadUrl' => $this->generateUrl('bookmarklet_extension_download', ['browser' => 'firefox']),
        ]);
    }

    #[Route(path: '/bookmarklet/download/{browser}', name: 'bookmarklet_extension_download', defaults: ['browser' => 'chrome'])]
    public function downloadExtension(string $browser, \App\Service\Scraper\ExtensionPackagerService $packager): Response
    {
        $path = $packager->getPackagePath($browser);
        if ($path === null || !file_exists($path)) {
            throw $this->createNotFoundException('Extension package not found.');
        }

        return $this->file($path, basename($path));
    }

    #[Route(path: '/bookmarklet/regenerate-token', name: 'bookmarklet_regenerate_token', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function regenerateToken(Request $request, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('regenerate_token', (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('bookmarklet');
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $user->regenerateApiToken();
            $em->persist($user);
            $em->flush();
            $this->addFlash('success', 'Your Contributor API token has been regenerated.');
        }

        return $this->redirectToRoute('bookmarklet');
    }

    #[Route(path: '/bookmarklet/submit', name: 'bookmarklet_submit', methods: ['GET', 'POST'])]
    public function submit(
        Request $request,
        \App\Repository\SetRepository $setRepo,
        EntityManagerInterface $em,
        \Symfony\Component\RateLimiter\RateLimiterFactory $priceReportLimiter
    ): Response {
        // 1. Authenticate user: Token or active session
        $token = (string) ($request->query->get('token') ?? $request->request->get('token') ?? '');
        $user = null;

        if ($token !== '') {
            $user = $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);
        }

        if (!$user instanceof User && $this->getUser() instanceof User) {
            $user = $this->getUser();
        }

        if (!$user instanceof User) {
            return $this->render('bookmarklet/submit.html.twig', [
                'success' => false,
                'recorded' => [],
                'errors' => ['Unauthorized. A valid contributor API token or active login session is required.'],
            ], new Response('', Response::HTTP_UNAUTHORIZED));
        }

        if ($user->isApiTokenExpired()) {
            return $this->render('bookmarklet/submit.html.twig', [
                'success' => false,
                'recorded' => [],
                'errors' => ['Contributor API token has expired. Please regenerate your token in your profile.'],
            ], new Response('', Response::HTTP_UNAUTHORIZED));
        }

        if (!$user->isEmailVerified()) {
            return $this->render('bookmarklet/submit.html.twig', [
                'success' => false,
                'recorded' => [],
                'errors' => ['Email address must be verified before reporting prices.'],
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        // 2. Rate limit
        $limiter = $priceReportLimiter->create($user->getUserIdentifier());
        if (!$limiter->consume()->isAccepted()) {
            return $this->render('bookmarklet/submit.html.twig', [
                'success' => false,
                'recorded' => [],
                'errors' => ['Rate limit exceeded (max 10 price reports per minute). Please wait a moment.'],
            ], new Response('', Response::HTTP_TOO_MANY_REQUESTS));
        }

        // 3. Extract items payload
        $rawPayload = (string) ($request->request->get('payload') ?? $request->query->get('data') ?? '');
        $data = [];
        if ($rawPayload !== '') {
            $decoded = json_decode($rawPayload, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if ($data === []) {
            $content = (string) $request->getContent();
            if ($content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        if ($data === [] && $request->get('setNo')) {
            $data = [
                'setNo' => $request->get('setNo'),
                'amountCents' => $request->get('amountCents'),
                'price' => $request->get('price'),
                'currency' => $request->get('currency'),
                'retailer' => $request->get('retailer'),
                'url' => $request->get('url'),
            ];
        }

        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = isset($data['items']) && is_array($data['items'])
            ? $data['items']
            : ($data === [] ? [] : [$data]);

        if (empty($rawItems)) {
            return $this->render('bookmarklet/submit.html.twig', [
                'success' => false,
                'recorded' => [],
                'errors' => ['No price report data provided.'],
            ], new Response('', Response::HTTP_BAD_REQUEST));
        }

        $recorded = [];
        $errors = [];

        foreach ($rawItems as $itemData) {
            $setNo = trim((string) ($itemData['setNo'] ?? ''));
            if ($setNo === '') {
                $errors[] = 'Missing set number (setNo).';
                continue;
            }

            // Match set by number
            $set = $setRepo->findOneBy(['no' => $setNo]);
            if (!$set instanceof \App\Entity\Set) {
                $normalizedNo = str_contains($setNo, '-') ? $setNo : $setNo . '-1';
                $set = $setRepo->findOneBy(['no' => $normalizedNo]);
            }

            if (!$set instanceof \App\Entity\Set) {
                $errors[] = sprintf('Set "%s" not found in database.', $setNo);
                continue;
            }

            // Validate Retailer
            $retailerRaw = (string) ($itemData['retailer'] ?? 'other');
            $retailer = \App\Enum\Retailer::tryFrom($retailerRaw) ?? \App\Enum\Retailer::OTHER;

            // Validate Amount
            $amountCents = (int) ($itemData['amountCents'] ?? 0);
            if ($amountCents <= 0) {
                if (isset($itemData['price']) && is_numeric($itemData['price']) && (float) $itemData['price'] > 0) {
                    $amountCents = (int) round(((float) $itemData['price']) * 100);
                } else {
                    $errors[] = sprintf('Invalid price for Set #%s.', $setNo);
                    continue;
                }
            }

            // Validate bounds
            if ($amountCents > 10_000_000) {
                $errors[] = sprintf('Price exceeds maximum allowed bounds for Set #%s.', $setNo);
                continue;
            }

            $currency = strtoupper(trim((string) ($itemData['currency'] ?? 'CHF')));
            if (!in_array($currency, ['CHF', 'EUR', 'USD', 'GBP'], true)) {
                $currency = 'CHF';
            }

            $provider = \App\Enum\PriceSourceProvider::USER_BOOKMARKLET;
            $url = isset($itemData['url']) ? trim((string) $itemData['url']) : null;

            $setPrice = new \App\Entity\SetPrice(
                set: $set,
                amount: $amountCents,
                currency: $currency,
                retailer: $retailer,
                provider: $provider
            );

            $status = $user->isTrusted()
                ? \App\Enum\PriceVerificationStatus::VERIFIED
                : \App\Enum\PriceVerificationStatus::UNVERIFIED;
            $setPrice->setStatus($status);
            $setPrice->setUrl($url);
            $setPrice->setSubmittedBy($user);

            $set->addPrice($setPrice);
            $em->persist($setPrice);
            $em->persist($set);

            $recorded[] = [
                'setNo' => $set->getNo(),
                'name' => $set->getName(),
                'price' => $setPrice->getFormattedPrice(),
                'retailer' => $retailer->label(),
            ];
        }

        if ($recorded !== []) {
            $em->flush();
        }

        $overallSuccess = $recorded !== [];

        return $this->render('bookmarklet/submit.html.twig', [
            'success' => $overallSuccess,
            'recorded' => $recorded,
            'errors' => $errors,
        ]);
    }
}
