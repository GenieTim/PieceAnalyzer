<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UserPreferencesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PreferenceController extends AbstractController
{
    #[Route(path: '/preferences/currency/{currency}', name: 'set_currency_preference', methods: ['GET', 'POST'])]
    public function setCurrency(string $currency, Request $request, UserPreferencesService $preferences): RedirectResponse
    {
        $preferences->setCurrency($currency);

        $referer = $request->headers->get('referer');
        if ($referer !== null && $referer !== '') {
            $parsedHost = parse_url($referer, PHP_URL_HOST);
            $parsedScheme = parse_url($referer, PHP_URL_SCHEME);
            if ($parsedHost !== null) {
                if ($parsedHost === $request->getHost() && in_array(strtolower((string) $parsedScheme), ['http', 'https'], true)) {
                    return $this->redirect($referer);
                }
            } elseif (str_starts_with($referer, '/') && !str_starts_with($referer, '//') && !str_starts_with($referer, '/\\')) {
                return $this->redirect($referer);
            }
        }

        return $this->redirectToRoute('filter_items');
    }
}
