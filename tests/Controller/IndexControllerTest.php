<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class IndexControllerTest extends WebTestCase
{
    public function testIndexPageReturnsSuccessfulResponse(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseRedirects('/en/');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('nav.navbar');
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Find the Best-Value LEGO Sets for Any Piece', $content);
    }

    public function testIndexPageGerman(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/de/');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Finde die preiswertesten LEGO Sets für jedes Teil', $content);
        $this->assertStringContainsString('Sets entdecken &amp; filtern', $content);
    }

    public function testIndexPageFrench(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/fr/');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Trouvez les sets LEGO au meilleur rapport qualité-prix', $content);
        $this->assertStringContainsString('Explorer &amp; filtrer les sets', $content);
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
