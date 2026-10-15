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
        $this->assertSelectorTextContains('code', $user->getApiToken());

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
