<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ListControllerTest extends WebTestCase
{
    public function testListAllRedirectsToFilter(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/all');

        $this->assertResponseRedirects('/en/filter');
    }

    public function testFilterPageReturnsSuccessfulResponse(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/filter');

        $this->assertResponseIsSuccessful();
    }

    public function testFilterWithMinYearQueryParameter(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/filter?minYear=2020');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Released in 2020 or newer', $content);
    }

    public function testBringYourOwnPriceOverridesDisplayForSubmittingUser(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $userA = new \App\Entity\User('byop_user_a_' . $suffix . '@example.com', 'password');
        $userB = new \App\Entity\User('byop_user_b_' . $suffix . '@example.com', 'password');
        $em->persist($userA);
        $em->persist($userB);

        $set = new \App\Entity\Set();
        $set->setNo('BYOP_' . $suffix . '-1');
        $set->setName('BYOP Test Set ' . $suffix);
        $set->setPrice(100.00);
        $set->setPriceCurrency('CHF');
        $em->persist($set);

        $piece = new \App\Entity\Piece();
        $piece->setNo('P_' . $suffix);
        $piece->setName('Test Piece');
        $piece->setCategory(1);
        $piece->setType('brick');
        $piece->setColor(1);
        $piece->setCount(100);
        $piece->setSet($set);
        $set->addPiece($piece);
        $em->persist($piece);

        $unverifiedPrice = new \App\Entity\SetPrice(
            $set,
            2000,
            'CHF',
            \App\Enum\Retailer::GALAXUS,
            \App\Enum\PriceSourceProvider::USER_BOOKMARKLET
        );
        $unverifiedPrice->setStatus(\App\Enum\PriceVerificationStatus::UNVERIFIED);
        $unverifiedPrice->setSubmittedBy($userA);
        $set->addPrice($unverifiedPrice);
        $em->persist($unverifiedPrice);

        $em->flush();

        try {
            // 1. Anonymous user: sees canonical 100.00, does not see "Your price"
            $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/filter');
            $this->assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('BYOP Test Set ' . $suffix, $content);
            $this->assertStringNotContainsString('👤 Your price', $content);
            $this->assertStringContainsString('100.00', $content);

            // 2. User A (the submitter): sees their unverified 20.00 and "Your price" badge
            $client->loginUser($userA);
            $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/filter');
            $this->assertResponseIsSuccessful();
            $contentUserA = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('BYOP Test Set ' . $suffix, $contentUserA);
            $this->assertStringContainsString('👤 Your price', $contentUserA);
            $this->assertStringContainsString('20.00', $contentUserA);

            // 3. User B: sees canonical 100.00, does not see "Your price"
            $client->loginUser($userB);
            $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/en/filter');
            $this->assertResponseIsSuccessful();
            $contentUserB = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('BYOP Test Set ' . $suffix, $contentUserB);
            $this->assertStringNotContainsString('👤 Your price', $contentUserB);
            $this->assertStringContainsString('100.00', $contentUserB);
        } finally {
            $cleanupEm = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
            $s = $cleanupEm->getRepository(\App\Entity\Set::class)->findOneBy(['no' => 'BYOP_' . $suffix . '-1']);
            if ($s !== null) {
                foreach ($s->getPieces() as $p) {
                    $cleanupEm->remove($p);
                }
                foreach ($s->getPrices() as $pr) {
                    $cleanupEm->remove($pr);
                }
                $cleanupEm->remove($s);
            }
            $uA = $cleanupEm->getRepository(\App\Entity\User::class)->findOneBy(['email' => $userA->getEmail()]);
            if ($uA !== null) {
                $cleanupEm->remove($uA);
            }
            $uB = $cleanupEm->getRepository(\App\Entity\User::class)->findOneBy(['email' => $userB->getEmail()]);
            if ($uB !== null) {
                $cleanupEm->remove($uB);
            }
            $cleanupEm->flush();
        }
    }

    public function testBringYourOwnPriceRankingInSetRepository(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $setRepo = $client->getContainer()->get(\App\Repository\SetRepository::class);

        $suffix = bin2hex(random_bytes(4));
        $userA = new \App\Entity\User('rank_user_a_' . $suffix . '@example.com', 'password');
        $userB = new \App\Entity\User('rank_user_b_' . $suffix . '@example.com', 'password');
        $em->persist($userA);
        $em->persist($userB);

        // Set 1: canonical 100.00 CHF / 100 pcs = 1.000 / pc
        $set1 = new \App\Entity\Set();
        $set1->setNo('RANK1_' . $suffix . '-1');
        $set1->setName('Rank Set 1');
        $set1->setPrice(100.00);
        $set1->setPriceCurrency('CHF');
        $em->persist($set1);

        $p1 = new \App\Entity\Piece();
        $p1->setNo('RP1_' . $suffix);
        $p1->setName('P1');
        $p1->setCategory(1);
        $p1->setType('brick');
        $p1->setColor(1);
        $p1->setCount(100);
        $p1->setSet($set1);
        $set1->addPiece($p1);
        $em->persist($p1);

        // Set 2: canonical 50.00 CHF / 100 pcs = 0.500 / pc
        $set2 = new \App\Entity\Set();
        $set2->setNo('RANK2_' . $suffix . '-1');
        $set2->setName('Rank Set 2');
        $set2->setPrice(50.00);
        $set2->setPriceCurrency('CHF');
        $em->persist($set2);

        $p2 = new \App\Entity\Piece();
        $p2->setNo('RP2_' . $suffix);
        $p2->setName('P2');
        $p2->setCategory(1);
        $p2->setType('brick');
        $p2->setColor(1);
        $p2->setCount(100);
        $p2->setSet($set2);
        $set2->addPiece($p2);
        $em->persist($p2);

        // User A submits unverified price on Set 1 of 10.00 CHF (1000 cents -> 0.100 / pc)
        $userPrice = new \App\Entity\SetPrice(
            $set1,
            1000,
            'CHF',
            \App\Enum\Retailer::GALAXUS,
            \App\Enum\PriceSourceProvider::USER_BOOKMARKLET
        );
        $userPrice->setStatus(\App\Enum\PriceVerificationStatus::UNVERIFIED);
        $userPrice->setSubmittedBy($userA);
        $set1->addPrice($userPrice);
        $em->persist($userPrice);

        $em->flush();

        try {
            // For Anonymous (or null user): Set 2 (0.50/pc) ranks before Set 1 (1.00/pc)
            $anonResults = $setRepo->findMostValuableBy([], 100, null);
            $anonNos = array_map(fn(\App\Entity\Set $s): ?string => $s->getNo(), $anonResults);
            $idxSet1Anon = array_search('RANK1_' . $suffix . '-1', $anonNos, true);
            $idxSet2Anon = array_search('RANK2_' . $suffix . '-1', $anonNos, true);
            $this->assertNotFalse($idxSet1Anon);
            $this->assertNotFalse($idxSet2Anon);
            $this->assertGreaterThan($idxSet2Anon, $idxSet1Anon, 'Set 2 must rank ahead of Set 1 for anonymous users');

            // For User A: Set 1 (effective 0.10/pc) ranks before Set 2 (0.50/pc)
            $userAResults = $setRepo->findMostValuableBy([], 100, $userA);
            $userANos = array_map(fn(\App\Entity\Set $s): ?string => $s->getNo(), $userAResults);
            $idxSet1UserA = array_search('RANK1_' . $suffix . '-1', $userANos, true);
            $idxSet2UserA = array_search('RANK2_' . $suffix . '-1', $userANos, true);
            $this->assertNotFalse($idxSet1UserA);
            $this->assertNotFalse($idxSet2UserA);
            $this->assertLessThan($idxSet2UserA, $idxSet1UserA, 'Set 1 must rank ahead of Set 2 for User A due to personal price');

            // For User B: Set 2 (0.50/pc) still ranks before Set 1 (1.00/pc)
            $userBResults = $setRepo->findMostValuableBy([], 100, $userB);
            $userBNos = array_map(fn(\App\Entity\Set $s): ?string => $s->getNo(), $userBResults);
            $idxSet1UserB = array_search('RANK1_' . $suffix . '-1', $userBNos, true);
            $idxSet2UserB = array_search('RANK2_' . $suffix . '-1', $userBNos, true);
            $this->assertNotFalse($idxSet1UserB);
            $this->assertNotFalse($idxSet2UserB);
            $this->assertGreaterThan($idxSet2UserB, $idxSet1UserB, 'Set 2 must rank ahead of Set 1 for User B');
        } finally {
            $cleanupEm = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
            foreach (['RANK1_' . $suffix . '-1', 'RANK2_' . $suffix . '-1'] as $no) {
                $s = $cleanupEm->getRepository(\App\Entity\Set::class)->findOneBy(['no' => $no]);
                if ($s !== null) {
                    foreach ($s->getPieces() as $p) {
                        $cleanupEm->remove($p);
                    }
                    foreach ($s->getPrices() as $pr) {
                        $cleanupEm->remove($pr);
                    }
                    $cleanupEm->remove($s);
                }
            }
            $uA = $cleanupEm->getRepository(\App\Entity\User::class)->findOneBy(['email' => $userA->getEmail()]);
            if ($uA !== null) {
                $cleanupEm->remove($uA);
            }
            $uB = $cleanupEm->getRepository(\App\Entity\User::class)->findOneBy(['email' => $userB->getEmail()]);
            if ($uB !== null) {
                $cleanupEm->remove($uB);
            }
        }
    }

    public function testListItemRedirectsToItemPrices(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $set = new \App\Entity\Set();
        $set->setNo('ITEM_' . $suffix . '-1');
        $set->setName('Item Test Set ' . $suffix);
        $set->setPrice(59.99);
        $set->setPriceCurrency('CHF');
        $em->persist($set);
        $em->flush();

        try {
            $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, sprintf('/en/item/%d', $set->getId()));
            $this->assertResponseRedirects(sprintf('/en/set/%d', $set->getId()));
        } finally {
            $cleanupEm = $client->getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
            $s = $cleanupEm->getRepository(\App\Entity\Set::class)->findOneBy(['no' => 'ITEM_' . $suffix . '-1']);
            if ($s !== null) {
                $cleanupEm->remove($s);
                $cleanupEm->flush();
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
