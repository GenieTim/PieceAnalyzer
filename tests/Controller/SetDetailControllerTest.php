<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Piece;
use App\Entity\Set;
use App\Entity\SetPrice;
use App\Enum\PriceSourceProvider;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SetDetailControllerTest extends WebTestCase
{
    private function cleanDatabase(EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM App\Entity\SetPrice')->execute();
        $em->createQuery('DELETE FROM App\Entity\Piece')->execute();
        $em->createQuery('DELETE FROM App\Entity\Set')->execute();
        $em->createQuery('DELETE FROM App\Entity\Item')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testSetDetailPageSuccessfulWithPiecesAndPrices(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $set = new Set();
        $set->setNo('2263-1');
        $set->setName('Turbo Shredder');
        $set->setPrice(29.99);
        $set->setPriceCurrency('USD');
        $em->persist($set);

        // Add Pieces
        $piece1 = new Piece();
        $piece1->setNo('3001');
        $piece1->setName('Brick 2 x 4');
        $piece1->setColor(15); // White
        $piece1->setCategory(1);
        $piece1->setCount(4);
        $piece1->setSet($set);
        $set->addPiece($piece1);
        $em->persist($piece1);

        $piece2 = new Piece();
        $piece2->setNo('3020');
        $piece2->setName('Plate 2 x 4');
        $piece2->setColor(0); // Black
        $piece2->setCategory(1);
        $piece2->setCount(8);
        $piece2->setSet($set);
        $set->addPiece($piece2);
        $em->persist($piece2);

        // Add Prices with Condition and URLs
        $pMsrp = new SetPrice(
            set: $set,
            amount: 2999,
            currency: 'USD',
            retailer: Retailer::LEGO_STORE,
            provider: PriceSourceProvider::BRICKSET,
            itemCondition: 'msrp'
        );
        $pMsrp->setStatus(PriceVerificationStatus::VERIFIED);
        $pMsrp->setUrl('https://brickset.com/sets/2263-1');
        $set->addPrice($pMsrp);
        $em->persist($pMsrp);

        $pBricklink = new SetPrice(
            set: $set,
            amount: 20800,
            currency: 'CHF',
            retailer: Retailer::BRICKLINK,
            provider: PriceSourceProvider::BRICKSET,
            itemCondition: 'new'
        );
        $pBricklink->setStatus(PriceVerificationStatus::VERIFIED);
        $pBricklink->setUrl('https://www.bricklink.com/v2/catalog/catalogitem.page?S=2263-1');
        $set->addPrice($pBricklink);
        $em->persist($pBricklink);

        $em->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, sprintf('/en/set/%d', $set->getId()));
        $this->assertResponseIsSuccessful();

        // Check Set Details
        $this->assertSelectorTextContains('h1', 'Turbo Shredder');
        $this->assertSelectorTextContains('.badge', '2263-1');

        // Check Prices Section
        $this->assertSelectorTextContains('#prices', 'BrickLink');
        $this->assertSelectorTextContains('#prices', 'LEGO Store');

        // Check Piece Inventory Section
        $this->assertSelectorTextContains('#pieces', 'Brick 2 x 4');
        $this->assertSelectorTextContains('#pieces', 'Plate 2 x 4');
        $this->assertSelectorTextContains('#pieces', '8x');
        $this->assertSelectorTextContains('#pieces', '4x');

        // Check filter links
        $crawler = $client->getCrawler();
        $filterLink = $crawler->filter('a[href*="part=3001"]')->first()->attr('href');
        $this->assertStringContainsString('part=3001', $filterLink);
    }

    public function testSetDetailByNoRedirects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cleanDatabase($em);

        $set = new Set();
        $set->setNo('2263-1');
        $set->setName('Turbo Shredder');
        $em->persist($set);
        $em->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/set/no/2263-1');
        $this->assertResponseRedirects(sprintf('/en/set/%d', $set->getId()));
    }

    public function testSetDetailNotFound(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/set/99999999');
        $this->assertResponseStatusCodeSame(404);
    }
}
