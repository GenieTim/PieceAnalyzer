<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class BookmarkletController extends AbstractController
{
    #[Route(path: '/bookmarklet', name: 'bookmarklet')]
    public function index() : Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }
        $apiUrl = $this->generateUrl('api_price_report', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $apiToken = $user->getApiToken();
        $js = <<<JS
(function(){
    var host = window.location.hostname.toLowerCase();
    var url = window.location.href;
    var retailer = 'other';
    var priceText = '';
    var currency = 'CHF';
    var titleText = (document.title || '') + ' ' + (document.querySelector('h1') ? document.querySelector('h1').innerText : '');
    
    // 1. Detect Retailer
    if (host.indexOf('galaxus.ch') !== -1 || host.indexOf('digitec.ch') !== -1) {
        retailer = 'galaxus';
        currency = 'CHF';
    } else if (host.indexOf('brack.ch') !== -1) {
        retailer = 'brack';
        currency = 'CHF';
    } else if (host.indexOf('amazon.de') !== -1) {
        retailer = 'amazon_de';
        currency = 'EUR';
    } else if (host.indexOf('amazon.com') !== -1) {
        retailer = 'amazon_com';
        currency = 'USD';
    }

    // 2. Try JSON-LD Schema.org Product data
    var ldScripts = document.querySelectorAll('script[type="application/ld+json"]');
    for (var i = 0; i < ldScripts.length; i++) {
        try {
            var data = JSON.parse(ldScripts[i].innerText);
            var items = Array.isArray(data) ? data : [data];
            for (var j = 0; j < items.length; j++) {
                var it = items[j];
                if (it['@type'] === 'Product' || (it['@graph'] && Array.isArray(it['@graph']))) {
                    var prod = it['@type'] === 'Product' ? it : it['@graph'].find(function(g){ return g['@type'] === 'Product'; });
                    if (prod && prod.offers) {
                        var off = Array.isArray(prod.offers) ? prod.offers[0] : prod.offers;
                        if (off && (off.price || off.lowPrice)) {
                            priceText = String(off.price || off.lowPrice);
                            if (off.priceCurrency) currency = off.priceCurrency.toUpperCase();
                            break;
                        }
                    }
                }
            }
            if (priceText) break;
        } catch(e) {}
    }

    // 3. Fallback: meta tags & DOM selectors
    if (!priceText) {
        var metaPrice = document.querySelector('meta[property="product:price:amount"], meta[itemprop="price"]');
        if (metaPrice && metaPrice.content) {
            priceText = metaPrice.content;
            var metaCurr = document.querySelector('meta[property="product:price:currency"], meta[itemprop="priceCurrency"]');
            if (metaCurr && metaCurr.content) currency = metaCurr.content.toUpperCase();
        }
    }

    if (!priceText) {
        var selectors = [
            '[data-test="product-price"]',
            '[data-test="price"]',
            '[data-qa="product-detail-price"]',
            '.productPrice',
            '.a-price .a-offscreen',
            '#corePrice_feature_div .a-offscreen',
            'strong[class*="price"]',
            'span[class*="price"]'
        ];
        for (var k = 0; k < selectors.length; k++) {
            var el = document.querySelector(selectors[k]);
            if (el && el.innerText && /[\d]/.test(el.innerText)) {
                priceText = el.innerText;
                break;
            }
        }
    }

    // 4. Extract Set Number
    var setMatch = titleText.match(/\\b(?:LEGO|Set)?\\s*([1-9]\\d{3,5})\\b/i);
    var detectedSet = setMatch ? setMatch[1] : '';

    var setNo = prompt('Piece Analyzer Price Report\\n\\nConfirm or enter Set #:', detectedSet);
    if (!setNo) return;

    var cleanPrice = priceText.replace(/[^\\d.,]/g, '').replace(',', '.');
    var priceInput = prompt('Enter price in ' + currency + ':', cleanPrice || '');
    if (!priceInput) return;

    var amountCents = Math.round(parseFloat(priceInput) * 100);
    if (isNaN(amountCents) || amountCents <= 0) {
        alert('Invalid price format.');
        return;
    }

    fetch('{$apiUrl}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer {$apiToken}'
        },
        body: JSON.stringify({
            setNo: setNo.trim(),
            retailer: retailer,
            amountCents: amountCents,
            currency: currency,
            url: url,
            provider: 'bookmarklet',
            token: '{$apiToken}'
        })
    }).then(function(res){ return res.json(); })
    .then(function(data){
        if (data.success) {
            alert('✓ Success! Set #' + data.setNo + ' (' + data.price + ') recorded in Piece Analyzer for ' + data.retailer + '!');
        } else {
            alert('Error: ' + (data.error || 'Failed to submit price.'));
        }
    }).catch(function(err){
        alert('Could not submit price to Piece Analyzer: ' + err.message);
    });
})();
JS;
        $bookmarkletHref = 'javascript:' . rawurlencode(trim(preg_replace('/\s+/', ' ', $js) ?? ''));
        return $this->render('bookmarklet/index.html.twig', [
            'bookmarkletHref' => $bookmarkletHref,
            'apiUrl' => $apiUrl,
            'apiToken' => $apiToken,
        ]);
    }

    #[Route(path: '/bookmarklet/regenerate-token', name: 'bookmarklet_regenerate_token', methods: ['POST'])]
    public function regenerateToken(EntityManagerInterface $em): RedirectResponse
    {
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
