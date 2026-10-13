<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class PreferenceControllerTest extends WebTestCase
{
    public function testSetCurrencyPreference(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/preferences/currency/EUR');

        $this->assertResponseRedirects('/filter');

        $session = $client->getRequest()->getSession();
        $this->assertSame('EUR', $session->get('pref_currency'));
    }

    public function testSetCurrencyPreferenceOpenRedirectIsBlocked(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/preferences/currency/USD', [], [], [
            'HTTP_REFERER' => 'https://evil-phishing.com/scam',
        ]);

        // Must redirect to safe local route, NOT evil-phishing.com
        $this->assertResponseRedirects('/filter');
    }

    public function testSetCurrencyPreferenceValidLocalRefererIsAllowed(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/preferences/currency/GBP', [], [], [
            'HTTP_REFERER' => 'http://localhost/filter?color=2',
        ]);

        $this->assertResponseRedirects('http://localhost/filter?color=2');
    }

    public function testSetCurrencyPreferencePersistsToLoggedInUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $user = new \App\Entity\User('pref_user_' . $suffix . '@example.com', 'password');
        $user->setPreferredCurrency('CHF');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/preferences/currency/USD');

        $this->assertResponseRedirects('/filter');

        // Verify entity in database is updated
        $em->clear();
        $reloaded = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $user->getEmail()]);
        $this->assertNotNull($reloaded);
        $this->assertSame('USD', $reloaded->getPreferredCurrency());

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
