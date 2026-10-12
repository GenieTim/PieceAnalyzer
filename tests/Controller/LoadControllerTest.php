<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LoadControllerTest extends WebTestCase
{
    public function testLoadFilesPostWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_POST, '/files');

        $this->assertResponseRedirects('/all');
    }

    public function testLoadBrickpickerPricesPostWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_POST, '/price/brickpicker');

        $this->assertResponseRedirects('/all');
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
