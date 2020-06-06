<?php

namespace App\Controller;

use App\Entity\Set;
use App\Entity\Item;
use App\Form\FilterFormType;
use Knp\Component\Pager\Paginator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ListController extends AbstractController
{

    /**
     * Legacy route
     *
     * @deprecated v3
     * @Route("/all", name="list_all")
     */
    public function listAllAction()
    {
        return $this->redirectToRoute('filter_items');
    }

    /**
     * Filter all sets
     *
     * @Route("/filter", name="filter_items")
     * @param Request $request
     */
    public function filterAction(Request $request, Paginator $paginator)
    {
        $em = $this->getDoctrine()->getManager();
        $set_repo = $em->getRepository(Set::class);
        $form = $this->createForm(FilterFormType::class);
        $form->handleRequest($request);
        $criteria = array();
        if ($form->isSubmitted() && $form->isValid()) {
            $criteria = $form->getData();
        }
        $pagination = $paginator->paginate(
            $set_repo->getMostValuableByQuery($criteria)->getResult(), /* query NOT result */
            $request->query->getInt('page', 1) /* page number */,
            50/* limit per page */
            // array('wrap-queries' => true)
        );

        return $this->render('list/list_all.html.twig', array(
            'pagination' => $pagination,
            'form' => $form->createView(),
        ));
    }

    /**
     * Redirect to a vendor to see the set/item
     *
     * @Route("/item/{id}", name="list_item", requirements={"id"="\d+"})
     * @param Item $item
     */
    public function listItemAction(Item $item)
    {
        if ($item instanceof Set && $item->getSource() === Set::SOURCE_REBRICKABLE) {
            return $this->redirect('https://rebrickable.com/sets/' . $item->getNo());
        }
        return $this->redirect('http://bricklink.com/v2/catalog/catalogitem.page?S=' . $item->getNo());
    }
}
