<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_user_api_token', columns: ['api_token'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 180, unique: true)]
    private string $email = '';

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(name: 'api_token', type: Types::STRING, length: 64, unique: true)]
    private string $apiToken = '';

    #[ORM\Column(name: 'api_token_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $apiTokenExpiresAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'preferred_currency', type: Types::STRING, length: 3, options: ['default' => 'CHF'])]
    private string $preferredCurrency = 'CHF';

    /**
     * @var list<string>
     */
    #[ORM\Column(name: 'preferred_retailers', type: Types::JSON, options: ['default' => '[]'])]
    private array $preferredRetailers = [];

    #[ORM\Column(name: 'is_verified_contributor', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isVerifiedContributor = false;

    #[ORM\Column(name: 'email_verification_token', type: Types::STRING, length: 64, nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(name: 'email_verification_token_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerificationTokenExpiresAt = null;

    public function __construct(string $email = '', #[ORM\Column(type: Types::STRING)]
    private string $password = '', #[ORM\Column(name: 'is_email_verified', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isEmailVerified = true)
    {
        $this->email = strtolower(trim($email));
        $this->apiToken = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    #[\Override]
    public function getUserIdentifier(): string
    {
        return $this->email !== '' ? $this->email : 'anonymous';
    }

    #[\Override]
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    #[\Override]
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    public function setApiToken(string $apiToken): static
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function getApiTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->apiTokenExpiresAt;
    }

    public function setApiTokenExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->apiTokenExpiresAt = $expiresAt;

        return $this;
    }

    public function isApiTokenExpired(): bool
    {
        if (!$this->apiTokenExpiresAt instanceof \DateTimeImmutable) {
            return false;
        }

        return $this->apiTokenExpiresAt < new \DateTimeImmutable();
    }

    public function regenerateApiToken(?\DateTimeImmutable $expiresAt = null): static
    {
        $this->apiToken = bin2hex(random_bytes(32));
        $this->apiTokenExpiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPreferredCurrency(): string
    {
        return $this->preferredCurrency;
    }

    public function setPreferredCurrency(string $currency): static
    {
        $curr = strtoupper(trim($currency));
        if (in_array($curr, ['CHF', 'EUR', 'USD', 'GBP'], true)) {
            $this->preferredCurrency = $curr;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getPreferredRetailers(): array
    {
        return $this->preferredRetailers;
    }

    /**
     * @param list<string> $retailers
     */
    public function setPreferredRetailers(array $retailers): static
    {
        $this->preferredRetailers = array_values(array_unique($retailers));

        return $this;
    }

    public function isVerifiedContributor(): bool
    {
        return $this->isVerifiedContributor;
    }

    public function setIsVerifiedContributor(bool $verified): static
    {
        $this->isVerifiedContributor = $verified;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->isEmailVerified;
    }

    public function setIsEmailVerified(bool $verified): static
    {
        $this->isEmailVerified = $verified;

        return $this;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(?string $token): static
    {
        $this->emailVerificationToken = $token;

        return $this;
    }

    public function getEmailVerificationTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerificationTokenExpiresAt;
    }

    public function setEmailVerificationTokenExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->emailVerificationTokenExpiresAt = $expiresAt;

        return $this;
    }

    public function generateEmailVerificationToken(int $ttlHours = 24): string
    {
        $token = bin2hex(random_bytes(32));
        $this->emailVerificationToken = $token;
        $this->emailVerificationTokenExpiresAt = new \DateTimeImmutable(sprintf('+%d hours', $ttlHours));

        return $token;
    }

    public function isEmailVerificationTokenExpired(): bool
    {
        if (!$this->emailVerificationTokenExpiresAt instanceof \DateTimeImmutable) {
            return true;
        }

        return $this->emailVerificationTokenExpiresAt < new \DateTimeImmutable();
    }

    public function isTrusted(): bool
    {
        return $this->isVerifiedContributor || in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    #[\Override]
    public function eraseCredentials(): void
    {
        // If sensitive data is temporarily stored on the entity, clear it here
    }
}
