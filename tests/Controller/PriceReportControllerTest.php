<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Piece;
use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class PriceReportControllerTest extends WebTestCase
{
    private ?EntityManagerInterface $em = null;

    public function testOptionsPreflightReturnsCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_OPTIONS, '/api/prices/report', [], [], [
            'HTTP_ORIGIN' => 'https://www.galaxus.ch',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
        $this->assertSame(
            'https://www.galaxus.ch',
            $client->getResponse()->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertTrue($client->getResponse()->headers->has('Access-Control-Allow-Methods'));
    }

    public function testUntrustedOriginDoesNotReceiveCorsHeader(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_OPTIONS, '/api/prices/report', [], [], [
            'HTTP_ORIGIN' => 'https://evil-untrusted-site.com',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertFalse(
            $client->getResponse()->headers->has('Access-Control-Allow-Origin'),
            'Untrusted third-party origins must not be reflected in Access-Control-Allow-Origin.'
        );
    }

    public function testQueryParamTokenIsRejectedAsUnauthorized(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $user = new User('leak_tester_' . $suffix . '@example.com', 'password');
        $token = 'leak_token_' . bin2hex(random_bytes(16));
        $user->setApiToken($token);
        $em->persist($user);
        $em->flush();

        // Submitting token in query param is insecure and must be rejected
        $client->request(
            Request::METHOD_POST,
            '/api/prices/report?token=' . urlencode($token),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['setNo' => '75192', 'amountCents' => 50000])
        );

        $this->assertResponseStatusCodeSame(401);

        $em->remove($user);
        $em->flush();
    }

    public function testReportPriceUnauthorizedWithoutToken(): void
    {
        $client = static::createClient();

        $client->request(
            Request::METHOD_POST,
            '/api/prices/report',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['setNo' => '75192', 'amountCents' => 50000])
        );

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Unauthorized', $data['error']);
    }

    public function testReportPriceSuccessfulWithToken(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'contributor_' . $suffix . '@example.com';
        $token = 'token_' . bin2hex(random_bytes(16));

        // Create a verified contributor test user
        $user = new User($email, 'password');
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $user->setIsVerifiedContributor(true);
        $this->em->persist($user);


        // Create a test set
        $setNo = '99999_' . $suffix;
        $set = new Set();
        $set->setNo($setNo . '-1');
        $set->setName('Test Bookmarklet Set');
        $this->em->persist($set);
        $this->em->flush();

        $client->request(
            Request::METHOD_POST,
            '/api/prices/report',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://www.galaxus.ch',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'setNo' => $setNo,
                'retailer' => 'galaxus',
                'amountCents' => 8990,
                'currency' => 'CHF',
                'url' => 'https://www.galaxus.ch/en/s1/product/lego-' . $setNo,
                'provider' => 'bookmarklet',
            ])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($setNo . '-1', $data['setNo']);
        $this->assertSame('CHF 89.90', $data['price']);
        $this->assertSame('Galaxus.ch', $data['retailer']);
        $this->assertSame($email, $data['submittedBy']);

        // Verify Set and SetPrice in database
        $this->em->clear();
        $reloadedSet = $this->em->getRepository(Set::class)->findOneBy(['no' => $setNo . '-1']);
        $this->assertNotNull($reloadedSet);
        $this->assertSame(89.90, $reloadedSet->getPrice());
        $this->assertCount(1, $reloadedSet->getPrices());

        $firstPrice = $reloadedSet->getPrices()->first();
        $this->assertInstanceOf(SetPrice::class, $firstPrice);
        $this->assertSame($email, $firstPrice->getSubmittedBy()?->getEmail());
        $this->assertSame(\App\Enum\PriceVerificationStatus::VERIFIED, $firstPrice->getStatus());

        // Cleanup
        $this->em->remove($reloadedSet);
        $reloadedUser = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($reloadedUser !== null) {
            $this->em->remove($reloadedUser);
        }
        $this->em->flush();
    }

    public function testReportPriceUnverifiedUserDoesNotUpdateCanonicalSetPrice(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'unverified_' . $suffix . '@example.com';
        $token = 'unverified_token_' . bin2hex(random_bytes(16));

        // Create an unverified user
        $user = new User($email, 'password');
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $user->setIsVerifiedContributor(false);
        $this->em->persist($user);


        // Create a test set without canonical price
        $setNo = '88888_' . $suffix;
        $set = new Set();
        $set->setNo($setNo . '-1');
        $set->setName('Test Unverified Set');
        $this->em->persist($set);
        $this->em->flush();

        $client->request(
            Request::METHOD_POST,
            '/api/prices/report',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://www.galaxus.ch',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'setNo' => $setNo,
                'retailer' => 'galaxus',
                'amountCents' => 5990,
                'currency' => 'CHF',
            ])
        );

        $this->assertResponseIsSuccessful();

        // Verify Set and SetPrice in database
        $this->em->clear();
        $reloadedSet = $this->em->getRepository(Set::class)->findOneBy(['no' => $setNo . '-1']);
        $this->assertNotNull($reloadedSet);
        // Canonical price must remain null because user is unverified!
        $this->assertNull($reloadedSet->getPrice());
        $this->assertCount(1, $reloadedSet->getPrices());

        $firstPrice = $reloadedSet->getPrices()->first();
        $this->assertInstanceOf(SetPrice::class, $firstPrice);
        $this->assertSame(\App\Enum\PriceVerificationStatus::UNVERIFIED, $firstPrice->getStatus());

        // Cleanup
        $this->em->remove($reloadedSet);
        $reloadedUser = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($reloadedUser !== null) {
            $this->em->remove($reloadedUser);
        }
        $this->em->flush();
    }

    public function testReportPriceValidationErrorsWithToken(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'validator_' . $suffix . '@example.com';
        $token = 'validator_token_' . bin2hex(random_bytes(16));

        $user = new User($email, 'password');
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $this->em->persist($user);

        $this->em->flush();

        // 1. Missing setNo
        $client->request(
            Request::METHOD_POST,
            '/api/prices/report',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([])
        );
        $this->assertResponseStatusCodeSame(400);

        // 2. Non-existent set
        $client->request(
            Request::METHOD_POST,
            '/api/prices/report',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'setNo' => 'non-existent-000',
                'amountCents' => 5000,
            ])
        );
        $this->assertResponseStatusCodeSame(404);

        // Cleanup
        $u = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($u !== null) {
            $this->em->remove($u);
            $this->em->flush();
        }
    }

    public function testExpiredApiTokenReturns401(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $email = 'expired_user_' . bin2hex(random_bytes(4)) . '@example.com';
        $token = 'expired_token_' . bin2hex(random_bytes(8));

        $user = new User($email, 'pass');
        $user->setApiToken($token);
        $user->setApiTokenExpiresAt((new \DateTimeImmutable())->modify('-1 day'));
        $this->em->persist($user);
        $this->em->flush();

        $client->request(
            Request::METHOD_POST,
            '/api/prices/report',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'setNo' => '10294',
                'retailer' => 'galaxus',
                'amountCents' => 5000,
            ])
        );

        $this->assertResponseStatusCodeSame(401);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertStringContainsString('expired', strtolower($data['error'] ?? ''));

        $this->em->remove($user);
        $this->em->flush();
    }

    public function testRejectMaliciousJavascriptUrl(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'contributor_xss_' . $suffix . '@example.com';
        $token = 'token_xss_' . bin2hex(random_bytes(16));

        $user = new User($email, 'password');
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $this->em->persist($user);


        $set = new Set();
        $set->setNo('XSS_' . $suffix . '-1');
        $set->setName('XSS Test Set');
        $this->em->persist($set);
        $this->em->flush();

        try {
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([
                    'setNo' => 'XSS_' . $suffix,
                    'retailer' => 'other',
                    'amountCents' => 5000,
                    'url' => 'javascript:alert(document.cookie)',
                ])
            );

            $this->assertResponseStatusCodeSame(400);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('HTTP or HTTPS', $data['error']);
        } finally {
            $this->em->remove($set);
            $this->em->remove($user);
            $this->em->flush();
        }
    }

    public function testRejectSpoofedDomainUrl(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'contributor_spoof_' . $suffix . '@example.com';
        $token = 'token_spoof_' . bin2hex(random_bytes(16));

        $user = new User($email, 'password');
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $this->em->persist($user);


        $set = new Set();
        $set->setNo('SPOOF_' . $suffix . '-1');
        $set->setName('Spoof Test Set');
        $this->em->persist($set);
        $this->em->flush();

        try {
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([
                    'setNo' => 'SPOOF_' . $suffix,
                    'retailer' => 'galaxus',
                    'amountCents' => 5000,
                    'url' => 'https://evilattackergalaxus.ch/product/123',
                ])
            );

            $this->assertResponseStatusCodeSame(400);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('does not match the specified retailer domain', $data['error']);
        } finally {
            $this->em->remove($set);
            $this->em->remove($user);
            $this->em->flush();
        }
    }

    public function testRejectExcessivePriceBounds(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'contributor_bound_' . $suffix . '@example.com';
        $token = 'token_bound_' . bin2hex(random_bytes(16));

        $user = new User($email, 'password');
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $this->em->persist($user);


        $set = new Set();
        $set->setNo('BOUND_' . $suffix . '-1');
        $set->setName('Bound Test Set');
        $this->em->persist($set);
        $this->em->flush();

        try {
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([
                    'setNo' => 'BOUND_' . $suffix,
                    'retailer' => 'galaxus',
                    'amountCents' => 50_000_000,
                    'url' => 'https://www.galaxus.ch/product/123',
                ])
            );

            $this->assertResponseStatusCodeSame(422);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('exceeds maximum allowed bounds', $data['error']);
        } finally {
            $this->em->remove($set);
            $this->em->remove($user);
            $this->em->flush();
        }
    }

    public function testReportBatchPricesSuccessfulWithToken(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $user = new User('batch_tester_' . $suffix . '@example.com', 'password');
        $token = 'batch_token_' . bin2hex(random_bytes(16));
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $this->em->persist($user);


        $set1 = new Set();
        $set1->setNo('BATCH_1_' . $suffix . '-1');
        $set1->setName('Batch Set 1');
        $this->em->persist($set1);

        $set2 = new Set();
        $set2->setNo('BATCH_2_' . $suffix . '-1');
        $set2->setName('Batch Set 2');
        $this->em->persist($set2);

        $this->em->flush();

        try {
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([
                    'items' => [
                        [
                            'setNo' => 'BATCH_1_' . $suffix,
                            'retailer' => 'galaxus',
                            'amountCents' => 2890,
                            'currency' => 'CHF',
                            'url' => 'https://www.galaxus.ch/product/batch1',
                        ],
                        [
                            'setNo' => 'BATCH_2_' . $suffix,
                            'retailer' => 'brack',
                            'amountCents' => 37200,
                            'currency' => 'CHF',
                            'url' => 'https://www.brack.ch/product/batch2',
                        ],
                    ],
                ])
            );

            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertSame(2, $data['count']);
            $this->assertCount(2, $data['results']);

            // Verify prices were persisted in database
            $price1 = $this->em->getRepository(SetPrice::class)->findOneBy(['set' => $set1]);
            $this->assertNotNull($price1);
            $this->assertSame(2890, $price1->getAmount());

            $price2 = $this->em->getRepository(SetPrice::class)->findOneBy(['set' => $set2]);
            $this->assertNotNull($price2);
            $this->assertSame(37200, $price2->getAmount());
        } finally {
            $this->em->remove($set1);
            $this->em->remove($set2);
            $this->em->remove($user);
            $this->em->flush();
        }
    }

    public function testReportToppreiseSourceAndUrlAllowed(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $user = new User('toppreise_tester_' . $suffix . '@example.com', 'password');
        $token = 'toppreise_token_' . bin2hex(random_bytes(16));
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $this->em->persist($user);


        $set = new Set();
        $set->setNo('TOP_TEST_' . $suffix . '-1');
        $set->setName('Toppreise Test Set');
        $this->em->persist($set);
        $this->em->flush();

        try {
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([
                    'setNo' => 'TOP_TEST_' . $suffix,
                    'retailer' => 'galaxus',
                    'amountCents' => 2890,
                    'currency' => 'CHF',
                    'url' => 'https://www.toppreise.ch/preisvergleich/Lego/set-40809',
                    'provider' => 'toppreise',
                ])
            );

            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);

            $price = $this->em->getRepository(SetPrice::class)->findOneBy(['set' => $set]);
            $this->assertNotNull($price);
            $this->assertSame('toppreise', $price->getProvider()->value);
            $this->assertSame('https://www.toppreise.ch/preisvergleich/Lego/set-40809', $price->getUrl());
        } finally {
            $this->em->remove($set);
            $this->em->remove($user);
        }
    }

    public function testUnverifiedEmailReturns403(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $email = 'unverified_' . bin2hex(random_bytes(4)) . '@example.com';
        $token = 'unverified_token_' . bin2hex(random_bytes(8));

        $user = new User($email, 'pass');
        $user->setApiToken($token);
        $user->setIsEmailVerified(false);
        $this->em->persist($user);
        $this->em->flush();

        try {
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([
                    'setNo' => '10294',
                    'retailer' => 'galaxus',
                    'amountCents' => 5000,
                ])
            );

            $this->assertResponseStatusCodeSame(403);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('verified', strtolower($data['error'] ?? ''));
        } finally {
            $this->em->remove($user);
            $this->em->flush();
        }
    }

    public function testBatchLimitExceeded(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'batch_limit_' . $suffix . '@example.com';
        $user = new User($email, 'password');
        $token = 'batch_limit_token_' . bin2hex(random_bytes(16));
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $em->persist($user);
        $em->flush();

        try {
            $items = [];
            for ($i = 0; $i < 101; $i++) {
                $items[] = [
                    'setNo' => '10294',
                    'retailer' => 'galaxus',
                    'amountCents' => 5000,
                ];
            }

            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode(['items' => $items])
            );

            $this->assertResponseStatusCodeSame(400);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertSame('Batch exceeds maximum limit of 100 items per request.', $data['error']);
        } finally {
            $cleanEm = static::getContainer()->get(EntityManagerInterface::class);
            $u = $cleanEm->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($u !== null) {
                $cleanEm->remove($u);
                $cleanEm->flush();
            }
        }
    }

    public function testEmptyPayload(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'empty_payload_' . $suffix . '@example.com';
        $user = new User($email, 'password');
        $token = 'empty_token_' . bin2hex(random_bytes(16));
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $em->persist($user);
        $em->flush();

        try {
            // 1. Empty JSON array payload []
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([])
            );

            $this->assertResponseStatusCodeSame(400);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertSame('No price report data provided.', $data['error']);

            // 2. Empty items array payload {'items': []}
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode(['items' => []])
            );

            $this->assertResponseStatusCodeSame(400);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertSame('No price report data provided.', $data['error']);
        } finally {
            $cleanEm = static::getContainer()->get(EntityManagerInterface::class);
            $u = $cleanEm->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($u !== null) {
                $cleanEm->remove($u);
                $cleanEm->flush();
            }
        }
    }

    public function testLowerSanityBoundRejectionForLargeSet(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $email = 'sanity_' . $suffix . '@example.com';
        $user = new User($email, 'password');
        $token = 'sanity_token_' . bin2hex(random_bytes(16));
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $em->persist($user);

        $setNo = 'SANITY_' . $suffix;
        $set = new Set();
        $set->setNo($setNo . '-1');
        $set->setName('Large Sanity Test Set');
        $em->persist($set);

        $piece = new Piece();
        $piece->setNo('P_' . $suffix);
        $piece->setName('Test Part');
        $piece->setCategory(1);
        $piece->setColor(1);
        $piece->setCount(100);
        $set->addPiece($piece);
        $em->persist($piece);

        $em->flush();

        try {
            $client->request(
                Request::METHOD_POST,
                '/api/prices/report',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                ],
                json_encode([
                    'setNo' => $setNo,
                    'retailer' => 'galaxus',
                    'amountCents' => 100,
                    'currency' => 'CHF',
                ])
            );

            $this->assertResponseStatusCodeSame(422);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertFalse($data['success']);
            $this->assertSame('Price is unreasonably low for this set size.', $data['error']);
        } finally {
            $cleanEm = static::getContainer()->get(EntityManagerInterface::class);
            $s = $cleanEm->getRepository(Set::class)->findOneBy(['no' => $setNo . '-1']);
            if ($s !== null) {
                foreach ($s->getPieces() as $p) {
                    $cleanEm->remove($p);
                }
                $cleanEm->remove($s);
            }
            $u = $cleanEm->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($u !== null) {
                $cleanEm->remove($u);
            }
            $cleanEm->flush();
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
        while (true) {
            $previous = set_exception_handler(null);
            restore_exception_handler();
            if ($previous === null) {
                break;
            }
            restore_exception_handler();
        }
    }
}
