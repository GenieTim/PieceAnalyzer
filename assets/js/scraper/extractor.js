/**
 * Universal LEGO Price Extractor Engine
 * 
 * Shared between:
 * 1. Browser Bookmarklet (interactive user price submissions)
 * 2. Automated cronjob scrapers (Camoufox / Playwright / Puppeteer headless runners)
 * 3. Unit test suites against recorded HTML fixtures
 *
 * Zero external dependencies. Works in standard DOM and Node.js DOM adapters.
 */

(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.LegoPriceExtractor = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * Parse price strings into structured numbers and currency.
     * Handles: 'CHF 32.05', 'ab CHF 24.19', '37 Angebote ab CHF 265.30', '179,00 CHF', '179.-', '€149,99', '$199.99'
     */
    function parsePrice(text, defaultCurrency = 'CHF') {
        if (typeof text === 'number') {
            return {
                price: Math.round(text * 100) / 100,
                amountCents: Math.round(text * 100),
                currency: defaultCurrency
            };
        }
        if (!text || typeof text !== 'string') return null;

        let currency = defaultCurrency;
        if (/EUR|€/i.test(text)) currency = 'EUR';
        else if (/USD|\$/i.test(text)) currency = 'USD';
        else if (/GBP|£/i.test(text)) currency = 'GBP';
        else if (/CHF/i.test(text)) currency = 'CHF';

        // Isolate price portion near currency symbol/code if present (e.g. "37 Angebote ab CHF 265.30")
        const currMatch = text.match(/(?:CHF|EUR|€|\$|USD|GBP|£)\s*([0-9]{1,3}(?:['’]\d{3})*(?:[.,]\d{1,2})?|[0-9]+(?:[.,]\d{1,2})?)/i) ||
                          text.match(/([0-9]{1,3}(?:['’]\d{3})*(?:[.,]\d{1,2})?|[0-9]+(?:[.,]\d{1,2})?)\s*(?:CHF|EUR|€|\$|USD|GBP|£)/i);
        let numStr = currMatch ? currMatch[1] : text;

        // Clean raw number part
        let cleaned = numStr.replace(/[^0-9.,-]/g, '').trim();
        if (cleaned.endsWith('.-')) {
            cleaned = cleaned.slice(0, -2);
        }

        if (cleaned.includes(',') && !cleaned.includes('.')) {
            cleaned = cleaned.replace(',', '.');
        } else if (cleaned.includes('.') && cleaned.includes(',')) {
            if (cleaned.indexOf('.') < cleaned.indexOf(',')) {
                cleaned = cleaned.replace(/\./g, '').replace(',', '.');
            } else {
                cleaned = cleaned.replace(/,/g, '');
            }
        }

        const val = parseFloat(cleaned);
        if (isNaN(val) || val <= 0) return null;

        return {
            price: Math.round(val * 100) / 100,
            amountCents: Math.round(val * 100),
            currency: currency
        };
    }

    /**
     * Extract 4-6 digit LEGO set number from a string, title, or URL path.
     */
    function extractSetNumber(str) {
        if (!str || typeof str !== 'string') return null;

        // 1. Check for trailing slug pattern e.g. "/product/porsche-911-10295" or "...-40809-p818086" or "...-60510"
        const trailingSlugMatch = str.match(/[-_]([1-9]\d{3,5})(?:\.html?|\/|\?|#|$)/i);
        if (trailingSlugMatch) {
            const num = trailingSlugMatch[1];
            if (!/^(?:19\d\d|20[0-3]\d)$/.test(num)) {
                return num;
            }
        }

        // 2. Check for explicit parentheses e.g. "(40809)" or "Set 40809" or "LEGO #40809"
        const explicitMatch = str.match(/\((?:LEGO\s*)?([1-9]\d{3,5})\)/i) ||
                             str.match(/\b(?:LEGO|Set)\s*#?([1-9]\d{3,5})\b/i);
        if (explicitMatch) return explicitMatch[1];

        // 3. Check for URL slug patterns e.g. "/lego-...-42232-2112722"
        const urlMatch = str.match(/[-_/]([1-9]\d{3,5})(?:-[a-z0-9]+|\.html?|\/|$)/i);
        if (urlMatch) {
            const num = urlMatch[1];
            if (!/^(?:19\d\d|20[0-3]\d)$/.test(num)) {
                return num;
            }
        }

        // 4. Fallback: match standalone 4-6 digit number (excluding years 1900-2039 if other numbers exist)
        const matches = str.match(/\b([1-9]\d{3,5})\b/g);
        if (matches) {
            for (const m of matches) {
                if (/^(?:19\d\d|20[0-3]\d)$/.test(m) && matches.length > 1) {
                    continue;
                }
                return m;
            }
            return matches[0];
        }

        return null;
    }

    /**
     * Helper to read JSON-LD scripts from a document.
     */
    function getJsonLdScripts(doc) {
        const scripts = doc.querySelectorAll('script[type="application/ld+json"]');
        const results = [];
        for (let i = 0; i < scripts.length; i++) {
            try {
                const text = (scripts[i].textContent || scripts[i].innerText || '').trim();
                if (!text) continue;
                const parsed = JSON.parse(text);
                if (Array.isArray(parsed)) {
                    results.push(...parsed);
                } else if (parsed['@graph'] && Array.isArray(parsed['@graph'])) {
                    results.push(...parsed['@graph']);
                } else {
                    results.push(parsed);
                }
            } catch (e) {
                // Ignore invalid JSON-LD blocks
            }
        }
        return results;
    }

    /**
     * Deduplicate an array of extracted price report items.
     * Keeps the first occurrence based on setNo, retailer, merchantName, and amountCents.
     */
    function deduplicateItems(items) {
        const seen = new Set();
        const unique = [];
        for (const item of items) {
            const key = item.setNo + ':' + item.retailer + ':' + (item.merchantName || '') + ':' + item.amountCents;
            if (!seen.has(key)) {
                seen.add(key);
                unique.push(item);
            }
        }
        return unique;
    }

    /**
     * Map merchant/shop name or hostname to Retailer enum.
     */
    function mapRetailer(shopName, host = '') {
        const s = (shopName || '').toLowerCase();
        const h = (host || '').toLowerCase();

        if (s.includes('galaxus') || s.includes('digitec') || h.includes('galaxus.ch') || h.includes('digitec.ch')) {
            return 'galaxus';
        }
        if (s.includes('brack') || h.includes('brack.ch')) {
            return 'brack';
        }
        if (s.includes('lego shop') || s.includes('lego store') || (s === 'lego' && !s.includes('lego-')) || h.includes('lego.com')) {
            return 'lego_store';
        }
        if (s.includes('amazon.de') || h.includes('amazon.de')) {
            return 'amazon_de';
        }
        if (s.includes('amazon.com') || h.includes('amazon.com')) {
            return 'amazon_com';
        }
        return 'other';
    }

    /**
     * Extract prices from Toppreise.ch:
     * - Single product page: extracts all merchant offers from all sources listed on the page.
     * - Search / category overview: extracts all search result sets with their best price.
     */
    function extractToppreise(doc, url = '') {
        const items = [];
        const docTitle = doc.title || '';

        // 1. Single Set Price Comparison Page (contains [id^="Plugin_Offer_"])
        const offerNodes = doc.querySelectorAll('[id^="Plugin_Offer_"]');
        if (offerNodes.length > 0) {
            // Find main set number
            let mainSetNo = extractSetNumber(docTitle);
            const jsonLds = getJsonLdScripts(doc);
            for (const ld of jsonLds) {
                if (ld['@type'] === 'Product') {
                    if (ld.mpn) mainSetNo = mainSetNo || String(ld.mpn);
                    if (ld.name) mainSetNo = mainSetNo || extractSetNumber(ld.name);
                }
            }
            if (!mainSetNo) {
                mainSetNo = extractSetNumber(url);
            }

            for (let i = 0; i < offerNodes.length; i++) {
                const node = offerNodes[i];
                // Merchant / Shop name:
                const img = node.querySelector('img[title], img[alt]');
                const shopName = img ? (img.getAttribute('title') || img.getAttribute('alt') || '') : '';
                
                // Product price: prefer .Plugin_Price or .priceContainer.productPrice
                const priceEl = node.querySelector('.Plugin_Price') || node.querySelector('.priceContainer.productPrice');
                const priceText = priceEl 
                    ? (priceEl.textContent || priceEl.innerText || '')
                    : (node.textContent || node.innerText || '');

                const parsed = parsePrice(priceText, 'CHF');
                if (parsed && mainSetNo) {
                    const retailer = mapRetailer(shopName, '');
                    // Offer redirect or product URL:
                    const link = node.querySelector('a[data-offer-href], a[href*="/shops/"], a[href*="/ext_"]');
                    let offerUrl = link ? (link.getAttribute('data-offer-href') || link.getAttribute('href') || '') : '';
                    if (offerUrl && offerUrl.startsWith('/')) {
                        offerUrl = 'https://www.toppreise.ch' + offerUrl;
                    }
                    if (!offerUrl) offerUrl = url;

                    items.push({
                        setNo: mainSetNo,
                        title: docTitle.split(' ab CHF')[0].trim(),
                        amountCents: parsed.amountCents,
                        price: parsed.price,
                        currency: parsed.currency,
                        retailer: retailer,
                        provider: 'toppreise',
                        url: offerUrl,
                        merchantName: shopName || 'Toppreise Merchant',
                        type: 'comparison_offer'
                    });
                }
            }
            return items;
        }

        // 2. Search Results / Overview Page (contains .Plugin_Product)
        const productNodes = doc.querySelectorAll('.Plugin_Product, [id^="Plugin_Product_"]');
        for (let j = 0; j < productNodes.length; j++) {
            const pNode = productNodes[j];
            const text = (pNode.textContent || pNode.innerText || '').trim();
            const setNo = extractSetNumber(text);

            const priceEl = pNode.querySelector('.Plugin_Price') || pNode.querySelector('.priceContainer.productPrice');
            const priceText = priceEl ? (priceEl.textContent || priceEl.innerText || '') : text;
            const parsed = parsePrice(priceText, 'CHF');

            let link = pNode.querySelector('a[href*="/preisvergleich/"]');
            if (!link && pNode.closest) {
                link = pNode.closest('a[href*="/preisvergleich/"]');
            }
            let href = link ? link.getAttribute('href') : '';
            if (href && href.startsWith('/')) {
                href = 'https://www.toppreise.ch' + href;
            }

            if (setNo && parsed) {
                items.push({
                    setNo: setNo,
                    title: text.split('CHF')[0].trim(),
                    amountCents: parsed.amountCents,
                    price: parsed.price,
                    currency: parsed.currency,
                    retailer: 'other',
                    provider: 'toppreise',
                    url: href || url,
                    type: 'search_result'
                });
            }
        }

        return items;
    }

    /**
     * Extract prices from Brack.ch:
     * - Single product page: extracts main set and price from JSON-LD / DOM.
     * - Search / Category overview: extracts all products from JSON-LD ItemList (48 sets!) or DOM cards.
     */
    function extractBrack(doc, url = '') {
        const items = [];
        const jsonLds = getJsonLdScripts(doc);

        // 1. Check for JSON-LD ItemList (Search / Category results)
        for (const ld of jsonLds) {
            const itemList = (ld['@type'] === 'ItemList' && ld.itemListElement) ? ld.itemListElement
                : (ld.mainEntity && ld.mainEntity['@type'] === 'ItemList' && ld.mainEntity.itemListElement) ? ld.mainEntity.itemListElement
                : null;

            if (Array.isArray(itemList)) {
                for (let i = 0; i < itemList.length; i++) {
                    const entry = itemList[i].item || itemList[i];
                    if (entry && (entry['@type'] === 'Product' || entry.offers)) {
                        const name = entry.name || '';
                        const setNo = entry.productID || extractSetNumber(name) || extractSetNumber(entry.url || '');
                        const off = Array.isArray(entry.offers) ? entry.offers[0] : entry.offers;
                        if (off && (off.price || off.lowPrice) && setNo) {
                            const parsed = parsePrice(String(off.price || off.lowPrice), off.priceCurrency || 'CHF');
                            if (parsed) {
                                let itemUrl = entry.url || '';
                                if (itemUrl && itemUrl.startsWith('/')) itemUrl = 'https://www.brack.ch' + itemUrl;
                                items.push({
                                    setNo: setNo,
                                    title: name,
                                    amountCents: parsed.amountCents,
                                    price: parsed.price,
                                    currency: parsed.currency,
                                    retailer: 'brack',
                                    provider: 'bookmarklet',
                                    url: itemUrl || url,
                                    type: 'search_result'
                                });
                            }
                        }
                    }
                }
            }
        }

        // 2. Check for Single Product JSON-LD
        for (const ld of jsonLds) {
            if (ld['@type'] === 'Product' && ld.offers) {
                const name = ld.name || '';
                const setNo = ld.productID || extractSetNumber(name) || extractSetNumber(url);
                const off = Array.isArray(ld.offers) ? ld.offers[0] : ld.offers;
                if (off && (off.price || off.lowPrice) && setNo) {
                    const parsed = parsePrice(String(off.price || off.lowPrice), off.priceCurrency || 'CHF');
                    if (parsed) {
                        let itemUrl = ld.url || url;
                        if (itemUrl.startsWith('/')) itemUrl = 'https://www.brack.ch' + itemUrl;
                        items.push({
                            setNo: setNo,
                            title: name,
                            amountCents: parsed.amountCents,
                            price: parsed.price,
                            currency: parsed.currency,
                            retailer: 'brack',
                            provider: 'bookmarklet',
                            url: itemUrl,
                            type: 'main'
                        });
                    }
                }
            }
        }

        // 3. Fallback: DOM selectors if JSON-LD was absent
        if (items.length === 0) {
            const titleEl = doc.querySelector('h1');
            const titleText = (titleEl ? (titleEl.textContent || titleEl.innerText) : '') || doc.title || '';
            const setNo = extractSetNumber(titleText) || extractSetNumber(url);
            const priceEl = doc.querySelector('[data-qa="product-detail-price"], .productPrice, span[class*="price"]');
            if (setNo && priceEl) {
                const parsed = parsePrice(priceEl.textContent || priceEl.innerText || '', 'CHF');
                if (parsed) {
                    items.push({
                        setNo: setNo,
                        title: titleText.trim(),
                        amountCents: parsed.amountCents,
                        price: parsed.price,
                        currency: parsed.currency,
                        retailer: 'brack',
                        provider: 'bookmarklet',
                        url: url,
                        type: 'main'
                    });
                }
            }
        }

        // 4. Related products, accessories, and recommendations in the DOM
        const relatedCards = doc.querySelectorAll('li[data-snippet], [data-e-ref="lsr"], [data-qa="product-item"], [data-qa*="product"], .c-product-item, [class*="productCard"], [class*="product-card"], [class*="product-item"]');
        for (let i = 0; i < relatedCards.length; i++) {
            const card = relatedCards[i];
            const link = card.querySelector('a[href*="/lego-"], a[href*="/product/"], a[href*="-"]');
            const href = link ? link.getAttribute('href') : '';
            const img = card.querySelector('img[alt], img[title]');
            const imgTitle = img ? (img.getAttribute('alt') || img.getAttribute('title') || '') : '';
            const titleEl = card.querySelector('[data-qa="product-item-title"], h3, h2, .productTitle, [class*="title"]') || link;
            const title = imgTitle || (titleEl ? (titleEl.textContent || titleEl.innerText || '').trim() : '');

            // On Brack, title like 'Technic Land Rover Defender 42110' has the set number while URL has trailing SKU like '-905561'
            const setNo = extractSetNumber(title) || extractSetNumber(href);
            const priceEl = card.querySelector('[data-qa*="price"], .productPrice, .price, span[class*="price"]');
            const priceText = priceEl ? (priceEl.textContent || priceEl.innerText || '') : (card.textContent.match(/\b([0-9]{1,4}\.[0-9]{2})\b/)?.[1] || '');
            const parsed = parsePrice(priceText, 'CHF');

            if (setNo && parsed) {
                let itemUrl = href || url;
                if (itemUrl.startsWith('/')) itemUrl = 'https://www.brack.ch' + itemUrl;
                items.push({
                    setNo: setNo,
                    title: title,
                    amountCents: parsed.amountCents,
                    price: parsed.price,
                    currency: parsed.currency,
                    retailer: 'brack',
                    provider: 'bookmarklet',
                    url: itemUrl,
                    type: 'similar'
                });
            }
        }

        return deduplicateItems(items);
    }

    /**
     * Extract prices from LEGO.com:
     * - Main Product: from JSON-LD Product or PDP header.
     * - "Similar Products" / Recommendations Carousel & Overview: all [data-test="product-leaf"] tiles.
     */
    function extractLego(doc, url = '') {
        const items = [];
        const jsonLds = getJsonLdScripts(doc);

        // 1. Main Product from JSON-LD
        for (const ld of jsonLds) {
            if (ld['@type'] === 'Product' && ld.offers) {
                const setNo = ld.productId || extractSetNumber(ld.name || '') || extractSetNumber(url);
                const off = Array.isArray(ld.offers) ? ld.offers[0] : ld.offers;
                if (off && off.price && setNo) {
                    const parsed = parsePrice(String(off.price), off.priceCurrency || 'CHF');
                    if (parsed) {
                        items.push({
                            setNo: setNo,
                            title: ld.name || '',
                            amountCents: parsed.amountCents,
                            price: parsed.price,
                            currency: parsed.currency,
                            retailer: 'lego_store',
                            provider: 'direct_crawl',
                            url: ld['@id'] || off.url || url,
                            type: 'main'
                        });
                    }
                }
            }
        }

        // 2. Similar Products / Recommendations / Overview Tiles [data-test="product-leaf"]
        const leaves = doc.querySelectorAll('[data-test="product-leaf"]');
        for (let i = 0; i < leaves.length; i++) {
            const leaf = leaves[i];
            const link = leaf.querySelector('a[href*="/product/"]');
            const titleEl = leaf.querySelector('[data-test="product-leaf-title"]');
            const priceEl = leaf.querySelector('[data-test="product-leaf-price"]');

            const href = link ? link.getAttribute('href') : '';
            const title = titleEl ? (titleEl.textContent || titleEl.innerText || '').replace(/\s+/g, ' ').trim() : '';
            const priceText = priceEl ? (priceEl.textContent || priceEl.innerText || '').trim() : '';

            const setNo = extractSetNumber(href) || extractSetNumber(title);
            const parsed = parsePrice(priceText, 'CHF');

            if (setNo && parsed) {
                let fullUrl = href;
                if (fullUrl && fullUrl.startsWith('/')) {
                    fullUrl = 'https://www.lego.com' + fullUrl;
                }
                items.push({
                    setNo: setNo,
                    title: title,
                    amountCents: parsed.amountCents,
                    price: parsed.price,
                    currency: parsed.currency,
                    retailer: 'lego_store',
                    provider: 'direct_crawl',
                    url: fullUrl || url,
                    type: (href && url && href.includes(extractSetNumber(url) || 'none')) ? 'main' : 'similar'
                });
            }
        }

        return items;
    }

    /**
     * Extract prices from Galaxus.ch / Digitec.ch:
     * - JSON-LD Product: extracts set number from offers.url, description, or title, and price.
     * - Also inspects rendered product cards.
     */
    function extractGalaxus(doc, url = '') {
        const items = [];
        const jsonLds = getJsonLdScripts(doc);

        for (const ld of jsonLds) {
            if (ld['@type'] === 'Product' && ld.offers) {
                const off = Array.isArray(ld.offers) ? ld.offers[0] : ld.offers;
                const offerUrl = (off && off.url) ? off.url : url;
                // Set number can be in offerUrl (...-40809-...), description ("(40809)"), or name:
                const setNo = extractSetNumber(offerUrl) || 
                              extractSetNumber(ld.description || '') || 
                              extractSetNumber(ld.name || '') || 
                              extractSetNumber(doc.title || '');

                if (off && (off.price || off.lowPrice) && setNo) {
                    const parsed = parsePrice(String(off.price || off.lowPrice), off.priceCurrency || 'CHF');
                    if (parsed) {
                        items.push({
                            setNo: setNo,
                            title: ld.name || doc.title || '',
                            amountCents: parsed.amountCents,
                            price: parsed.price,
                            currency: parsed.currency,
                            retailer: 'galaxus',
                            provider: 'bookmarklet',
                            url: offerUrl || url,
                            type: 'main'
                        });
                    }
                }
            }
        }

        // 2. DOM Fallback for main product
        if (items.length === 0) {
            const h1 = doc.querySelector('h1');
            const titleText = (h1 ? (h1.textContent || h1.innerText) : '') || doc.title || '';
            const setNo = extractSetNumber(titleText) || extractSetNumber(url);
            const priceEl = doc.querySelector('[data-test="product-price"], [data-test="price"], strong[class*="price"], span[class*="price"]');
            if (setNo && priceEl) {
                const parsed = parsePrice(priceEl.textContent || priceEl.innerText || '', 'CHF');
                if (parsed) {
                    items.push({
                        setNo: setNo,
                        title: titleText.trim(),
                        amountCents: parsed.amountCents,
                        price: parsed.price,
                        currency: parsed.currency,
                        retailer: 'galaxus',
                        provider: 'bookmarklet',
                        url: url,
                        type: 'main'
                    });
                }
            }
        }

        // 3. Related products, accessories, and recommendations in the DOM
        const relatedCards = doc.querySelectorAll('article, [data-test*="product-card"], [class*="productCard"], [data-test*="productTile"], [data-test="product"]');
        for (let i = 0; i < relatedCards.length; i++) {
            const card = relatedCards[i];
            const link = card.querySelector('a[href*="/product/"], a[href*="/s5/product/"]');
            const href = link ? link.getAttribute('href') : '';
            const img = card.querySelector('img[alt], img[title]');
            const imgTitle = img ? (img.getAttribute('alt') || img.getAttribute('title') || '').replace(/^Produktbild\s+/i, '') : '';
            const titleEl = card.querySelector('h3, h2, [class*="productName"], [class*="title"]') || link;
            const title = imgTitle || (titleEl ? (titleEl.textContent || titleEl.innerText || '').trim() : '');

            const setNo = extractSetNumber(title) || extractSetNumber(href);
            const priceEl = card.querySelector('[data-test="product-price"], [data-test="price"], [class*="productPrice"], strong[class*="price"], span[class*="price"]');
            const priceText = priceEl ? (priceEl.textContent || priceEl.innerText || '') : card.textContent;
            const parsed = parsePrice(priceText, 'CHF');

            if (setNo && parsed) {
                let itemUrl = href || url;
                if (itemUrl.startsWith('/')) itemUrl = 'https://www.galaxus.ch' + itemUrl;
                items.push({
                    setNo: setNo,
                    title: title,
                    amountCents: parsed.amountCents,
                    price: parsed.price,
                    currency: parsed.currency,
                    retailer: 'galaxus',
                    provider: 'bookmarklet',
                    url: itemUrl,
                    type: 'similar'
                });
            }
        }

        return deduplicateItems(items);
    }

    /**
     * Extract prices from Amazon (Amazon.de / Amazon.com):
     */
    function extractAmazon(doc, url = '') {
        const items = [];
        const isDe = url.includes('amazon.de') || (doc.location && doc.location.hostname && doc.location.hostname.includes('amazon.de'));
        const defaultCurrency = isDe ? 'EUR' : 'USD';
        const retailer = isDe ? 'amazon_de' : 'amazon_com';

        // 1. Main Product
        const titleEl = doc.querySelector('#productTitle, h1#title, #title') ||
                        (function() {
                            const h1s = doc.querySelectorAll('h1');
                            for (let i = 0; i < h1s.length; i++) {
                                const t = (h1s[i].textContent || h1s[i].innerText || '').trim();
                                if (extractSetNumber(t)) return h1s[i];
                            }
                            return null;
                        })() ||
                        doc.querySelector('h1');
        const titleText = (titleEl ? (titleEl.textContent || titleEl.innerText) : '') || doc.title || '';
        const setNo = extractSetNumber(titleText) || extractSetNumber(url);

        const priceEl = doc.querySelector('.a-price .a-offscreen, #corePrice_feature_div .a-offscreen, #corePriceDisplay_desktop_feature_div .a-offscreen, #apex_desktop .a-price .a-offscreen, .productPrice');
        if (setNo && priceEl) {
            const rawPrice = (priceEl.textContent || priceEl.innerText || '').trim();
            const parsed = parsePrice(rawPrice, defaultCurrency);
            if (parsed) {
                items.push({
                    setNo: setNo,
                    title: titleText.replace(/\s+/g, ' ').trim(),
                    amountCents: parsed.amountCents,
                    price: parsed.price,
                    currency: parsed.currency,
                    retailer: retailer,
                    provider: 'bookmarklet',
                    url: url,
                    type: 'main'
                });
            }
        }

        // 2. Similar products / Carousel / Search results
        const cards = doc.querySelectorAll('.a-carousel-card, [data-component-type="s-search-result"]');
        for (let i = 0; i < cards.length; i++) {
            const card = cards[i];

            // Title: check h2 first (standard on Amazon search results), then links
            const h2 = card.querySelector('h2');
            let cardTitle = h2 ? (h2.textContent || h2.innerText || '').trim() : '';

            const links = card.querySelectorAll('a[href*="/dp/"], a[href*="%2Fdp%2F"], a[href*="/gp/product/"], a.a-link-normal');
            let href = '';
            for (let j = 0; j < links.length; j++) {
                const linkHref = links[j].getAttribute('href') || '';
                if (linkHref.includes('/dp/') || linkHref.includes('%2Fdp%2F') || linkHref.includes('/gp/product/')) {
                    if (!href) href = linkHref;
                    if (!cardTitle) {
                        const candidate = (links[j].textContent || links[j].innerText || '').trim();
                        if (extractSetNumber(candidate)) {
                            cardTitle = candidate;
                            href = linkHref;
                            break;
                        }
                    }
                }
            }

            // Clean title of any embedded CSS block text if present
            if (cardTitle.includes('{') && cardTitle.includes('}')) {
                cardTitle = cardTitle.replace(/\{[^}]*\}/g, ' ').replace(/[^{}]*\{/g, ' ').trim();
            }

            const cardSet = extractSetNumber(cardTitle) || extractSetNumber(href);
            const cardPriceEl = card.querySelector('.a-price .a-offscreen, .a-color-price');
            if (cardSet && cardPriceEl) {
                const rawPrice = (cardPriceEl.textContent || cardPriceEl.innerText || '').trim();
                const parsed = parsePrice(rawPrice, defaultCurrency);
                if (parsed) {
                    if (href && href.startsWith('/')) {
                        href = (isDe ? 'https://www.amazon.de' : 'https://www.amazon.com') + href;
                    }
                    items.push({
                        setNo: cardSet,
                        title: cardTitle.replace(/\s+/g, ' ').trim(),
                        amountCents: parsed.amountCents,
                        price: parsed.price,
                        currency: parsed.currency,
                        retailer: retailer,
                        provider: 'bookmarklet',
                        url: href || url,
                        type: 'similar'
                    });
                }
            }
        }

        return deduplicateItems(items);
    }

    /**
     * Universal Dispatcher: Detects store and extracts all available price data.
     */
    function extractAll(doc, url = '') {
        const u = url.toLowerCase();
        let rawItems = [];

        if (u.includes('toppreise.ch')) {
            rawItems = extractToppreise(doc, url);
        } else if (u.includes('brack.ch')) {
            rawItems = extractBrack(doc, url);
        } else if (u.includes('lego.com')) {
            rawItems = extractLego(doc, url);
        } else if (u.includes('galaxus.ch') || u.includes('digitec.ch')) {
            rawItems = extractGalaxus(doc, url);
        } else if (u.includes('amazon.')) {
            rawItems = extractAmazon(doc, url);
        } else {
            // Generic fallback
            const brack = extractBrack(doc, url);
            if (brack.length > 0) rawItems = brack;
            else rawItems = extractGalaxus(doc, url);
        }

        // Deduplicate items by setNo + retailer + merchantName + amountCents
        const seen = new Set();
        const uniqueItems = [];
        for (const item of rawItems) {
            const key = item.setNo + ':' + item.retailer + ':' + (item.merchantName || '') + ':' + item.amountCents;
            if (!seen.has(key)) {
                seen.add(key);
                uniqueItems.push(item);
            }
        }

        return uniqueItems;
    }

    return {
        parsePrice,
        extractSetNumber,
        mapRetailer,
        extractToppreise,
        extractBrack,
        extractLego,
        extractGalaxus,
        extractAmazon,
        extractAll
    };
}));
