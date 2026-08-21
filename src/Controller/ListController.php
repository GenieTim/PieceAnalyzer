<?php

namespace App\Controller;

use App\Entity\Set;
use App\Entity\Item;
use App\Form\FilterFormType;
use Knp\Component\Pager\Paginator;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ListController extends AbstractController
{

    /**
     * Legacy route
     *
     * @deprecated v3
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/all', name: 'list_all')]
    public function listAll(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute('filter_items');
    }

    /**
     * Filter all sets
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/filter', name: 'filter_items')]
    public function filter(Request $request, PaginatorInterface $paginator, \App\Repository\SetRepository $setRepo): \Symfony\Component\HttpFoundation\Response
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
            50
        );

        return $this->render('list/list_all.html.twig', [
            'pagination' => $pagination,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Redirect to a vendor to see the set/item
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/item/{id}', name: 'list_item', requirements: ['id' => '\d+'])]
    public function listItem(Item $item): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if ($item instanceof Set && $item->getSource() === Set::SOURCE_REBRICKABLE) {
            return $this->redirect('https://rebrickable.com/sets/' . $item->getNo());
        }
        return $this->redirect('http://bricklink.com/v2/catalog/catalogitem.page?S=' . $item->getNo());
    }
}
