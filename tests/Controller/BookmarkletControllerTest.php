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
        $client->request(Request::METHOD_GET, '/bookmarklet');

        $this->assertResponseRedirects('http://localhost/login');
    }

    public function testBookmarkletPageReturnsSuccessfulWhenLoggedIn(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $user = new User('tester@example.com', 'password');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/bookmarklet');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Price Grabber Bookmarklet');
        $this->assertSelectorExists('a[href^="javascript:"]');
        $this->assertSelectorTextContains('code', $user->getApiToken());

        // Cleanup
        $em->remove($user);
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
