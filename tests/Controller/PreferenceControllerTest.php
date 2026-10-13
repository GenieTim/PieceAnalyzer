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
