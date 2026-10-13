<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageRenders(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Sign In');
        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testLoginSuccessAndLogout(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'login_test_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = new User($testEmail);
        $user->setPassword($hasher->hashPassword($user, 'Secret123!'));
        $em->persist($user);
        $em->flush();

        try {
            $client->request(Request::METHOD_GET, '/login');
            $client->submitForm('Sign In', [
                '_username' => $testEmail,
                '_password' => 'Secret123!',
            ]);

            $this->assertResponseRedirects('/');

            $client->followRedirect();
            $this->assertSelectorTextContains('nav', $testEmail);

            // Logout
            $client->request(Request::METHOD_GET, '/logout');
            $this->assertResponseRedirects('/');
        } finally {
            // Cleanup
            $cleanEm = $client->getContainer()->get(EntityManagerInterface::class);
            $u = $cleanEm->getRepository(User::class)->findOneBy(['email' => $testEmail]);
            if ($u !== null) {
                $cleanEm->remove($u);
                $cleanEm->flush();
            }
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
