<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Description of GeneralController
 *
 * @author timbernhard
 */
class IndexController extends Controller
{
    
    /**
     * @Route("/", name="index")
     */
    public function indexAction()
    {
        return $this->render('static/index.html.twig');
    }
}
