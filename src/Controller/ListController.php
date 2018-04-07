<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Set;
use App\Repository\SetRepository;
use App\Entity\Piece;
use App\Entity\Item;
use App\Form\FilterFormType;

class ListController extends Controller {

    /**
     * 
     * @Route("/all", name="list_all")
     */
    public function listAllAction() {
        return $this->redirectToRoute('filter_items');
    }

    /**
     * 
     * @Route("/filter", name="filter_items")
     * @param Request $request
     */
    public function filterAction(Request $request) {
        $em = $this->getDoctrine()->getManager();
        $set_repo = $em->getRepository(Set::class);
        $form = $this->createForm(FilterFormType::class);
        $form->handleRequest($request);
        $criteria = array();
        if ($form->isSubmitted() && $form->isValid()) {
            $criteria = $form->getData();
        } 
        $paginator = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
                $set_repo->getMostValuableByQuery($criteria)->getResult(), /* TODO: query NOT result */ 
                $request->query->getInt('page', 1)/* page number */, 
                50,/* limit per page */
//                array('wrap-queries'=>true)
                array()
        );

        return $this->render('list/list_all.html.twig', array(
                    'pagination' => $pagination,
                    'form' => $form->createView()
        ));
    }

    /**
     * 
     * @Route("/item/{id}", name="list_item", requirements={"id"="\d+"})
     * @param Item $item
     */
    public function listItemAction(Item $item) {
        return $this->redirect('http://bricklink.com/v2/catalog/catalogitem.page?S=' . $item->getNo());
    }

}
