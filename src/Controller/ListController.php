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
        } elseif (!$form->isSubmitted() && ($request->query->has('part') || $request->query->has('color') || $request->query->has('category'))) {
            $criteria = [
                'part' => $request->query->get('part'),
                'color' => $request->query->getInt('color'),
                'category' => $request->query->getInt('category'),
            ];
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
     * Redirect to a vendor to see the set/item
     */
    #[Route(path: '/item/{id}', name: 'list_item', requirements: ['id' => '\d+'])]
    public function listItem(Item $item): RedirectResponse
    {
        $setNo = rawurlencode((string) $item->getNo());
        if ($item instanceof Set && $item->getSource() === Set::SOURCE_REBRICKABLE) {
            return $this->redirect('https://rebrickable.com/sets/' . $setNo);
        }
        return $this->redirect('https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . $setNo);
    }

    /**
     * View detailed price history and trends for a set.
     */
    #[Route(path: '/item/{id}/prices', name: 'item_prices', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function itemPrices(
        Item $item,
        Request $request,
        \App\Service\UserPreferencesService $preferencesService
    ): Response {
        if (!$item instanceof Set) {
            throw $this->createNotFoundException('Item is not a LEGO set.');
        }

        $currency = $preferencesService->getCurrency();
        $prices = $item->getPricesSortedByDate('DESC');
        $bestPrice = $item->getBestPrice($currency);
        $lowestPrice = $item->getHistoricalLowestPrice($currency);
        $highestPrice = $item->getHistoricalHighestPrice($currency);
        $savingsDelta = $item->getHistoricalSavingsDelta($currency);

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
                'provider' => $p->getProvider()->value,
                'status' => $p->getStatus()->value,
                'url' => $p->getUrl(),
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
