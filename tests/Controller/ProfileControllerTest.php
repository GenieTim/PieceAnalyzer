<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProfileControllerTest extends WebTestCase
{
    private function cleanDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\SetPrice')->execute();
        $em->createQuery('DELETE FROM App\Entity\Piece')->execute();
        $em->createQuery('DELETE FROM App\Entity\Set')->execute();
        $em->createQuery('DELETE FROM App\Entity\Item')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testAnonymousRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/profile');
        $this->assertResponseRedirects('/en/login');
    }

    public function testAuthenticatedUserCanViewProfile(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $user = new User('profileuser@example.com', 'hashed');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/profile');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'User Profile & Preferences');
        $this->assertSelectorTextContains('.card', 'profileuser@example.com');
    }

    public function testUpdatePreferences(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $user = new User('pref@example.com', 'hashed');
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/profile');
        $token = $crawler->filter('form[action$="/en/profile/preferences"] input[name="_token"]')->attr('value');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/en/profile/preferences', [
            '_token' => $token,
            'currency' => 'EUR',
            'retailers' => ['galaxus', 'amazon_de'],
        ]);

        $this->assertResponseRedirects('/en/profile');

        $em->clear();
        $updated = $em->find(User::class, $user->getId());
        $this->assertSame('EUR', $updated?->getPreferredCurrency());
        $this->assertSame(['galaxus', 'amazon_de'], $updated?->getPreferredRetailers());
    }

    public function testChangePasswordSuccess(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->cleanDatabase($em);

        $user = new User('changepass@example.com', '');
        $user->setPassword($hasher->hashPassword($user, 'oldSecretPassword123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/profile');
        $token = $crawler->filter('form[action$="/en/profile/password"] input[name="_token"]')->attr('value');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/en/profile/password', [
            '_token' => $token,
            'current_password' => 'oldSecretPassword123',
            'new_password' => 'newSecretPassword456',
            'confirm_password' => 'newSecretPassword456',
        ]);

        $this->assertResponseRedirects('/en/profile');

        $em->clear();
        $updated = $em->find(User::class, $user->getId());
        $this->assertNotNull($updated);
        $this->assertTrue($hasher->isPasswordValid($updated, 'newSecretPassword456'));
    }

    public function testChangePasswordWrongCurrentPassword(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->cleanDatabase($em);

        $user = new User('wrongpass@example.com', '');
        $user->setPassword($hasher->hashPassword($user, 'oldSecretPassword123'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/profile');
        $token = $crawler->filter('form[action$="/en/profile/password"] input[name="_token"]')->attr('value');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/en/profile/password', [
            '_token' => $token,
            'current_password' => 'wrongPassword',
            'new_password' => 'newSecretPassword456',
            'confirm_password' => 'newSecretPassword456',
        ]);

        $this->assertResponseRedirects('/en/profile');
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Current password is not correct');
    }

    public function testRegenerateApiToken(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $user = new User('retoken@example.com', 'hashed');
        $oldToken = $user->getApiToken();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/profile');
        $token = $crawler->filter('form[action$="/en/profile/regenerate-token"] input[name="_token"]')->attr('value');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/en/profile/regenerate-token', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/en/profile');

        $em->clear();
        $updated = $em->find(User::class, $user->getId());
        $this->assertNotSame($oldToken, $updated?->getApiToken());
    }
}
