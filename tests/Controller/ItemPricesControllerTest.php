<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Item;
use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\PriceSourceProvider;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ItemPricesControllerTest extends WebTestCase
{
    private function cleanDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\SetPrice')->execute();
        $em->createQuery('DELETE FROM App\Entity\Piece')->execute();
        $em->createQuery('DELETE FROM App\Entity\Set')->execute();
        $em->createQuery('DELETE FROM App\Entity\Item')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testItemPricesHtmlFullPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $set = new Set();
        $set->setNo('75192-1');
        $set->setName('Millennium Falcon');
        $set->setPrice(849.99);
        $set->setPriceCurrency('CHF');
        $em->persist($set);

        $price1 = new SetPrice(
            set: $set,
            amount: 84999,
            currency: 'CHF',
            retailer: Retailer::LEGO_STORE,
            provider: PriceSourceProvider::DIRECT_CRAWL,
            recordedAt: new \DateTimeImmutable('2026-01-01 10:00:00')
        );
        $price1->setStatus(PriceVerificationStatus::VERIFIED);

        $price2 = new SetPrice(
            set: $set,
            amount: 69990,
            currency: 'CHF',
            retailer: Retailer::GALAXUS,
            provider: PriceSourceProvider::USER_BOOKMARKLET,
            recordedAt: new \DateTimeImmutable('2026-02-01 10:00:00')
        );
        $price2->setStatus(PriceVerificationStatus::VERIFIED);

        $set->addPrice($price1);
        $set->addPrice($price2);
        $em->persist($price1);
        $em->persist($price2);
        $em->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, sprintf('/item/%d/prices', $set->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h5', 'Millennium Falcon');
        $this->assertSelectorTextContains('.price-history-container .badge', '75192-1');
        $this->assertSelectorTextContains('.price-history-container', 'Galaxus');
        $this->assertSelectorTextContains('.price-history-container', 'Lowest Ever');
    }

    public function testItemPricesModalPartial(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $set = new Set();
        $set->setNo('10300-1');
        $set->setName('Back to the Future Time Machine');
        $em->persist($set);

        $price = new SetPrice(
            set: $set,
            amount: 19990,
            currency: 'CHF',
            retailer: Retailer::BRACK,
            provider: PriceSourceProvider::DIRECT_CRAWL
        );
        $set->addPrice($price);
        $em->persist($price);
        $em->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, sprintf('/item/%d/prices?modal=1', $set->getId()), [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Back to the Future Time Machine', $content);
        $this->assertStringContainsString('Brack', $content);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $content);
    }

    public function testItemPricesJson(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $set = new Set();
        $set->setNo('21330-1');
        $set->setName('Home Alone');
        $em->persist($set);

        $price = new SetPrice(
            set: $set,
            amount: 29990,
            currency: 'CHF',
            retailer: Retailer::GALAXUS,
            provider: PriceSourceProvider::USER_BOOKMARKLET
        );
        $price->setStatus(PriceVerificationStatus::VERIFIED);
        $set->addPrice($price);
        $em->persist($price);
        $em->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, sprintf('/item/%d/prices?format=json', $set->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('21330-1', $data['setNo']);
        $this->assertSame('Home Alone', $data['name']);
        $this->assertNotEmpty($data['prices']);
        $this->assertSame('Galaxus.ch', $data['prices'][0]['retailerLabel']);
    }

    public function testItemPricesNotFound(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/item/99999999/prices');
        $this->assertResponseStatusCodeSame(404);
    }
}
