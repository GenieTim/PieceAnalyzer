<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RegistrationControllerTest extends WebTestCase
{
    public function testRegisterPageRenders(): void
    {
        $client = static::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Create Account');
        $this->assertSelectorExists('form[name="registration_form"]');
    }

    public function testRegistrationAndEmailVerificationFlow(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $testEmail = 'register_' . bin2hex(random_bytes(4)) . '@example.com';
        $testPassword = 'SecurePassword123!';

        // 1. Submit Registration Form
        $crawler = $client->request(Request::METHOD_GET, '/register');
        $buttonCrawlerNode = $crawler->selectButton('Register');
        $form = $buttonCrawlerNode->form([
            'registration_form[email]' => $testEmail,
            'registration_form[plainPassword][first]' => $testPassword,
            'registration_form[plainPassword][second]' => $testPassword,
            'registration_form[preferredCurrency]' => 'EUR',
        ]);

        $client->submit($form);
        $this->assertResponseRedirects('/login');

        // 2. Verify user was created in database and is UNVERIFIED
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        $this->assertNotNull($user);
        $this->assertFalse($user->isEmailVerified());
        $this->assertSame('EUR', $user->getPreferredCurrency());
        $this->assertNotEmpty($user->getEmailVerificationToken());
        $this->assertNotNull($user->getEmailVerificationTokenExpiresAt());
        $this->assertFalse($user->isEmailVerificationTokenExpired());

        // 3. Attempt to login while unverified -> Must be blocked by UserChecker
        $client->followRedirect(); // Now on /login
        $loginCrawler = $client->getCrawler();
        $loginForm = $loginCrawler->selectButton('Sign In')->form([
            '_username' => $testEmail,
            '_password' => $testPassword,
        ]);
        $client->submit($loginForm);
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Please verify your email address before signing in');

        // 4. Verify with invalid token -> Fails
        $client->request(Request::METHOD_GET, '/verify-email/nonexistent_token_123');
        $this->assertResponseRedirects('/login');
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'invalid or has already been used');

        // 5. Verify with valid token -> Succeeds
        $verificationToken = $user->getEmailVerificationToken();
        $client->request(Request::METHOD_GET, '/verify-email/' . $verificationToken);
        $this->assertResponseRedirects('/login');

        // Verify entity state updated in database
        $em->clear();
        $verifiedUser = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        $this->assertTrue($verifiedUser->isEmailVerified());
        $this->assertNull($verifiedUser->getEmailVerificationToken());

        // 6. Login now succeeds!
        $client->followRedirect(); // On /login
        $loginCrawler = $client->getCrawler();
        $loginForm = $loginCrawler->selectButton('Sign In')->form([
            '_username' => $testEmail,
            '_password' => $testPassword,
        ]);
        $client->submit($loginForm);
        $this->assertResponseRedirects('/');

        // 7. Cleanup
        $em->remove($verifiedUser);
        $em->flush();
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $existingEmail = 'duplicate_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = new User($existingEmail, 'password', true);
        $em->persist($user);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/register');
        $form = $crawler->selectButton('Register')->form([
            'registration_form[email]' => $existingEmail,
            'registration_form[plainPassword][first]' => 'AnotherPassword123!',
            'registration_form[plainPassword][second]' => 'AnotherPassword123!',
            'registration_form[preferredCurrency]' => 'CHF',
        ]);

        $client->submit($form);
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertSelectorTextContains('.invalid-feedback, .form-text, form', 'An account with this email address already exists');

        $userToRemove = $em->getRepository(User::class)->findOneBy(['email' => $existingEmail]);
        if ($userToRemove instanceof User) {
            $em->remove($userToRemove);
            $em->flush();
        }
    }

    public function testResendVerificationEmail(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $unverifiedEmail = 'resend_' . bin2hex(random_bytes(4)) . '@example.com';
        $user = new User($unverifiedEmail, 'password', false);
        $oldToken = $user->generateEmailVerificationToken(24);
        $em->persist($user);
        $em->flush();

        $crawler = $client->request(Request::METHOD_GET, '/resend-verification');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Send Verification Link')->form([
            'email' => $unverifiedEmail,
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/login');
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert-info', 'new confirmation link has been sent');

        $em->clear();
        $updatedUser = $em->getRepository(User::class)->findOneBy(['email' => $unverifiedEmail]);
        $this->assertNotNull($updatedUser);
        $this->assertNotSame($oldToken, $updatedUser->getEmailVerificationToken());

        $em->remove($updatedUser);
        $em->flush();
    }
}
