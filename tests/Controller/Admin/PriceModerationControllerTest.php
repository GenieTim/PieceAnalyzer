<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\PriceSourceProvider;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PriceModerationControllerTest extends WebTestCase
{
    private function cleanDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\SetPrice')->execute();
        $em->createQuery('DELETE FROM App\Entity\Piece')->execute();
        $em->createQuery('DELETE FROM App\Entity\Set')->execute();
        $em->createQuery('DELETE FROM App\Entity\Item')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testNonAdminIsForbiddenOrRedirected(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/admin/prices/moderation');
        // Unauthenticated user redirected to login
        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserAccessIsDenied(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $user = new User('regular@example.com', 'password');
        $user->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/admin/prices/moderation');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanViewModerationQueue(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $admin = new User('admin@example.com', 'password');
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);

        $set = new Set();
        $set->setNo('10294-1');
        $set->setName('Titanic');
        $em->persist($set);

        $price = new SetPrice(
            set: $set,
            amount: 67990,
            currency: 'CHF',
            retailer: Retailer::GALAXUS,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );
        $price->setStatus(PriceVerificationStatus::UNVERIFIED);
        $em->persist($price);
        $em->flush();

        $client->loginUser($admin);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/admin/prices/moderation');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Price Moderation Queue');
        $this->assertSelectorTextContains('.table', 'Titanic');
        $this->assertSelectorTextContains('.table', 'Galaxus');
    }

    public function testAdminApproveWithAutoVerificationOfMatchingReports(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $admin = new User('admin@example.com', 'password');
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);

        $user1 = new User('user1@example.com', 'password');
        $user2 = new User('user2@example.com', 'password');
        $em->persist($user1);
        $em->persist($user2);

        $set = new Set();
        $set->setNo('75192-1');
        $set->setName('Millennium Falcon');
        $em->persist($set);

        // Two identical reports for same set and retailer
        $price1 = new SetPrice(set: $set, amount: 69990, currency: 'CHF', retailer: Retailer::GALAXUS);
        $price1->setStatus(PriceVerificationStatus::UNVERIFIED);
        $price1->setSubmittedBy($user1);
        $em->persist($price1);

        $price2 = new SetPrice(set: $set, amount: 69990, currency: 'CHF', retailer: Retailer::GALAXUS);
        $price2->setStatus(PriceVerificationStatus::UNVERIFIED);
        $price2->setSubmittedBy($user2);
        $em->persist($price2);

        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/admin/prices/moderation');
        $token = $crawler->filter('form[action$="' . $price1->getId() . '/approve"] input[name="_token"]')->attr('value');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, sprintf('/admin/prices/moderation/%d/approve', $price1->getId()), [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/admin/prices/moderation');
        $client->followRedirect();

        $em->clear();
        $p1 = $em->find(SetPrice::class, $price1->getId());
        $p2 = $em->find(SetPrice::class, $price2->getId());
        $s = $em->find(Set::class, $set->getId());

        $this->assertSame(PriceVerificationStatus::VERIFIED, $p1?->getStatus());
        $this->assertSame(PriceVerificationStatus::VERIFIED, $p2?->getStatus());
        $this->assertSame(699.9, $s?->getPrice());
    }

    public function testAdminReject(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $admin = new User('admin@example.com', 'password');
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);

        $set = new Set();
        $set->setNo('10300-1');
        $set->setName('Time Machine');
        $em->persist($set);

        $price = new SetPrice(set: $set, amount: 1500, currency: 'CHF', retailer: Retailer::OTHER);
        $price->setStatus(PriceVerificationStatus::UNVERIFIED);
        $em->persist($price);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/admin/prices/moderation');
        $token = $crawler->filter('form[action$="' . $price->getId() . '/reject"] input[name="_token"]')->attr('value');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, sprintf('/admin/prices/moderation/%d/reject', $price->getId()), [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/admin/prices/moderation');

        $em->clear();
        $reloaded = $em->find(SetPrice::class, $price->getId());
        $this->assertSame(PriceVerificationStatus::REJECTED, $reloaded?->getStatus());
    }
}
