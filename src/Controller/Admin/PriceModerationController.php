<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Enum\PriceVerificationStatus;
use App\Enum\Retailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class PriceModerationController extends AbstractController
{
    #[Route(path: '/admin/prices/moderation', name: 'admin_price_moderation', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $statusFilter = strtolower(trim((string) $request->query->get('status', 'unverified')));
        $retailerFilter = trim((string) $request->query->get('retailer', ''));

        $priceRepo = $em->getRepository(SetPrice::class);

        // Counts for status badges
        /** @var list<array{status: PriceVerificationStatus|string, cnt: int|string}> $rawCounts */
        $rawCounts = $priceRepo->createQueryBuilder('sp')
            ->select('sp.status, COUNT(sp.id) as cnt')
            ->groupBy('sp.status')
            ->getQuery()
            ->getArrayResult();

        $countsByStatus = [];
        foreach ($rawCounts as $row) {
            $statusKey = $row['status'] instanceof PriceVerificationStatus
                ? $row['status']->value
                : (string) $row['status'];
            $countsByStatus[$statusKey] = (int) $row['cnt'];
        }

        $unverifiedCount = $countsByStatus[PriceVerificationStatus::UNVERIFIED->value] ?? 0;
        $verifiedCount = $countsByStatus[PriceVerificationStatus::VERIFIED->value] ?? 0;
        $rejectedCount = $countsByStatus[PriceVerificationStatus::REJECTED->value] ?? 0;


        $qb = $priceRepo->createQueryBuilder('sp')
            ->leftJoin('sp.set', 's')
            ->leftJoin('sp.submittedBy', 'u')
            ->addSelect('s', 'u')
            ->orderBy('sp.recordedAt', 'DESC')
            ->setMaxResults(100);

        if ($statusFilter !== 'all') {
            $statusEnum = match ($statusFilter) {
                'verified' => PriceVerificationStatus::VERIFIED,
                'rejected' => PriceVerificationStatus::REJECTED,
                default => PriceVerificationStatus::UNVERIFIED,
            };
            $qb->andWhere('sp.status = :status')
                ->setParameter('status', $statusEnum);
        }

        if ($retailerFilter !== '') {
            $retailerEnum = Retailer::tryFrom($retailerFilter);
            if ($retailerEnum instanceof Retailer) {
                $qb->andWhere('sp.retailer = :retailer')
                    ->setParameter('retailer', $retailerEnum);
            }
        }

        /** @var list<SetPrice> $prices */
        $prices = $qb->getQuery()->getResult();

        return $this->render('admin/price_moderation.html.twig', [
            'prices' => $prices,
            'currentStatus' => $statusFilter,
            'currentRetailer' => $retailerFilter,
            'unverifiedCount' => $unverifiedCount,
            'verifiedCount' => $verifiedCount,
            'rejectedCount' => $rejectedCount,
            'retailers' => Retailer::cases(),
        ]);
    }

    #[Route(
        path: '/admin/prices/moderation/{id}/approve',
        name: 'admin_price_approve',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function approve(SetPrice $setPrice, Request $request, EntityManagerInterface $em): RedirectResponse
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('approve_price_' . $setPrice->getId(), $token)) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('admin_price_moderation');
        }

        $setPrice->setStatus(PriceVerificationStatus::VERIFIED);
        $set = $setPrice->getSet();
        if ($set instanceof Set) {
            $set->updateCachedBestPrice();
            $em->persist($set);
        }
        $em->persist($setPrice);

        // Auto-verify matching reports for identical set, retailer, currency, and amount
        $matching = $em->getRepository(SetPrice::class)->findBy([
            'set' => $set,
            'retailer' => $setPrice->getRetailer(),
            'amount' => $setPrice->getAmount(),
            'currency' => $setPrice->getCurrency(),
            'status' => PriceVerificationStatus::UNVERIFIED,
        ]);

        $autoVerifiedCount = 0;
        foreach ($matching as $match) {
            if ($match->getId() !== $setPrice->getId()) {
                $match->setStatus(PriceVerificationStatus::VERIFIED);
                $em->persist($match);
                $autoVerifiedCount++;
            }
        }

        $em->flush();

        $msg = sprintf(
            '✓ Approved %s price for Set #%s (%s: %s).',
            $setPrice->getFormattedPrice(),
            $set?->getNo() ?? '',
            $setPrice->getRetailer()->label(),
            $setPrice->getFormattedPrice()
        );
        if ($autoVerifiedCount > 0) {
            $msg .= sprintf(' Also auto-verified %d matching unverified report(s).', $autoVerifiedCount);
        }
        $this->addFlash('success', $msg);

        return $this->redirectToRoute('admin_price_moderation');
    }

    #[Route(
        path: '/admin/prices/moderation/{id}/reject',
        name: 'admin_price_reject',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function reject(SetPrice $setPrice, Request $request, EntityManagerInterface $em): RedirectResponse
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reject_price_' . $setPrice->getId(), $token)) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('admin_price_moderation');
        }

        $setPrice->setStatus(PriceVerificationStatus::REJECTED);
        $set = $setPrice->getSet();
        if ($set instanceof Set) {
            $set->updateCachedBestPrice();
            $em->persist($set);
        }
        $em->persist($setPrice);
        $em->flush();

        $this->addFlash('info', sprintf(
            'Price report of %s for Set #%s rejected.',
            $setPrice->getFormattedPrice(),
            $set?->getNo() ?? ''
        ));

        return $this->redirectToRoute('admin_price_moderation');
    }

    #[Route(
        path: '/admin/prices/moderation/auto-verify-matching',
        name: 'admin_price_auto_verify_matching',
        methods: ['POST']
    )]
    public function autoVerifyMatching(Request $request, EntityManagerInterface $em): RedirectResponse
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('auto_verify_matching', $token)) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('admin_price_moderation');
        }

        /** @var list<SetPrice> $unverified */
        $unverified = $em->getRepository(SetPrice::class)->findBy([
            'status' => PriceVerificationStatus::UNVERIFIED,
        ]);

        /** @var array<string, list<SetPrice>> $grouped */
        $grouped = [];
        foreach ($unverified as $p) {
            $setId = $p->getSet()?->getId();
            if ($setId === null) {
                continue;
            }
            $key = sprintf('%d_%s_%s_%d', $setId, $p->getRetailer()->value, $p->getCurrency(), $p->getAmount());
            $grouped[$key][] = $p;
        }

        $verifiedCount = 0;
        $affectedSets = [];

        foreach ($grouped as $group) {
            // Check if reports are from 2 or more distinct verified contributors
            $submitterIds = [];
            foreach ($group as $p) {
                $user = $p->getSubmittedBy();
                if ($user instanceof User && $user->isVerifiedContributor() && $user->getId() !== null) {
                    $submitterIds[$user->getId()] = true;
                }
            }

            if (count($submitterIds) >= 2) {
                foreach ($group as $p) {
                    $p->setStatus(PriceVerificationStatus::VERIFIED);
                    $em->persist($p);
                    $verifiedCount++;
                    $set = $p->getSet();
                    if ($set instanceof Set && $set->getId() !== null) {
                        $affectedSets[$set->getId()] = $set;
                    }
                }
            }
        }

        foreach ($affectedSets as $set) {
            $set->updateCachedBestPrice();
            $em->persist($set);
        }

        $em->flush();

        if ($verifiedCount > 0) {
            $this->addFlash('success', sprintf(
                'Auto-verification rule applied: %d reports across %d sets auto-verified '
                . 'based on consensus between multiple contributors.',
                $verifiedCount,
                count($affectedSets)
            ));
        } else {
            $this->addFlash('info', 'No unverified reports currently meet the multi-contributor consensus rule.');
        }

        return $this->redirectToRoute('admin_price_moderation');
    }
}
