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

        return $this->redirectToRoute('filter_items', ['_locale' => $request->getLocale()]);
    }

    #[Route(path: '/preferences/locale/{locale}', name: 'set_locale_preference', methods: ['GET', 'POST'])]
    public function setLocale(string $locale, Request $request): RedirectResponse
    {
        $targetLocale = strtolower(trim($locale));
        if (in_array($targetLocale, ['en', 'de', 'fr'], true)) {
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session instanceof \Symfony\Component\HttpFoundation\Session\SessionInterface) {
                $session->set('_locale', $targetLocale);
            }
            $request->setLocale($targetLocale);
        } else {
            $targetLocale = 'en';
        }

        $referer = $request->headers->get('referer');
        if ($referer !== null && $referer !== '') {
            $parsedHost = parse_url($referer, PHP_URL_HOST);
            $parsedScheme = parse_url($referer, PHP_URL_SCHEME);
            $rawPath = parse_url($referer, PHP_URL_PATH);
            $parsedPath = is_string($rawPath) ? $rawPath : '/';
            $parsedQuery = parse_url($referer, PHP_URL_QUERY);

            $newPath = preg_replace('#^/(en|de|fr)(/|$)#', '/' . $targetLocale . '$2', $parsedPath);
            if ($newPath === null || ($newPath === $parsedPath && !preg_match('#^/(en|de|fr)(/|$)#', $parsedPath))) {
                $newPath = '/' . $targetLocale . (str_starts_with($parsedPath, '/') ? $parsedPath : '/' . $parsedPath);
            }

            $queryString = $parsedQuery ? '?' . $parsedQuery : '';

            if ($parsedHost !== null) {
                if ($parsedHost === $request->getHost() && in_array(strtolower((string) $parsedScheme), ['http', 'https'], true)) {
                    $port = parse_url($referer, PHP_URL_PORT);
                    $portStr = $port ? ':' . $port : '';
                    return $this->redirect($parsedScheme . '://' . $parsedHost . $portStr . $newPath . $queryString);
                }
            } elseif (str_starts_with($referer, '/') && !str_starts_with($referer, '//') && !str_starts_with($referer, '/\\')) {
                return $this->redirect($newPath . $queryString);
            }
        }

        return $this->redirectToRoute('filter_items', ['_locale' => $targetLocale]);
    }
}
