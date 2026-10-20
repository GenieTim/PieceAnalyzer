<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\Retailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class UserPreferencesService
{
    public const SESSION_CURRENCY = 'pref_currency';
    public const SESSION_RETAILERS = 'pref_retailers';
    public const SESSION_EXCLUDE_USED_PRICES = 'pref_exclude_used_prices';
    public const DEFAULT_CURRENCY = 'CHF';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly EntityManagerInterface $em
    ) {
    }

    public function getCurrency(): string
    {
        $user = $this->security->getUser();
        if ($user instanceof User && $user->getPreferredCurrency() !== '') {
            return $user->getPreferredCurrency();
        }

        $session = $this->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
            $currency = (string) $session->get(self::SESSION_CURRENCY, self::DEFAULT_CURRENCY);
            return in_array($currency, ['CHF', 'EUR', 'USD', 'GBP'], true) ? $currency : self::DEFAULT_CURRENCY;
        }

        return self::DEFAULT_CURRENCY;
    }

    public function setCurrency(string $currency): void
    {
        $curr = strtoupper(trim($currency));
        if (!in_array($curr, ['CHF', 'EUR', 'USD', 'GBP'], true)) {
            return;
        }

        $session = $this->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
            $session->set(self::SESSION_CURRENCY, $curr);
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $user->setPreferredCurrency($curr);
            $this->em->persist($user);
            $this->em->flush();
        }
    }

    /**
     * @return string[]
     */
    public function getPreferredRetailers(): array
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            return $user->getPreferredRetailers();
        }

        $session = $this->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
            $retailers = $session->get(self::SESSION_RETAILERS, []);
            return is_array($retailers) ? $retailers : [];
        }

        return [];
    }

    /**
     * @param string[] $retailers
     */
    public function setPreferredRetailers(array $retailers): void
    {
        $session = $this->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
            $session->set(self::SESSION_RETAILERS, $retailers);
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $user->setPreferredRetailers($retailers);
            $this->em->persist($user);
            $this->em->flush();
        }
    }

    public function shouldExcludeUsedPrices(): bool
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            return $user->shouldExcludeUsedPrices();
        }

        $session = $this->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
            return (bool) $session->get(self::SESSION_EXCLUDE_USED_PRICES, false);
        }

        return false;
    }

    public function setExcludeUsedPrices(bool $exclude): void
    {
        $session = $this->getSession();
        if ($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
            $session->set(self::SESSION_EXCLUDE_USED_PRICES, $exclude);
        }

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $user->setExcludeUsedPrices($exclude);
            $this->em->persist($user);
            $this->em->flush();
        }
    }

    private function getSession(): ?SessionInterface
    {
        try {
            return $this->requestStack->getSession();
        } catch (\Throwable) {
            return null;
        }
    }
}
