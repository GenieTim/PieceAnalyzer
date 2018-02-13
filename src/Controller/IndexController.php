<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 * Description of GeneralController
 *
 * @author timbernhard
 */
class IndexController extends Controller {
    
    /**
     * @Route("/", name="index")
     */
    public function indexAction() {
        return $this->renderView('static/index.html.twig');
    }
}
