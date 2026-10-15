<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

class UserCheckerTest extends TestCase
{
    public function testCheckPreAuthThrowsExceptionWhenEmailNotVerified(): void
    {
        $checker = new UserChecker();
        $user = new User('unverified@example.com', 'pass', false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Please verify your email address before signing in.');

        $checker->checkPreAuth($user);
    }

    public function testCheckPreAuthSucceedsWhenEmailVerified(): void
    {
        $checker = new UserChecker();
        $user = new User('verified@example.com', 'pass', true);

        // Should not throw any exception
        $checker->checkPreAuth($user);
        $this->assertTrue($user->isEmailVerified());
    }

    public function testCheckPreAuthIgnoresNonAppUser(): void
    {
        $checker = new UserChecker();
        $otherUser = $this->createMock(UserInterface::class);

        // Should not throw any exception
        $checker->checkPreAuth($otherUser);
        $this->assertTrue(true);
    }

    public function testCheckPostAuthDoesNothing(): void
    {
        $checker = new UserChecker();
        $user = new User('verified@example.com');

        $checker->checkPostAuth($user);
        $this->assertTrue(true);
    }
}
