<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LoadControllerTest extends WebTestCase
{
    public function testAnonymousAccessIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_POST, '/en/files');
        $this->assertResponseRedirects('http://localhost/en/login');

        $client->request(Request::METHOD_POST, '/en/price/brickpicker');
        $this->assertResponseRedirects('http://localhost/en/login');
    }

    public function testLoadFilesPostWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $admin = new \App\Entity\User('admin_load_' . $suffix . '@example.com', 'password');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, '/en/files');

        $this->assertResponseRedirects('/en/all');

        $toRemove = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $admin->getEmail()]);
        if ($toRemove !== null) {
            $em->remove($toRemove);
            $em->flush();
        }
    }

    public function testLoadBrickpickerPricesPostWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $admin = new \App\Entity\User('admin_price_' . $suffix . '@example.com', 'password');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_POST, '/en/price/brickpicker');

        $this->assertResponseRedirects('/en/all');

        $toRemove = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $admin->getEmail()]);
        if ($toRemove !== null) {
            $em->remove($toRemove);
            $em->flush();
        }
    }

    public function testRegularUserWithoutAdminRoleIsDenied(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $regularUser = new \App\Entity\User('user_noadmin_' . $suffix . '@example.com', 'password');
        $regularUser->setRoles(['ROLE_USER']);
        $em->persist($regularUser);
        $em->flush();

        $client->loginUser($regularUser);

        $client->request(Request::METHOD_POST, '/en/files');
        $this->assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, '/en/price/brickpicker');
        $this->assertResponseStatusCodeSame(403);

        $userToRemove = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $regularUser->getEmail()]);
        if ($userToRemove !== null) {
            $em->remove($userToRemove);
            $em->flush();
        }
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
