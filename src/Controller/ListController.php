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

class ListController extends Controller
{

    /**
     * 
     * @Route("/all", name="list_all")
     */
    public function listAllAction()
    {
        return $this->redirectToRoute('filter_items');
    }
    
    /**
     * 
     * @Route("/filter", name="filter_items")
     * @param Request $request
     */
    public function filterAction(Request $request) {
        $em = $this->getDoctrine()->getManager(Set::class);
        $set_repo = $em->getRepository()
        $form = $this->createForm(FilterFormType::class);
        $form->handleRequest($request);
        $sets = array();
        if ($form->isSubmitted() && $form->isValid()) {
            $sets = $set_repo->findMostValuableBy($form->getData());
        } else {
            $sets = $set_repo->findMostValuableBy();
        }
        
        return $this->renderView('list:list_all.html.twig', array(
            'sets' => $sets,
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