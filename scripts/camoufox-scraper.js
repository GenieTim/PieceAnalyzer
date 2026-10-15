/**
 * Automated Cronjob Price Scraper (Camoufox / Playwright)
 *
 * Demonstrates shared architecture between the Browser Bookmarklet and Headless Scrapers:
 * Both utilize the exact same extractor engine (`assets/js/scraper/extractor.js`).
 *
 * Includes automated stepped scrolling to trigger lazy loading, IntersectionObservers,
 * and dynamic carousel hydration on modern SPAs (Galaxus, Brack, LEGO.com, etc.).
 *
 * Usage:
 *   node scripts/camoufox-scraper.js --url "https://www.toppreise.ch/preisvergleich/..." --token "YOUR_API_TOKEN"
 *   node scripts/camoufox-scraper.js --url "https://www.galaxus.ch/de/s5/product/..." --token "YOUR_API_TOKEN"
 *   node scripts/camoufox-scraper.js --url "https://www.brack.ch/search?query=lego" --token "YOUR_API_TOKEN"
 */

const fs = require('fs');
const path = require('path');
const extractor = require('../assets/js/scraper/extractor');

/**
 * Incrementally scrolls through the page to trigger lazy-loading,
 * IntersectionObservers, and dynamic carousel/recommendations hydration.
 *
 * Intermittently extracts items during the scroll to ensure virtualized lists
 * or unmounted items are captured, followed by a final settling pass and deduplication.
 */
async function scrollAndExtract(page, options = {}) {
    const {
        scroll = true,
        maxScrollSteps = 25,
        stepDistance = 650,
        stepDelayMs = 250,
        settleDelayMs = 1500,
        triggerCarousels = true
    } = options;

    // Inject shared extractor engine into browser tab
    const extractorPath = path.resolve(__dirname, '../assets/js/scraper/extractor.js');
    const extractorSource = fs.readFileSync(extractorPath, 'utf8');
    await page.addScriptTag({ content: extractorSource });

    if (!scroll) {
        console.log('[Scraper] Scrolling disabled, extracting current DOM state...');
        return await page.evaluate(() => {
            return window.LegoPriceExtractor.extractAll(document, window.location.href);
        });
    }

    console.log(`[Scraper] Scrolling page (max ${maxScrollSteps} steps, ${stepDistance}px step, ${stepDelayMs}ms delay)...`);

    // In-page stepped scroll with periodic extraction
    const collectedItems = await page.evaluate(async ({ maxScrollSteps, stepDistance, stepDelayMs, triggerCarousels }) => {
        const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));
        const allItems = [];

        function collect() {
            if (window.LegoPriceExtractor && typeof window.LegoPriceExtractor.extractAll === 'function') {
                try {
                    const current = window.LegoPriceExtractor.extractAll(document, window.location.href);
                    for (let i = 0; i < current.length; i++) {
                        allItems.push(current[i]);
                    }
                } catch (e) {
                    console.error('Error during scroll extraction:', e);
                }
            }
        }

        // 1. Initial collection at current view
        collect();

        // 2. Stepped downward scroll
        let lastScrollTop = -1;
        let consecutiveBottomCount = 0;

        for (let step = 0; step < maxScrollSteps; step++) {
            window.scrollBy({ top: stepDistance, left: 0, behavior: 'smooth' });
            await sleep(stepDelayMs);

            const scrollTop = window.scrollY || document.documentElement.scrollTop || 0;
            const scrollHeight = document.documentElement.scrollHeight || document.body.scrollHeight || 0;
            const clientHeight = window.innerHeight || document.documentElement.clientHeight || 0;

            // Harvest newly rendered products
            collect();

            // Detect page bottom
            const isAtBottom = (scrollTop + clientHeight >= scrollHeight - 60) || (scrollTop === lastScrollTop);
            if (isAtBottom) {
                consecutiveBottomCount++;
                if (consecutiveBottomCount >= 2) {
                    // Confirmed at page bottom with no further growth
                    break;
                }
            } else {
                consecutiveBottomCount = 0;
            }

            lastScrollTop = scrollTop;
        }

        // 3. Optional: Trigger next/right arrows on carousels to hydrate more slides
        if (triggerCarousels) {
            const nextButtons = document.querySelectorAll(
                'button[aria-label*="next" i], button[aria-label*="weiter" i], button[aria-label*="suivant" i], ' +
                '[data-test*="carousel-next"], [data-test*="slider-next"], button[class*="carouselNext" i], button[class*="arrowRight" i]'
            );
            for (let i = 0; i < nextButtons.length; i++) {
                try {
                    const btn = nextButtons[i];
                    if (btn.offsetParent !== null) { // Visible
                        btn.click();
                    }
                } catch (e) {
                    // Ignore click errors on dynamic elements
                }
            }
            if (nextButtons.length > 0) {
                await sleep(500);
                collect();
            }
        }

        return allItems;
    }, { maxScrollSteps, stepDistance, stepDelayMs, triggerCarousels });

    // Settling pause for any final network fetches / state updates triggered near bottom
    if (settleDelayMs > 0) {
        await new Promise(resolve => setTimeout(resolve, settleDelayMs));
    }

    // Final sweep pass to ensure everything currently in the DOM is captured
    const finalSweep = await page.evaluate(() => {
        return window.LegoPriceExtractor.extractAll(document, window.location.href);
    });

    // Merge all collected items and deduplicate
    const merged = [...collectedItems, ...finalSweep];
    const seen = new Set();
    const uniqueItems = [];

    for (const item of merged) {
        const key = item.setNo + ':' + item.retailer + ':' + (item.merchantName || '') + ':' + item.amountCents;
        if (!seen.has(key)) {
            seen.add(key);
            uniqueItems.push(item);
        }
    }

    return uniqueItems;
}

/**
 * Main scraper runner for headless Camoufox or Playwright
 */
async function scrapeWithCamoufoxOrPlaywright(targetUrl, apiUrl, apiToken, scraperOptions = {}) {
    console.log(`[Scraper] Starting scrape for: ${targetUrl}`);

    // Try loading camoufox (Firefox-based anti-detect) or standard playwright
    let browserType;
    let isCamoufox = false;
    try {
        const { camoufox } = require('camoufox-js');
        browserType = camoufox;
        isCamoufox = true;
        console.log('[Scraper] Using Camoufox stealth browser engine');
    } catch {
        try {
            const { firefox, chromium } = require('playwright');
            browserType = firefox || chromium;
            console.log('[Scraper] Using Playwright browser engine');
        } catch (e) {
            console.error('[Scraper] Neither camoufox-js nor playwright is installed. Run: npm install playwright or npm install camoufox-js');
            throw e;
        }
    }

    const isHeadless = scraperOptions.headful !== true;
    const browser = isCamoufox && typeof browserType.launch !== 'function' && typeof browserType === 'function'
        ? await browserType({ headless: isHeadless })
        : await browserType.launch({
            headless: isHeadless,
            args: ['--disable-blink-features=AutomationControlled']
        });

    const context = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        locale: 'de-CH',
        viewport: { width: 1440, height: 900 }
    });

    const page = await context.newPage();

    try {
        await page.goto(targetUrl, { timeout: 45000, waitUntil: 'domcontentloaded' });
        // Initial pause to allow initial client hydration
        await new Promise(resolve => setTimeout(resolve, 2000));

        // Perform scrolling and extraction
        const extractedItems = await scrollAndExtract(page, scraperOptions);

        console.log(`[Scraper] Successfully extracted ${extractedItems.length} unique prices!`);
        for (const item of extractedItems.slice(0, 10)) {
            console.log(`  - Set #${item.setNo}: ${item.price} ${item.currency} (${item.retailer} via ${item.provider})`);
        }
        if (extractedItems.length > 10) {
            console.log(`  ... and ${extractedItems.length - 10} more items`);
        }

        // Submit extracted batch to Piece Analyzer API
        if (apiUrl && apiToken && extractedItems.length > 0) {
            console.log(`[Scraper] Submitting batch to Piece Analyzer API: ${apiUrl}`);
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${apiToken}`
                },
                body: JSON.stringify({
                    items: extractedItems,
                    token: apiToken
                })
            });

            const result = await response.json();
            console.log('[Scraper] API Result:', result);
        }

        return extractedItems;
    } finally {
        await browser.close();
    }
}

// CLI argument runner
if (require.main === module) {
    const args = process.argv.slice(2);
    let targetUrl = '';
    let apiUrl = process.env.PIECE_ANALYZER_API_URL || 'http://localhost/api/prices/report';
    let apiToken = process.env.PIECE_ANALYZER_API_TOKEN || '';
    let outputJson = false;
    const scraperOptions = {
        scroll: true,
        maxScrollSteps: 25,
        stepDistance: 650,
        stepDelayMs: 250,
        settleDelayMs: 1500,
        triggerCarousels: true,
        headful: false
    };

    for (let i = 0; i < args.length; i++) {
        if (args[i] === '--url' && args[i + 1]) targetUrl = args[++i];
        else if (args[i] === '--api' && args[i + 1]) apiUrl = args[++i];
        else if (args[i] === '--token' && args[i + 1]) apiToken = args[++i];
        else if (args[i] === '--json') outputJson = true;
        else if (args[i] === '--no-scroll') scraperOptions.scroll = false;
        else if (args[i] === '--max-steps' && args[i + 1]) scraperOptions.maxScrollSteps = parseInt(args[++i], 10);
        else if (args[i] === '--step-distance' && args[i + 1]) scraperOptions.stepDistance = parseInt(args[++i], 10);
        else if (args[i] === '--step-delay' && args[i + 1]) scraperOptions.stepDelayMs = parseInt(args[++i], 10);
        else if (args[i] === '--settle-delay' && args[i + 1]) scraperOptions.settleDelayMs = parseInt(args[++i], 10);
        else if (args[i] === '--no-carousels') scraperOptions.triggerCarousels = false;
        else if (args[i] === '--headful') scraperOptions.headful = true;
    }

    if (!targetUrl) {
        console.log('Usage: node scripts/camoufox-scraper.js --url <URL> [--api <API_URL>] [--token <API_TOKEN>] [--json] [options]');
        console.log('Options:');
        console.log('  --json                Output extracted items as raw JSON to stdout');
        console.log('  --no-scroll           Disable stepped scrolling');
        console.log('  --max-steps <N>       Maximum scroll steps (default: 25)');
        console.log('  --step-distance <PX>  Pixels per scroll step (default: 650)');
        console.log('  --step-delay <MS>     Delay between steps (default: 250)');
        console.log('  --settle-delay <MS>   Settling delay after reaching bottom (default: 1500)');
        console.log('  --no-carousels        Disable triggering carousel navigation arrows');
        console.log('  --headful             Run browser in non-headless mode for visual inspection');
        process.exit(0);
    }

    // When outputting JSON to stdout, mute stdout console logs by routing to stderr
    if (outputJson) {
        console.log = (...a) => console.error(...a);
    }

    scrapeWithCamoufoxOrPlaywright(targetUrl, apiUrl, apiToken, scraperOptions)
        .then((items) => {
            if (outputJson) {
                process.stdout.write(JSON.stringify(items || []));
            }
            process.exit(0);
        })
        .catch(err => {
            console.error('[Scraper] Fatal error:', err.message);
            process.exit(1);
        });
}

module.exports = {
    scrollAndExtract,
    scrapeWithCamoufoxOrPlaywright
};
