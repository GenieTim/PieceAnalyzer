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
        $client->request(Request::METHOD_GET, '/en/preferences/currency/EUR');

        $this->assertResponseRedirects('/en/filter');

        $session = $client->getRequest()->getSession();
        $this->assertSame('EUR', $session->get('pref_currency'));
    }

    public function testSetCurrencyPreferenceOpenRedirectIsBlocked(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/preferences/currency/USD', [], [], [
            'HTTP_REFERER' => 'https://evil-phishing.com/scam',
        ]);

        // Must redirect to safe local route, NOT evil-phishing.com
        $this->assertResponseRedirects('/en/filter');
    }

    public function testSetCurrencyPreferenceValidLocalRefererIsAllowed(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/preferences/currency/GBP', [], [], [
            'HTTP_REFERER' => 'http://localhost/en/filter?color=2',
        ]);

        $this->assertResponseRedirects('http://localhost/en/filter?color=2');
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
        $client->request(Request::METHOD_GET, '/en/preferences/currency/USD');

        $this->assertResponseRedirects('/en/filter');

        // Verify entity in database is updated
        $em->clear();
        $reloaded = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $user->getEmail()]);
        $this->assertNotNull($reloaded);
        $this->assertSame('USD', $reloaded->getPreferredCurrency());

        // Cleanup
        $em->remove($reloaded);
        $em->flush();
    }

    public function testSetLocalePreference(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/preferences/locale/de');

        $this->assertResponseRedirects('/de/filter');

        $session = $client->getRequest()->getSession();
        $this->assertSame('de', $session->get('_locale'));
    }

    public function testSetLocalePreferenceValidReferer(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/preferences/locale/de', [], [], [
            'HTTP_REFERER' => 'http://localhost/en/filter',
        ]);

        $this->assertResponseRedirects('http://localhost/de/filter');
    }

    public function testSetLocalePreferenceOpenRedirectIsBlocked(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/en/preferences/locale/de', [], [], [
            'HTTP_REFERER' => 'https://malicious-site.example.com/exploit',
        ]);

        $this->assertResponseRedirects('/de/filter');
    }

    public function testRootRedirectsBasedOnBrowserLanguageGerman(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/', [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'de-CH,de;q=0.9,en;q=0.8',
        ]);

        $this->assertResponseRedirects('/de/');
    }

    public function testRootRedirectsBasedOnBrowserLanguageEnglish(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/', [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ]);

        $this->assertResponseRedirects('/en/');
    }

    public function testLocalizedPagesRenderCorrectLanguage(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/de/filter');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Sets entdecken', (string) $client->getResponse()->getContent());

        $client->request(Request::METHOD_GET, '/en/filter');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Explore Sets', (string) $client->getResponse()->getContent());
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
