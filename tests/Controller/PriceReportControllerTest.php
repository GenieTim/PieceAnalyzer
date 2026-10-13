<?php

declare(strict_types=1);

namespace App\Tests\Controller;

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
