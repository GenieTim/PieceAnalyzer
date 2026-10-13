const { chromium } = require('playwright');

async function testStores() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--disable-blink-features=AutomationControlled']
    });
    const context = await browser.newContext({
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        locale: 'de-CH'
    });

    const page = await context.newPage();

    console.log('--- 1. Testing Galaxus.ch ---');
    try {
        await page.goto('https://www.galaxus.ch/en/search?q=lego+10311', { timeout: 25000, waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);
        const galaxusTitle = await page.title();
        console.log('Galaxus Page Title:', galaxusTitle);

        // Find product card / link
        const firstProduct = await page.$('article a[href*="/product/"], a[href*="/product/"]');
        if (firstProduct) {
            const productHref = await firstProduct.getAttribute('href');
            console.log('Found Galaxus product link:', productHref);
            const fullUrl = productHref.startsWith('http') ? productHref : 'https://www.galaxus.ch' + productHref;
            await page.goto(fullUrl, { timeout: 25000, waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(3000);

            const detailTitle = await page.title();
            console.log('Galaxus Product Detail Title:', detailTitle);

            // Test extraction logic
            const extracted = await page.evaluate(() => {
                const titleText = (document.title || '') + ' ' + (document.querySelector('h1')?.innerText || '');
                const setMatch = titleText.match(/\b(?:LEGO|Set)?\s*([1-9]\d{3,5})\b/i);
                
                // Price selectors on Galaxus
                const priceSelectors = [
                    '[data-test="product-price"]',
                    '[data-test="price"]',
                    'strong[class*="price"]',
                    'span[class*="price"]',
                    'button[data-test="orderButton"]',
                    'div[class*="productPrice"]'
                ];
                let priceText = '';
                for (const sel of priceSelectors) {
                    const el = document.querySelector(sel);
                    if (el && el.innerText) {
                        priceText = el.innerText;
                        break;
                    }
                }
                return {
                    titleText,
                    setNo: setMatch ? setMatch[1] : null,
                    priceText
                };
            });
            console.log('Galaxus Extracted Data:', extracted);
        } else {
            console.log('No direct product link on search, checking search page text');
        }
    } catch (e) {
        console.log('Galaxus error:', e.message);
    }

    console.log('\n--- 2. Testing Brack.ch ---');
    try {
        await page.goto('https://www.brack.ch/search?query=lego+10311', { timeout: 25000, waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);
        const brackTitle = await page.title();
        console.log('Brack Page Title:', brackTitle);

        const brackProduct = await page.$('a[href*="/lego-"], a[data-qa="product-item"]');
        if (brackProduct) {
            const href = await brackProduct.getAttribute('href');
            const fullUrl = href.startsWith('http') ? href : 'https://www.brack.ch' + href;
            console.log('Found Brack product link:', fullUrl);
            await page.goto(fullUrl, { timeout: 25000, waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(3000);

            const extracted = await page.evaluate(() => {
                const titleText = (document.title || '') + ' ' + (document.querySelector('h1')?.innerText || '');
                const setMatch = titleText.match(/\b(?:LEGO|Set)?\s*([1-9]\d{3,5})\b/i);
                const priceSelectors = [
                    '[data-qa="product-detail-price"]',
                    '.productPrice',
                    '.price',
                    'span[class*="price"]'
                ];
                let priceText = '';
                for (const sel of priceSelectors) {
                    const el = document.querySelector(sel);
                    if (el && el.innerText) {
                        priceText = el.innerText;
                        break;
                    }
                }
                return {
                    titleText,
                    setNo: setMatch ? setMatch[1] : null,
                    priceText
                };
            });
            console.log('Brack Extracted Data:', extracted);
        }
    } catch (e) {
        console.log('Brack error:', e.message);
    }

    console.log('\n--- 3. Testing Amazon.de ---');
    try {
        await page.goto('https://www.amazon.de/s?k=lego+10311', { timeout: 25000, waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);
        console.log('Amazon.de Title:', await page.title());

        const amazonProduct = await page.$('h2 a[href*="/dp/"]');
        if (amazonProduct) {
            const href = await amazonProduct.getAttribute('href');
            const fullUrl = href.startsWith('http') ? href : 'https://www.amazon.de' + href;
            console.log('Found Amazon product link:', fullUrl);
            await page.goto(fullUrl, { timeout: 25000, waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(3000);

            const extracted = await page.evaluate(() => {
                const titleText = (document.title || '') + ' ' + (document.querySelector('h1')?.innerText || '');
                const setMatch = titleText.match(/\b(?:LEGO|Set)?\s*([1-9]\d{3,5})\b/i);
                const priceEl = document.querySelector('.a-price .a-offscreen, #corePrice_feature_div .a-offscreen, #corePriceDisplay_desktop_feature_div .a-offscreen');
                return {
                    titleText,
                    setNo: setMatch ? setMatch[1] : null,
                    priceText: priceEl ? priceEl.innerText : ''
                };
            });
            console.log('Amazon Extracted Data:', extracted);
        }
    } catch (e) {
        console.log('Amazon error:', e.message);
    }

    await browser.close();
}

testStores();
