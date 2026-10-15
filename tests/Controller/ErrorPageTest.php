<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

class ErrorPageTest extends WebTestCase
{
    public function testError404TemplateRendersDirectly(): void
    {
        static::createClient();
        /** @var Environment $twig */
        $twig = static::getContainer()->get(Environment::class);

        $html = $twig->render('bundles/TwigBundle/Exception/error404.html.twig', [
            'status_code' => 404,
            'status_text' => 'Not Found',
        ]);

        $this->assertStringContainsString('404 - Missing Brick', $html);
        $this->assertStringContainsString('The set or piece you were looking for seems to have rolled under the sofa.', $html);
        $this->assertStringContainsString('Explore Sets', $html);
        $this->assertStringContainsString('Return Home', $html);
    }

    public function testError500TemplateRendersDirectly(): void
    {
        static::createClient();
        /** @var Environment $twig */
        $twig = static::getContainer()->get(Environment::class);

        $html = $twig->render('bundles/TwigBundle/Exception/error500.html.twig', [
            'status_code' => 500,
            'status_text' => 'Internal Server Error',
        ]);

        $this->assertStringContainsString('500 - Assembly Malfunction', $html);
        $this->assertStringContainsString('Something went wrong while assembling this page.', $html);
        $this->assertStringContainsString('Return Home', $html);
    }

    public function testGenericErrorTemplateRendersDirectly(): void
    {
        static::createClient();
        /** @var Environment $twig */
        $twig = static::getContainer()->get(Environment::class);

        $html = $twig->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => 403,
            'status_text' => 'Forbidden',
        ]);

        $this->assertStringContainsString('403 - Forbidden', $html);
        $this->assertStringContainsString('An unexpected building mishap occurred.', $html);
        $this->assertStringContainsString('Return Home', $html);
    }

    public function testHttp404RendersBrandedTemplate(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request(Request::METHOD_GET, '/en/this-route-does-not-exist-at-all');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('h1', '404 - Missing Brick');
        $this->assertSelectorTextContains('.lead', 'rolled under the sofa');
        $this->assertSelectorExists('a[href="/en/filter"]');
        $this->assertSelectorExists('a[href="/en/"]');
    }
}
