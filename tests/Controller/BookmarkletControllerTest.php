<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class BookmarkletControllerTest extends WebTestCase
{
    public function testBookmarkletRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/bookmarklet');

        $this->assertResponseRedirects('http://localhost/en/login');
    }

    public function testBookmarkletPageReturnsSuccessfulWhenLoggedIn(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $user = new User('tester@example.com', 'password');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/en/bookmarklet');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Price Grabber Bookmarklet');
        $this->assertSelectorExists('a[href^="javascript:"]');
        $this->assertSelectorTextContains('code.font-monospace', $user->getApiToken());

        $crawler = $client->getCrawler();
        $href = (string) $crawler->filter('a[href^="javascript:"]')->attr('href');
        $this->assertStringStartsWith('javascript:', $href);
        $decodedJs = rawurldecode(substr($href, 11));
        $this->assertStringStartsWith('(function(){', $decodedJs);
        $this->assertStringEndsWith('})();', $decodedJs);

        // Verify JavaScript syntax using node if available
        $tempFile = tempnam(sys_get_temp_dir(), 'bm_ctrl_');
        $this->assertNotFalse($tempFile);
        try {
            file_put_contents($tempFile, $decodedJs);
            $process = new \Symfony\Component\Process\Process(['node', '-c', $tempFile]);
            $process->run();
            if ($process->getExitCode() !== 127) {
                $this->assertSame(
                    0,
                    $process->getExitCode(),
                    'Rendered bookmarklet link must be syntactically valid JavaScript: ' . $process->getErrorOutput()
                );
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        // Cleanup
        $em->remove($user);
        $em->flush();
    }

    public function testRegenerateTokenWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $user = new User('csrf_tester_' . $suffix . '@example.com', 'password');
        $originalToken = $user->getApiToken();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_POST, '/en/bookmarklet/regenerate-token');

        $this->assertResponseRedirects('/en/bookmarklet');

        // Token must NOT have changed
        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
        $this->assertNotNull($reloaded);
        $this->assertSame($originalToken, $reloaded->getApiToken());

        // Cleanup
        $em->remove($reloaded);
        $em->flush();
    }

    public function testRegenerateTokenWithValidCsrfTokenSucceeds(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $user = new User('csrf_success_' . $suffix . '@example.com', 'password');
        $originalToken = $user->getApiToken();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/en/bookmarklet');
        $this->assertResponseIsSuccessful();

        // Extract CSRF token from rendered form
        $csrfToken = $crawler->filter('form[action$="/en/bookmarklet/regenerate-token"] input[name="_token"]')->attr('value');
        $this->assertNotEmpty($csrfToken);

        $client->request(Request::METHOD_POST, '/en/bookmarklet/regenerate-token', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects('/en/bookmarklet');

        // Token MUST have changed and be a valid 64-char hex string
        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
        $this->assertNotNull($reloaded);
        $this->assertNotSame($originalToken, $reloaded->getApiToken());
        $this->assertSame(64, strlen((string) $reloaded->getApiToken()));

        // Cleanup
        $em->remove($reloaded);
        $em->flush();
    }

    public function testSubmitUnauthorizedWithoutTokenOrSession(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/bookmarklet/submit?data=' . urlencode(json_encode(['setNo' => '75192', 'amountCents' => 50000])));

        $this->assertResponseStatusCodeSame(401);
        $this->assertSelectorTextContains('.alert-danger', 'Unauthorized');
    }

    public function testSubmitSuccessWithValidToken(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $user = new User('submitter_' . $suffix . '@example.com', 'password');
        $token = 'tok_' . bin2hex(random_bytes(16));
        $user->setApiToken($token);
        $user->setIsEmailVerified(true);
        $em->persist($user);

        $set = new \App\Entity\Set();
        $set->setNo('8001-' . $suffix);
        $set->setName('Submit Test Set');
        $set->setPieceCount(100);
        $set->setSource(\App\Entity\Set::SOURCE_REBRICKABLE);
        $em->persist($set);
        $em->flush();

        $payload = [
            'setNo' => '8001-' . $suffix,
            'retailer' => 'galaxus',
            'amountCents' => 12900,
            'currency' => 'CHF',
            'token' => $token,
        ];

        $client->request(
            Request::METHOD_GET,
            '/en/bookmarklet/submit?token=' . urlencode($token) . '&data=' . urlencode(json_encode($payload))
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Price Recorded!');
        $this->assertSelectorTextContains('.list-group', '#8001-' . $suffix);
        $this->assertSelectorTextContains('.list-group', 'CHF 129.00');

        // Verify SetPrice in DB
        $em->clear();
        $prices = $em->getRepository(\App\Entity\SetPrice::class)->findBy(['amount' => 12900]);
        $this->assertNotEmpty($prices);

        // Cleanup
        foreach ($prices as $p) {
            $em->remove($p);
        }
        $reloadedSet = $em->getRepository(\App\Entity\Set::class)->findOneBy(['no' => '8001-' . $suffix]);
        if ($reloadedSet) {
            $em->remove($reloadedSet);
        }
        $reloadedUser = $em->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
        if ($reloadedUser) {
            $em->remove($reloadedUser);
        }
        $em->flush();
    }

    public function testDownloadChromeExtension(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/bookmarklet/download/chrome');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/zip');
        $this->assertStringContainsString('piece-analyzer-chrome.zip', (string) $client->getResponse()->headers->get('content-disposition'));
    }

    public function testDownloadFirefoxExtension(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/bookmarklet/download/firefox');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/zip');
        $this->assertStringContainsString('piece-analyzer-firefox.zip', (string) $client->getResponse()->headers->get('content-disposition'));
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
