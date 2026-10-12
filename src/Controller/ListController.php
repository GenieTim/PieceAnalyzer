<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Item;
use App\Entity\Set;
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
    public function filter(Request $request, PaginatorInterface $paginator, SetRepository $setRepo): Response
    {
        $form = $this->createForm(FilterFormType::class);
        $form->handleRequest($request);
        $criteria = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $criteria = (array) $form->getData();
        }
        $pagination = $paginator->paginate(
            $setRepo->getMostValuableByQuery($criteria),
            $request->query->getInt('page', 1),
            50,
            ['wrap-queries' => true]
        );

        return $this->render('list/list_all.html.twig', [
            'pagination' => $pagination,
            'form' => $form->createView(),
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
}

