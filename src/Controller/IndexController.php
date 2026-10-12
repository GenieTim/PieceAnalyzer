<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Description of GeneralController
 *
 * @author timbernhard
 */
class IndexController extends AbstractController
{
    #[Route(path: '/', name: 'index')]
    public function index(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('static/index.html.twig');
    }
}
