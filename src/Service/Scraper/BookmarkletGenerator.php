<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class BookmarkletGenerator
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    /**
     * Generates the complete 'javascript:...' bookmarklet URL.
     */
    public function generateBookmarklet(string $apiUrl, string $apiToken, ?string $submitUrl = null): string
    {
        $runnerJs = $this->generateRunnerJs($apiUrl, $apiToken, $submitUrl);
        $cleanJs = $this->cleanJs($runnerJs);

        return 'javascript:' . rawurlencode($cleanJs);
    }

    /**
     * Generates the raw runner JavaScript code with the extractor embedded.
     */
    public function generateRunnerJs(string $apiUrl, string $apiToken, ?string $submitUrl = null): string
    {
        $submitUrl ??= preg_replace('#/api/prices/report/?$#', '/bookmarklet/submit', $apiUrl);
        $extractorPath = $this->projectDir . '/assets/js/scraper/extractor.js';
        $extractorJs = file_exists($extractorPath) ? (string) file_get_contents($extractorPath) : '';

        $cleanExtractor = $this->stripComments($extractorJs);
        $encodedApiUrl = json_encode($apiUrl, JSON_UNESCAPED_SLASHES);
        $encodedSubmitUrl = json_encode($submitUrl, JSON_UNESCAPED_SLASHES);
        $encodedApiToken = json_encode($apiToken, JSON_UNESCAPED_SLASHES);

        return <<<JS
(function(){
    {$cleanExtractor}

    var extractor = (typeof LegoPriceExtractor !== 'undefined')
        ? LegoPriceExtractor
        : ((typeof window !== 'undefined' && window.LegoPriceExtractor)
            ? window.LegoPriceExtractor
            : ((typeof self !== 'undefined' && self.LegoPriceExtractor) ? self.LegoPriceExtractor : {}));
    var items = (typeof extractor.extractAll === 'function')
        ? extractor.extractAll(document, window.location.href)
        : [];

    var apiUrl = {$encodedApiUrl};
    var submitUrl = {$encodedSubmitUrl};
    var apiToken = {$encodedApiToken};

    function submitViaPopup(targetUrl, payload) {
        var token = (payload && payload.token) ? payload.token : (apiToken || '');
        var jsonStr = JSON.stringify(payload);
        var urlWithData = targetUrl + (targetUrl.indexOf('?') === -1 ? '?' : '&')
            + 'token=' + encodeURIComponent(token)
            + '&data=' + encodeURIComponent(jsonStr);

        if (urlWithData.length < 2040) {
            window.open(urlWithData, '_blank', 'width=560,height=480,resizable=yes,scrollbars=yes');
            return;
        }

        var popupName = 'pa_' + Math.floor(Math.random() * 1000000);
        window.open('about:blank', popupName, 'width=560,height=480,resizable=yes,scrollbars=yes');
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = targetUrl + (targetUrl.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(token);
        form.target = popupName;

        var tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = 'token';
        tokenInput.value = token;
        form.appendChild(tokenInput);

        var payloadInput = document.createElement('input');
        payloadInput.type = 'hidden';
        payloadInput.name = 'payload';
        payloadInput.value = jsonStr;
        form.appendChild(payloadInput);

        document.body.appendChild(form);
        form.submit();
        setTimeout(function(){
            try { document.body.removeChild(form); } catch(e){}
        }, 1000);
    }

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

    var isCspStrict = /galaxus\.|digitec\./i.test(window.location.hostname);

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

        var payload = {
            setNo: it.setNo,
            retailer: it.retailer,
            amountCents: it.amountCents,
            currency: it.currency,
            url: it.url,
            provider: it.provider || 'bookmarklet',
            token: apiToken
        };

        if (isCspStrict) {
            submitViaPopup(submitUrl, payload);
            return;
        }

        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + apiToken
            },
            body: JSON.stringify(payload)
        }).then(function(res){ return res.json(); })
        .then(function(data){
            if (data.success) {
                alert('✓ Success! Set #' + data.setNo + ' (' + data.price + ')'
                    + ' recorded in Piece Analyzer for ' + data.retailer + '!');
            } else {
                alert('Error: ' + (data.error || 'Failed to submit price.'));
            }
        }).catch(function(err){
            submitViaPopup(submitUrl, payload);
        });
        return;
    }

    /* Multiple items found (e.g. 24 offers on Toppreise, 21 items on Lego, 47 sets on Brack) */
    var summary = 'Piece Analyzer: Detected ' + items.length + ' prices on this page!\\n\\n';
    if (items[0].merchantName) {
        summary += 'Store offers for Set #' + items[0].setNo + ' across ' + items.length + ' merchants.\\n';
    } else {
        var setNames = items.slice(0, 5).map(function(x){
            return '#' + x.setNo + ' (' + (x.amountCents/100).toFixed(2) + ' ' + x.currency + ')';
        }).join(', ');
        var extra = items.length > 5 ? ' ... and ' + (items.length - 5) + ' more' : '';
        summary += 'Sets found: ' + setNames + extra + '\\n';
    }
    summary += '\\nSubmit all ' + items.length + ' prices to Piece Analyzer?';

    if (!confirm(summary)) return;

    var batchPayload = {
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
    };

    if (isCspStrict) {
        submitViaPopup(submitUrl, batchPayload);
        return;
    }

    fetch(apiUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + apiToken
        },
        body: JSON.stringify(batchPayload)
    }).then(function(res){ return res.json(); })
    .then(function(data){
        if (data.success) {
            alert('✓ Success! Recorded ' + data.count + ' prices in Piece Analyzer!');
        } else {
            alert('Error: ' + (data.error || 'Failed to submit prices.'));
        }
    }).catch(function(err){
        submitViaPopup(submitUrl, batchPayload);
    });
})();
JS;
    }

    /**
     * Strips single-line and multi-line comments from JavaScript while preserving
     * string literals (single, double, template) and regular expression literals.
     */
    public function stripComments(string $js): string
    {
        $output = '';
        $len = strlen($js);
        $i = 0;

        while ($i < $len) {
            $c = $js[$i];

            // String literal: single quote
            if ($c === "'") {
                $output .= "'";
                $i++;
                while ($i < $len && $js[$i] !== "'") {
                    if ($js[$i] === '\\' && $i + 1 < $len) {
                        $output .= $js[$i] . $js[$i + 1];
                        $i += 2;
                    } else {
                        $output .= $js[$i++];
                    }
                }
                if ($i < $len) {
                    $output .= $js[$i++];
                }
            } elseif ($c === '"') {
                // String literal: double quote
                $output .= '"';
                $i++;
                while ($i < $len && $js[$i] !== '"') {
                    if ($js[$i] === '\\' && $i + 1 < $len) {
                        $output .= $js[$i] . $js[$i + 1];
                        $i += 2;
                    } else {
                        $output .= $js[$i++];
                    }
                }
                if ($i < $len) {
                    $output .= $js[$i++];
                }
            } elseif ($c === '`') {
                // Template literal: backtick
                $output .= '`';
                $i++;
                while ($i < $len && $js[$i] !== '`') {
                    if ($js[$i] === '\\' && $i + 1 < $len) {
                        $output .= $js[$i] . $js[$i + 1];
                        $i += 2;
                    } else {
                        $output .= $js[$i++];
                    }
                }
                if ($i < $len) {
                    $output .= $js[$i++];
                }
            } elseif ($c === '/' && $i + 1 < $len && $js[$i + 1] === '/') {
                // Single-line comment: strip until newline or EOF
                $i += 2;
                while ($i < $len && $js[$i] !== "\n" && $js[$i] !== "\r") {
                    $i++;
                }
            } elseif ($c === '/' && $i + 1 < $len && $js[$i + 1] === '*') {
                // Multi-line comment: strip until */
                $i += 2;
                while ($i + 1 < $len && !($js[$i] === '*' && $js[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
            } elseif ($c === '/' && $i + 1 < $len && $js[$i + 1] !== '/' && $js[$i + 1] !== '*') {
                // Determine whether '/' is a regex literal or division operator
                $prev = '';
                for ($j = strlen($output) - 1; $j >= 0; $j--) {
                    if (!ctype_space($output[$j])) {
                        $prev = $output[$j];
                        break;
                    }
                }

                // In JS grammar, regex literal follows punctuation/operators: = ( [ , ; : ! & | ? { } ~ + - * % ^
                if ($prev === '' || str_contains('=(:,;!&|?{}~+-*^', $prev) || $prev === '[') {
                    // Regex literal
                    $output .= '/';
                    $i++;
                    $inClass = false;
                    while ($i < $len) {
                        if ($js[$i] === '\\' && $i + 1 < $len) {
                            $output .= $js[$i] . $js[$i + 1];
                            $i += 2;
                        } elseif ($js[$i] === '[' && !$inClass) {
                            $inClass = true;
                            $output .= $js[$i++];
                        } elseif ($js[$i] === ']' && $inClass) {
                            $inClass = false;
                            $output .= $js[$i++];
                        } elseif ($js[$i] === '/' && !$inClass) {
                            $output .= $js[$i++];
                            // Regex flags (e.g. g, i, m, s, u, y)
                            while ($i < $len && ctype_alpha($js[$i])) {
                                $output .= $js[$i++];
                            }
                            break;
                        } else {
                            $output .= $js[$i++];
                        }
                    }
                } else {
                    $output .= $c;
                    $i++;
                }
            } else {
                $output .= $c;
                $i++;
            }
        }

        return $output;
    }

    /**
     * Cleans up whitespace while maintaining line structure and statement separation.
     */
    public function cleanJs(string $js): string
    {
        $stripped = $this->stripComments($js);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $stripped));
        $cleanLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $cleanLines[] = $trimmed;
            }
        }

        return implode("\n", $cleanLines);
    }
}
