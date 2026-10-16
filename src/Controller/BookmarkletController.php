<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Scraper\BookmarkletGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class BookmarkletController extends AbstractController
{
    public function __construct(
        private readonly BookmarkletGenerator $bookmarkletGenerator
    ) {
    }

    #[Route(path: '/bookmarklet', name: 'bookmarklet')]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        $apiUrl = $this->generateUrl('api_price_report', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $apiToken = $user->getApiToken();

        $bookmarkletHref = $this->bookmarkletGenerator->generateBookmarklet($apiUrl, $apiToken);

        return $this->render('bookmarklet/index.html.twig', [
            'bookmarkletHref' => $bookmarkletHref,
            'apiUrl' => $apiUrl,
            'apiToken' => $apiToken,
        ]);
    }

    #[Route(path: '/bookmarklet/regenerate-token', name: 'bookmarklet_regenerate_token', methods: ['POST'])]
    public function regenerateToken(Request $request, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('regenerate_token', (string) $request->request->get('_token'))) {
            $this->addFlash('alert', 'Invalid security token.');
            return $this->redirectToRoute('bookmarklet');
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $user->regenerateApiToken();
            $em->persist($user);
            $em->flush();
            $this->addFlash('success', 'Your Contributor API token has been regenerated.');
        }

        return $this->redirectToRoute('bookmarklet');
    }
}
