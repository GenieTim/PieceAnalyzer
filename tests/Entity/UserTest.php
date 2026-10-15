<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testUserCreationAndDefaultRoles(): void
    {
        $user = new User('user@example.com', 'hashed_pass');

        $this->assertSame('user@example.com', $user->getEmail());
        $this->assertSame('user@example.com', $user->getUserIdentifier());
        $this->assertSame('hashed_pass', $user->getPassword());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertNotEmpty($user->getApiToken());
        $this->assertSame(64, strlen($user->getApiToken()));
        $this->assertFalse($user->isApiTokenExpired());
        $this->assertTrue($user->isEmailVerified());
        $unverified = new User('unverified@example.com', '', false);
        $this->assertFalse($unverified->isEmailVerified());

        $this->assertFalse($user->isVerifiedContributor());
        $this->assertFalse($user->isTrusted());
        $this->assertSame('CHF', $user->getPreferredCurrency());
        $this->assertSame([], $user->getPreferredRetailers());
    }

    public function testApiTokenExpirationAndRegeneration(): void
    {
        $user = new User('token_tester@example.com');
        $oldToken = $user->getApiToken();

        // Not expired when null
        $this->assertFalse($user->isApiTokenExpired());

        // Not expired when future
        $user->setApiTokenExpiresAt((new \DateTimeImmutable())->modify('+30 days'));
        $this->assertFalse($user->isApiTokenExpired());

        // Expired when in the past
        $user->setApiTokenExpiresAt((new \DateTimeImmutable())->modify('-1 hour'));
        $this->assertTrue($user->isApiTokenExpired());

        // Regenerate token resets token and expiration
        $user->regenerateApiToken();
        $this->assertNotSame($oldToken, $user->getApiToken());
        $this->assertSame(64, strlen($user->getApiToken()));
        $this->assertNull($user->getApiTokenExpiresAt());
        $this->assertFalse($user->isApiTokenExpired());
    }

    public function testRolesDeduplicationAndAdminPromotion(): void
    {
        $user = new User('admin@example.com');
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER', 'ROLE_ADMIN']);

        $roles = $user->getRoles();
        $this->assertCount(2, $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles);

        // An admin is considered trusted
        $this->assertTrue($user->isTrusted());
    }

    public function testVerifiedContributorIsTrusted(): void
    {
        $user = new User('contributor@example.com');
        $this->assertFalse($user->isTrusted());

        $user->setIsVerifiedContributor(true);
        $this->assertTrue($user->isVerifiedContributor());
        $this->assertTrue($user->isTrusted());
    }

    public function testCurrencyPreferenceWhitelist(): void
    {
        $user = new User('curr@example.com');

        $user->setPreferredCurrency('EUR');
        $this->assertSame('EUR', $user->getPreferredCurrency());

        $user->setPreferredCurrency('usd');
        $this->assertSame('USD', $user->getPreferredCurrency());

        $user->setPreferredCurrency('GBP');
        $this->assertSame('GBP', $user->getPreferredCurrency());

        // Invalid currency must be ignored
        $user->setPreferredCurrency('XYZ');
        $this->assertSame('GBP', $user->getPreferredCurrency());
    }

    public function testRetailerPreferencesDeduplication(): void
    {
        $user = new User('retailer@example.com');

        $user->setPreferredRetailers(['galaxus', 'brack', 'galaxus', 'amazon_de']);
        $this->assertSame(['galaxus', 'brack', 'amazon_de'], $user->getPreferredRetailers());
    }

    public function testEmailVerificationToken(): void
    {
        $user = new User('verify@example.com');

        // When no token/expiration exists, it is considered expired (cannot be used)
        $this->assertTrue($user->isEmailVerificationTokenExpired());
        $this->assertNull($user->getEmailVerificationToken());

        $token = $user->generateEmailVerificationToken();
        $this->assertSame($token, $user->getEmailVerificationToken());
        $this->assertSame(64, strlen($token));
        $this->assertFalse($user->isEmailVerificationTokenExpired());


        // Expired token
        $user->setEmailVerificationTokenExpiresAt((new \DateTimeImmutable())->modify('-10 minutes'));
        $this->assertTrue($user->isEmailVerificationTokenExpired());

        // Clear token upon verification
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationTokenExpiresAt(null);
        $user->setIsEmailVerified(true);
        $this->assertNull($user->getEmailVerificationToken());
        $this->assertNull($user->getEmailVerificationTokenExpiresAt());
        $this->assertTrue($user->isEmailVerified());
    }
}
