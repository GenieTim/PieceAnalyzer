<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Item;
use App\Entity\Set;
use App\Entity\User;
use App\Form\FilterFormType;
use App\Repository\SetRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
        \App\Service\UserPreferencesService $preferencesService
    ): Response {
        $form = $this->createForm(FilterFormType::class);
        $form->handleRequest($request);
        $criteria = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $criteria = (array) $form->getData();
        } elseif (!$form->isSubmitted() && ($request->query->has('part') || $request->query->has('color') || $request->query->has('category') || $request->query->has('minYear') || $request->query->has('year'))) {
            $minYear = $request->query->has('minYear')
                ? $request->query->getInt('minYear')
                : ($request->query->has('year') ? $request->query->getInt('year') : null);

            $criteria = [
                'part' => $request->query->get('part'),
                'color' => $request->query->getInt('color'),
                'category' => $request->query->getInt('category'),
            ];
            if ($minYear !== null && $minYear > 0) {
                $criteria['minYear'] = $minYear;
            }
            $form->submit($criteria, false);
        }

        $user = $this->getUser();
        $currency = $preferencesService->getCurrency();
        $pagination = $paginator->paginate(
            $setRepo->getMostValuableByQuery($criteria, 0, $user instanceof User ? $user : null, $currency),
            $request->query->getInt('page', 1),
            50,
            ['wrap-queries' => true]
        );

        return $this->render('list/list_all.html.twig', [
            'pagination' => $pagination,
            'form' => $form->createView(),
            'criteria' => $criteria,
        ]);
    }

    /**
     * Dedicated Set Page with set overview, images, piece inventory list, and price history & trends.
     */
    #[Route(path: '/set/{id}', name: 'set_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function setDetail(
        Item $item,
        \App\Service\UserPreferencesService $preferencesService,
        \App\Service\ExchangeRateService $exchangeRateService,
        \App\Service\CsvLegoLoaderService $csvLoader
    ): Response {
        if (!$item instanceof Set) {
            throw $this->createNotFoundException('Item is not a LEGO set.');
        }

        $currency = $preferencesService->getCurrency();
        $prices = $item->getPricesSortedByDate('DESC');
        $bestPrice = $item->getBestPrice($currency, $exchangeRateService);
        $lowestPrice = $item->getHistoricalLowestPrice($currency, $exchangeRateService);
        $highestPrice = $item->getHistoricalHighestPrice($currency, $exchangeRateService);
        $savingsDelta = $item->getHistoricalSavingsDelta($currency, $exchangeRateService);

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

        $currency = $preferencesService->getCurrency();
        $prices = $item->getPricesSortedByDate('DESC');
        $bestPrice = $item->getBestPrice($currency, $exchangeRateService);
        $lowestPrice = $item->getHistoricalLowestPrice($currency, $exchangeRateService);
        $highestPrice = $item->getHistoricalHighestPrice($currency, $exchangeRateService);
        $savingsDelta = $item->getHistoricalSavingsDelta($currency, $exchangeRateService);

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
        ]);
    }
}
