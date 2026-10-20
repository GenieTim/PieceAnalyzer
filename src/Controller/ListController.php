<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Item;
use App\Entity\Set;
use App\Entity\SetPrice;
use App\Entity\User;
use App\Form\FilterFormType;
use App\Repository\SetRepository;
use App\Service\ExchangeRateService;
use App\Service\UserPreferencesService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ListController extends AbstractController
{
    /**
     * Legacy route
     *
     * @deprecated v3
     */
    #[Route(path: '/all', name: 'list_all')]
    public function listAll(): RedirectResponse
    {
        return $this->redirectToRoute('filter_items');
    }

    /**
     * Filter all sets
     */
    #[Route(path: '/filter', name: 'filter_items')]
    public function filter(
        Request $request,
        PaginatorInterface $paginator,
        SetRepository $setRepo,
        UserPreferencesService $preferencesService
    ): Response {
        $form = $this->createForm(FilterFormType::class);
        $form->handleRequest($request);

        $criteria = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $data = (array) $form->getData();
            foreach ($data as $k => $v) {
                if ($v !== null && $v !== '' && $v !== 0 && $v !== '0') {
                    $criteria[$k] = $v;
                }
            }
        }

        // Support direct / legacy GET parameters from query string
        $queryFields = ['set', 'setId', 'setNo', 'part', 'color', 'category', 'minYear', 'maxYear', 'name'];
        foreach ($queryFields as $qf) {
            if ($request->query->has($qf) && !isset($criteria[$qf])) {
                $val = $request->query->get($qf);
                if ($val !== null && $val !== '' && $val !== '0') {
                    $criteria[$qf] = is_numeric($val) ? (int) $val : $val;
                }
            }
        }
        if ($request->query->has('year') && !isset($criteria['minYear'])) {
            $criteria['minYear'] = $request->query->getInt('year');
        }

        // Exclude used prices preference and query param
        if ($request->query->has('excludeUsed')) {
            $excludeUsed = $request->query->getBoolean('excludeUsed');
            $criteria['excludeUsed'] = $excludeUsed;
        } elseif (isset($criteria['excludeUsed'])) {
            $excludeUsed = (bool) $criteria['excludeUsed'];
        } else {
            $excludeUsed = $preferencesService->shouldExcludeUsedPrices();
            if ($excludeUsed) {
                $criteria['excludeUsed'] = true;
            }
        }

        if (!$form->isSubmitted() && $criteria !== []) {
            $form->submit($criteria, false);
        }

        $user = $this->getUser();
        $currency = $preferencesService->getCurrency();
        $pagination = $paginator->paginate(
            $setRepo->getMostValuableByQuery(
                $criteria,
                0,
                $user instanceof User ? $user : null,
                $currency,
                $excludeUsed
            ),
            $request->query->getInt('page', 1),
            50,
            ['wrap-queries' => true]
        );

        $setRepo->batchLoadPrices($pagination->getItems());

        return $this->render('list/list_all.html.twig', [
            'pagination' => $pagination,
            'form' => $form->createView(),
            'criteria' => $criteria,
            'excludeUsed' => $excludeUsed,
        ]);
    }

    /**
     * Dedicated Set Page with set overview, images, piece inventory list, and price history & trends.
     */
    #[Route(path: '/set/{id}', name: 'set_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function setDetail(
        Item $item,
        Request $request,
        UserPreferencesService $preferencesService,
        ExchangeRateService $exchangeRateService,
        \App\Service\CsvLegoLoaderService $csvLoader
    ): Response {
        if (!$item instanceof Set) {
            throw $this->createNotFoundException('Item is not a LEGO set.');
        }

        $excludeUsed = $request->query->has('excludeUsed')
            ? $request->query->getBoolean('excludeUsed')
            : $preferencesService->shouldExcludeUsedPrices();

        $currency = $preferencesService->getCurrency();
        $prices = $item->getPricesSortedByDate('DESC', $excludeUsed);
        $bestPrice = $item->getBestPrice($currency, $exchangeRateService, $excludeUsed);
        $lowestPrice = $item->getHistoricalLowestPrice($currency, $exchangeRateService, $excludeUsed);
        $highestPrice = $item->getHistoricalHighestPrice($currency, $exchangeRateService, $excludeUsed);
        $savingsDelta = $item->getHistoricalSavingsDelta($currency, $exchangeRateService, $excludeUsed);

        // Pieces inventory sorted by count DESC, then part name
        $pieces = $item->getPieces()->toArray();
        usort($pieces, static function (\App\Entity\Piece $a, \App\Entity\Piece $b): int {
            $cmp = ($b->getCount() ?? 0) <=> ($a->getCount() ?? 0);
            return $cmp !== 0 ? $cmp : strcmp((string) $a->getName(), (string) $b->getName());
        });

        // Color map lookup
        $colorData = $csvLoader->getColors();
        $colorMap = [];
        foreach ($colorData as $c) {
            if (isset($c['id'])) {
                $colorMap[(int) $c['id']] = [
                    'name' => (string) ($c['name'] ?? ''),
                    'rgb' => (string) ($c['rgb'] ?? '808080'),
                    'is_trans' => ($c['is_trans'] ?? 'f') === 't',
                ];
            }
        }

        return $this->render('set/detail.html.twig', [
            'set' => $item,
            'pieces' => $pieces,
            'colorMap' => $colorMap,
            'prices' => $prices,
            'bestPrice' => $bestPrice,
            'lowestPrice' => $lowestPrice,
            'highestPrice' => $highestPrice,
            'savingsDelta' => $savingsDelta,
            'currentCurrency' => $currency,
            'excludeUsed' => $excludeUsed,
        ]);
    }

    /**
     * Set page lookup by set number (e.g. /set/no/2263-1)
     */
    #[Route(path: '/set/no/{no}', name: 'set_detail_by_no', methods: ['GET'])]
    public function setDetailByNo(string $no, SetRepository $setRepo): RedirectResponse
    {
        $cleanNo = trim($no);
        $set = $setRepo->findOneBy(['no' => $cleanNo]);
        if (!$set instanceof Set && !str_contains($cleanNo, '-')) {
            $set = $setRepo->findOneBy(['no' => $cleanNo . '-1']);
        }

        if (!$set instanceof Set) {
            throw $this->createNotFoundException('LEGO set not found.');
        }

        return $this->redirectToRoute('set_detail', ['id' => $set->getId()]);
    }

    /**
     * Legacy single item redirect
     */
    #[Route(path: '/item/{id}', name: 'list_item', requirements: ['id' => '\d+'])]
    public function listItem(Item $item): RedirectResponse
    {
        return $this->redirectToRoute('set_detail', ['id' => $item->getId()]);
    }

    /**
     * View detailed price history and trends for a set (modal / JSON API / fallback).
     */
    #[Route(path: '/item/{id}/prices', name: 'item_prices', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function itemPrices(
        Item $item,
        Request $request,
        \App\Service\UserPreferencesService $preferencesService,
        \App\Service\ExchangeRateService $exchangeRateService
    ): Response {
        if (!$item instanceof Set) {
            throw $this->createNotFoundException('Item is not a LEGO set.');
        }

        $excludeUsed = $request->query->has('excludeUsed')
            ? $request->query->getBoolean('excludeUsed')
            : $preferencesService->shouldExcludeUsedPrices();

        $currency = $preferencesService->getCurrency();
        $prices = $item->getPricesSortedByDate('DESC', $excludeUsed);
        $bestPrice = $item->getBestPrice($currency, $exchangeRateService, $excludeUsed);
        $lowestPrice = $item->getHistoricalLowestPrice($currency, $exchangeRateService, $excludeUsed);
        $highestPrice = $item->getHistoricalHighestPrice($currency, $exchangeRateService, $excludeUsed);
        $savingsDelta = $item->getHistoricalSavingsDelta($currency, $exchangeRateService, $excludeUsed);

        $isJson = $request->query->get('format') === 'json'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');

        if ($isJson) {
            $historyData = array_map(static fn(\App\Entity\SetPrice $p): array => [
                'id' => $p->getId(),
                'amount' => $p->getAmount(),
                'amountDecimal' => $p->getDecimalPrice(),
                'currency' => $p->getCurrency(),
                'formatted' => $p->getFormattedPrice(),
                'retailer' => $p->getRetailer()->value,
                'retailerLabel' => $p->getRetailer()->label(),
                'condition' => $p->getItemCondition(),
                'provider' => $p->getProvider()->value,
                'status' => $p->getStatus()->value,
                'url' => $p->getUrl(),
                'retailerUrl' => $p->getRetailerUrl(),
                'sourceUrl' => $p->getSourceUrl(),
                'recordedAt' => $p->getRecordedAt()->format(\DateTimeInterface::ATOM),
            ], $prices);

            return new \Symfony\Component\HttpFoundation\JsonResponse([
                'setId' => $item->getId(),
                'setNo' => $item->getNo(),
                'name' => $item->getName(),
                'currency' => $currency,
                'excludeUsed' => $excludeUsed,
                'bestPrice' => $bestPrice?->getFormattedPrice(),
                'lowestHistoricalPrice' => $lowestPrice?->getFormattedPrice(),
                'highestHistoricalPrice' => $highestPrice?->getFormattedPrice(),
                'savingsDelta' => $savingsDelta ? [
                    'savingsAmount' => $savingsDelta['savingsAmount'],
                    'savingsPercent' => $savingsDelta['savingsPercent'],
                    'highestPrice' => $savingsDelta['highest']->getFormattedPrice(),
                    'lowestPrice' => $savingsDelta['lowest']->getFormattedPrice(),
                ] : null,
                'prices' => $historyData,
            ]);
        }

        $template = $request->query->getBoolean('modal')
            ? 'list/_price_history_content.html.twig'
            : 'list/price_history.html.twig';

        return $this->render($template, [
            'set' => $item,
            'prices' => $prices,
            'bestPrice' => $bestPrice,
            'lowestPrice' => $lowestPrice,
            'highestPrice' => $highestPrice,
            'savingsDelta' => $savingsDelta,
            'currentCurrency' => $currency,
            'excludeUsed' => $excludeUsed,
        ]);
    }

    /**
     * Delete an own submitted price.
     */
    #[Route(path: '/price/{id}/delete', name: 'price_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deletePrice(
        SetPrice $price,
        Request $request,
        EntityManagerInterface $em,
        ExchangeRateService $exchangeRateService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User || $price->getSubmittedBy()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('For security purposes, you can only delete your own prices.');
        }

        if (!$this->isCsrfTokenValid('delete_price_' . $price->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectAfterPriceDelete($price, $request);
        }

        $set = $price->getSet();
        if ($set instanceof Set) {
            $set->removePrice($price);
            $set->updateCachedBestPrice($exchangeRateService);
            $em->persist($set);
        }

        $em->remove($price);
        $em->flush();

        $this->addFlash('success', 'Your price submission has been successfully deleted.');

        if ($request->isXmlHttpRequest() || $request->query->get('format') === 'json') {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectAfterPriceDelete($price, $request, $set);
    }

    private function redirectAfterPriceDelete(
        SetPrice $price,
        Request $request,
        ?Set $set = null
    ): RedirectResponse {
        $targetSet = $set ?? $price->getSet();
        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            if ($refererHost === null || $refererHost === $request->getHost()) {
                return $this->redirect($referer);
            }
        }

        if ($targetSet instanceof Set && $targetSet->getId() !== null) {
            return $this->redirectToRoute('set_detail', ['id' => $targetSet->getId()]);
        }

        return $this->redirectToRoute('filter_items');
    }
}
