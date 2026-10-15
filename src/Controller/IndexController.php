<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IndexController extends AbstractController
{
    public function root(Request $request): RedirectResponse
    {
        $preferredLocale = $request->getPreferredLanguage(['en', 'de', 'fr']) ?? 'en';
        return $this->redirectToRoute('index', ['_locale' => $preferredLocale], Response::HTTP_FOUND);
    }

    #[Route(path: '/', name: 'index')]
    public function index(): Response
    {
        return $this->render('static/index.html.twig');
    }
}
