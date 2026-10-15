<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
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

        $extractorPath = $this->projectDir . '/assets/js/scraper/extractor.js';
        $extractorJs = file_exists($extractorPath) ? (string) file_get_contents($extractorPath) : '';

        $runnerJs = <<<JS
(function(){
    {$extractorJs}

    var extractor = (typeof LegoPriceExtractor !== 'undefined') ? LegoPriceExtractor : (window.LegoPriceExtractor || {});
    var items = (typeof extractor.extractAll === 'function') ? extractor.extractAll(document, window.location.href) : [];

    var apiUrl = '{$apiUrl}';
    var apiToken = '{$apiToken}';

    if (!items || items.length === 0) {
        var setNo = prompt('Piece Analyzer: No price automatically detected.\\nEnter LEGO Set #:');
        if (!setNo) return;
        var priceInput = prompt('Enter price in CHF:');
        if (!priceInput) return;
        var amountCents = Math.round(parseFloat(priceInput.replace(',', '.')) * 100);
        if (isNaN(amountCents) || amountCents <= 0) {
            alert('Invalid price format.');
            return;
        }
        items = [{
            setNo: setNo.trim(),
            retailer: 'other',
            amountCents: amountCents,
            currency: 'CHF',
            url: window.location.href,
            provider: 'bookmarklet'
        }];
    }

    if (items.length === 1) {
        var it = items[0];
        var confirmedSet = prompt('Piece Analyzer Price Report\\n\\nConfirm or edit Set #:', it.setNo);
        if (!confirmedSet) return;
        var confirmedPrice = prompt('Enter price in ' + it.currency + ':', (it.amountCents / 100).toFixed(2));
        if (!confirmedPrice) return;
        it.setNo = confirmedSet.trim();
        it.amountCents = Math.round(parseFloat(confirmedPrice.replace(',', '.')) * 100);
        if (isNaN(it.amountCents) || it.amountCents <= 0) {
            alert('Invalid price format.');
            return;
        }

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + apiToken
            },
            body: JSON.stringify({
                setNo: it.setNo,
                retailer: it.retailer,
                amountCents: it.amountCents,
                currency: it.currency,
                url: it.url,
                provider: it.provider || 'bookmarklet',
                token: apiToken
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
        return;
    }

    // Multiple items found (e.g. 24 offers on Toppreise, 21 items on Lego, 47 sets on Brack)
    var summary = 'Piece Analyzer: Detected ' + items.length + ' prices on this page!\\n\\n';
    if (items[0].merchantName) {
        summary += 'Store offers for Set #' + items[0].setNo + ' across ' + items.length + ' merchants.\\n';
    } else {
        summary += 'Sets found: ' + items.slice(0, 5).map(function(x){ return '#' + x.setNo + ' (' + (x.amountCents/100).toFixed(2) + ' ' + x.currency + ')'; }).join(', ') + (items.length > 5 ? ' ... and ' + (items.length - 5) + ' more' : '') + '\\n';
    }
    summary += '\\nSubmit all ' + items.length + ' prices to Piece Analyzer?';

    if (!confirm(summary)) return;

    fetch(apiUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + apiToken
        },
        body: JSON.stringify({
            items: items.map(function(x){
                return {
                    setNo: x.setNo,
                    retailer: x.retailer,
                    amountCents: x.amountCents,
                    currency: x.currency,
                    url: x.url,
                    provider: x.provider || 'bookmarklet'
                };
            }),
            token: apiToken
        })
    }).then(function(res){ return res.json(); })
    .then(function(data){
        if (data.success) {
            alert('✓ Success! Recorded ' + data.count + ' prices in Piece Analyzer!');
        } else {
            alert('Error: ' + (data.error || 'Failed to submit prices.'));
        }
    }).catch(function(err){
        alert('Could not submit prices to Piece Analyzer: ' + err.message);
    });
})();
JS;

        $bookmarkletHref = 'javascript:' . rawurlencode(trim(preg_replace('/\s+/', ' ', $runnerJs) ?? ''));
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
