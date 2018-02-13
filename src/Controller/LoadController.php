<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use App\Form\SelectLoadFormType;
use App\Service\LegoLoaderService;

class LoadController extends Controller {

    /**
     * Load a range of set no's
     * 
     * @Route("/range", name="load_range")
     * @param Request $request
     * @param LegoLoaderService $loader
     * @return Response
     */
    public function loadRangeAction(Request $request, LegoLoaderService $loader) {
        $form = $this->createForm(SelectLoadFormType::class);
        
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $loader->loadSets($data['from'], $data['to']);
            return $this->redirectToRoute('index');
        }
        
        return $this->renderView('form:load_form.html.twig', array(
            'form' => $form->createView()
        ));
    }
}