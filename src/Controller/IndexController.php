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

    #[\Symfony\Component\Routing\Attribute\Route(path: '/', name: 'index')]
    public function index(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('static/index.html.twig');
    }
}
