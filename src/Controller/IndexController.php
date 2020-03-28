<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Description of GeneralController
 *
 * @author timbernhard
 */
class IndexController extends AbstractController
{

    /**
     * @Route("/", name="index")
     */
    public function indexAction()
    {
        return $this->render('static/index.html.twig');
    }
}
