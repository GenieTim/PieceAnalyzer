const test = require('node:test');
const assert = require('node:assert/strict');
const { loadFixture } = require('./dom-adapter');
const {
    parsePrice,
    extractSetNumber,
    mapRetailer,
    extractToppreise,
    extractBrack,
    extractLego,
    extractGalaxus,
    extractAmazon,
    extractAll
} = require('../../assets/js/scraper/extractor');

test('parsePrice handles various currency and number formats', () => {
    assert.deepEqual(parsePrice('CHF 32.05'), { price: 32.05, amountCents: 3205, currency: 'CHF' });
    assert.deepEqual(parsePrice('ab CHF 24.19'), { price: 24.19, amountCents: 2419, currency: 'CHF' });
    assert.deepEqual(parsePrice('179,00 CHF'), { price: 179.00, amountCents: 17900, currency: 'CHF' });
    assert.deepEqual(parsePrice('CHF 179.-'), { price: 179.00, amountCents: 17900, currency: 'CHF' });
    assert.deepEqual(parsePrice('€149,99'), { price: 149.99, amountCents: 14999, currency: 'EUR' });
    assert.deepEqual(parsePrice('$199.99'), { price: 199.99, amountCents: 19999, currency: 'USD' });
    assert.deepEqual(parsePrice(28.9, 'CHF'), { price: 28.90, amountCents: 2890, currency: 'CHF' });
    assert.equal(parsePrice(''), null);
});

test('extractSetNumber finds 4-6 digit LEGO set numbers', () => {
    assert.equal(extractSetNumber('LEGO Weihnachtliches Lebkuchenhaus (40809) ab CHF 32.05'), '40809');
    assert.equal(extractSetNumber('Porsche 911 10295 | LEGO® Icons'), '10295');
    assert.equal(extractSetNumber('LEGO® Technic Koenigsegg Sadair\'s Spear Hypercar 42232'), '42232');
    assert.equal(extractSetNumber('https://www.lego.com/de-ch/product/porsche-911-10295'), '10295');
    assert.equal(extractSetNumber('/lego-weihnachtliches-lebkuchenhaus-40809-lego-iconic-lego-65234203'), '40809');
    assert.equal(extractSetNumber('https://www.lego.com/de-ch/product/advent-calendar-2026-60510'), '60510');
    assert.equal(extractSetNumber('Technic Ford GT 2022 42154'), '42154');
    assert.equal(extractSetNumber('Icons Naturhistorisches Museum 10326'), '10326');
});

test('Toppreise.ch: Single Set page extracts all 24 merchant sources and prices', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/toppreise.ch/one_set.html');
    const items = extractToppreise(doc, 'https://www.toppreise.ch/preisvergleich/Lego-Sonstige/LEGO-Weihnachtliches-Lebkuchenhaus-40809-p818086');

    assert.equal(items.length, 24, 'Must extract all 24 merchant sources from the Toppreise comparison page');
    assert.ok(items.every(it => it.setNo === '40809'), 'All extracted items should link to set #40809');
    assert.ok(items.every(it => it.provider === 'toppreise'), 'All items should specify toppreise as provider');
    assert.ok(items.every(it => it.currency === 'CHF'), 'All items should have CHF currency');

    // Verify known merchants mapped correctly
    const galaxus = items.find(it => it.retailer === 'galaxus');
    assert.ok(galaxus, 'Galaxus offer should be detected and mapped to Retailer::GALAXUS');
    assert.equal(galaxus.amountCents, 2890);
    assert.equal(galaxus.price, 28.90);

    const brack = items.find(it => it.retailer === 'brack');
    assert.ok(brack, 'Brack offer should be detected and mapped to Retailer::BRACK');
    assert.equal(brack.amountCents, 2960);
    assert.equal(brack.price, 29.60);

    const lego = items.find(it => it.retailer === 'lego_store');
    assert.ok(lego, 'LEGO Shop offer should be detected and mapped to Retailer::LEGO_STORE');
    assert.equal(lego.amountCents, 4490);
    assert.equal(lego.price, 44.90);

    const alternate = items.find(it => it.merchantName?.toLowerCase().includes('alternate'));
    assert.ok(alternate, 'Alternate offer should be detected');
    assert.equal(alternate.amountCents, 2419);
    assert.equal(alternate.price, 24.19);
});

test('Toppreise.ch: Search results page extracts 25 overview sets with best prices', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/toppreise.ch/search_results.html');
    const items = extractToppreise(doc, 'https://www.toppreise.ch/s?q=lego');

    assert.equal(items.length, 25, 'Must extract all 25 search result sets');
    assert.ok(items.some(it => it.setNo === '40809' && it.amountCents === 3205));
    assert.ok(items.some(it => it.setNo === '42172' && it.amountCents === 26530));
    assert.ok(items.some(it => it.setNo === '42232' && it.amountCents === 29057));
    assert.ok(items.some(it => it.setNo === '11387' && it.amountCents === 11900));
    assert.ok(items.some(it => it.setNo === '10307' && it.amountCents === 61410));
});

test('Brack.ch: Single Set page extracts main product 42232 and 20 related sets from the DOM', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/brack.ch/one_set.html');
    const items = extractBrack(doc, 'https://www.brack.ch/lego-technic-koenigsegg-sadairs-spear-hypercar-42232-2112722');

    assert.equal(items.length, 21, 'Main set 42232 + 20 related sets in recommendations carousel');
    
    // Main set
    const main = items.find(it => it.setNo === '42232');
    assert.ok(main, 'Main set 42232 must be present');
    assert.equal(main.amountCents, 37200);
    assert.equal(main.price, 372.00);
    assert.equal(main.retailer, 'brack');
    assert.equal(main.currency, 'CHF');

    // Related sets
    assert.ok(items.some(it => it.setNo === '10326' && it.amountCents === 24800));
    assert.ok(items.some(it => it.setNo === '42152' && it.amountCents === 9000));
    assert.ok(items.some(it => it.setNo === '42172' && it.amountCents === 33900));
    assert.ok(items.some(it => it.setNo === '42110' && it.amountCents === 28900));
    assert.ok(items.some(it => it.setNo === '42239' && it.amountCents === 13440));
    assert.ok(items.some(it => it.setNo === '42214' && it.amountCents === 13425));
    assert.ok(items.some(it => it.setNo === '8110' && it.amountCents === 37400));
    assert.ok(items.some(it => it.setNo === '11504' && it.amountCents === 4000));
});

test('Brack.ch: Search results page extracts 47 sets with 4-6 digit set numbers from JSON-LD ItemList in bulk', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/brack.ch/search_results.html');
    const items = extractBrack(doc, 'https://www.brack.ch/search?query=lego');

    assert.equal(items.length, 47, 'Must extract all 47 numbered catalog sets from JSON-LD ItemList');
    assert.ok(items.every(it => it.retailer === 'brack'));
    assert.ok(items.every(it => it.currency === 'CHF'));

    // Check key sets in the overview
    assert.ok(items.some(it => it.setNo === '42232' && it.amountCents === 37200));
    assert.ok(items.some(it => it.setNo === '10307' && it.amountCents === 64900));
    assert.ok(items.some(it => it.setNo === '10318' && it.amountCents === 18300));
    assert.ok(items.some(it => it.setNo === '10311' && it.amountCents === 3705));
    assert.ok(items.some(it => it.setNo === '76405' && it.amountCents === 50900));
    assert.ok(items.some(it => it.setNo === '40809' && it.amountCents === 2960));
});

test('LEGO.com: Single Set page extracts main set 10295 and 20 similar products from recommendations carousel', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/lego.com/one_set.html');
    const items = extractLego(doc, 'https://www.lego.com/de-ch/product/porsche-911-10295');

    // Main product + 20 similar product leaves in carousel
    assert.ok(items.length >= 20, `Expected at least 20 items, got ${items.length}`);
    
    // Main product: Porsche 911
    const main = items.find(it => it.setNo === '10295');
    assert.ok(main, 'Main set #10295 must be extracted');
    assert.equal(main.amountCents, 17900);
    assert.equal(main.price, 179.00);
    assert.equal(main.retailer, 'lego_store');

    // Similar sets in carousel
    assert.ok(items.some(it => it.setNo === '76355' && it.amountCents === 23900), 'Batmobil 76355 extracted');
    assert.ok(items.some(it => it.setNo === '42232' && it.amountCents === 49900), 'Koenigsegg 42232 extracted');
    assert.ok(items.some(it => it.setNo === '11381' && it.amountCents === 15900), 'Jaguar E-Type 11381 extracted');
    assert.ok(items.some(it => it.setNo === '10300' && it.amountCents === 20900), 'Time Machine 10300 extracted');
    assert.ok(items.some(it => it.setNo === '42172' && it.amountCents === 47900), 'McLaren P1 42172 extracted');
    assert.ok(items.some(it => it.setNo === '77263' && it.amountCents === 3290), 'BMW M3 77263 extracted');
});

test('LEGO.com: Home page extracts product leaf tiles across featured carousels', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/lego.com/home.html');
    const items = extractLego(doc, 'https://www.lego.com/de-ch');

    assert.equal(items.length, 40, 'Must extract all 40 product leaf tiles across featured carousels');
    assert.ok(items.some(it => it.setNo === '11387' && it.amountCents === 11900));
    assert.ok(items.some(it => it.setNo === '40874' && it.amountCents === 6990));
    assert.ok(items.some(it => it.setNo === '72306' && it.amountCents === 17900));
    assert.ok(items.some(it => it.setNo === '21333' && it.amountCents === 17900));
    assert.ok(items.some(it => it.setNo === '21330' && it.amountCents === 32900));
    assert.ok(items.some(it => it.setNo === '60510' && it.amountCents === 2990), 'City Adventskalender 60510 correctly identified');
});

test('LEGO.com: Search results page extracts 22 catalog sets with prices and Swiss Francs (CHF)', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/lego.com/search_results.htm');
    const items = extractLego(doc, 'https://www.lego.com/de-ch/search?q=lego');

    assert.equal(items.length, 22, 'Must extract all 22 priced sets from the hydrated search results');
    assert.ok(items.every(it => it.retailer === 'lego_store'));
    assert.ok(items.every(it => it.currency === 'CHF'));

    assert.ok(items.some(it => it.setNo === '42232' && it.amountCents === 49900));
    assert.ok(items.some(it => it.setNo === '42173' && it.amountCents === 5990));
    assert.ok(items.some(it => it.setNo === '10295' && it.amountCents === 17900));
    assert.ok(items.some(it => it.setNo === '10337' && it.amountCents === 18900));
    assert.ok(items.some(it => it.setNo === '77262' && it.amountCents === 3290));
});

test('Galaxus.ch: Single Set page extracts main set #40809 and 18 related products from recommendations', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/galaxus.ch/one_set.html');
    const items = extractGalaxus(doc, 'https://www.galaxus.ch/de/s5/product/lego-weihnachtliches-lebkuchenhaus-40809-lego-iconic-lego-65234203');

    assert.equal(items.length, 19, 'Main set 40809 + 18 related sets from recommendations');
    
    // Main set
    const main = items.find(it => it.setNo === '40809');
    assert.ok(main, 'Main set 40809 must be present');
    assert.equal(main.amountCents, 2890);
    assert.equal(main.price, 28.90);
    assert.equal(main.retailer, 'galaxus');
    assert.equal(main.currency, 'CHF');

    // Related sets
    assert.ok(items.some(it => it.setNo === '40825' && it.amountCents === 2690));
    assert.ok(items.some(it => it.setNo === '40812' && it.amountCents === 900));
    assert.ok(items.some(it => it.setNo === '40820' && it.amountCents === 4200));
    assert.ok(items.some(it => it.setNo === '60510' && it.amountCents === 1990));
    assert.ok(items.some(it => it.setNo === '10311' && it.amountCents === 3390));
    assert.ok(items.some(it => it.setNo === '10281' && it.amountCents === 3350));
});

test('Galaxus.ch: Search page extracts 31 catalog items from the hydrated search DOM', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/galaxus.ch/lego_search.html');
    const items = extractGalaxus(doc, 'https://www.galaxus.ch/de/s5/producttype/lego-277');

    assert.equal(items.length, 31, 'Must extract all 31 product cards from the hydrated Galaxus search page');
    assert.ok(items.every(it => it.retailer === 'galaxus'));
    assert.ok(items.every(it => it.currency === 'CHF'));

    assert.ok(items.some(it => it.setNo === '11380' && it.amountCents === 9000));
    assert.ok(items.some(it => it.setNo === '43020' && it.amountCents === 13100));
    assert.ok(items.some(it => it.setNo === '11377' && it.amountCents === 61500));
    assert.ok(items.some(it => it.setNo === '72154' && it.amountCents === 18000));
    assert.ok(items.some(it => it.setNo === '10311' && it.amountCents === 3390));
});

test('Amazon.de: Single Product page extracts main product 31389 and carousel sets', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/amazon.de/one_product.html');
    const items = extractAmazon(doc, 'https://www.amazon.de/dp/B0DJ26QG3W');

    assert.ok(items.length >= 18, 'Must extract main product + carousel recommendations');
    const main = items.find(it => it.setNo === '31389');
    assert.ok(main, 'Main set 31389 must be present');
    assert.equal(main.amountCents, 5476);
    assert.equal(main.price, 54.76);
    assert.equal(main.currency, 'CHF');
    assert.equal(main.retailer, 'amazon_de');

    // Verify recommendations
    assert.ok(items.some(it => it.setNo === '43305' && it.amountCents === 3019));
    assert.ok(items.some(it => it.setNo === '40811' && it.amountCents === 1092));
    assert.ok(items.some(it => it.setNo === '31381' && it.amountCents === 1943));
});

test('Amazon.de: Search results page extracts 50+ sets with prices and currencies', () => {
    const doc = loadFixture('tests/fixtures/bookmarklet/html/amazon.de/search.html');
    const items = extractAmazon(doc, 'https://www.amazon.de/s?k=lego');

    assert.ok(items.length >= 50, 'Must extract at least 50 product cards from the Amazon search results');
    assert.ok(items.every(it => it.retailer === 'amazon_de'));

    assert.ok(items.some(it => it.setNo === '72154' && it.amountCents === 16564));
    assert.ok(items.some(it => it.setNo === '11379' && it.amountCents === 8704));
    assert.ok(items.some(it => it.setNo === '76345' && it.amountCents === 3380));
    assert.ok(items.some(it => it.setNo === '42232' && it.amountCents === 28988));
});

test('Universal extractAll dispatcher correctly routes and deduplicates', () => {
    const toppreiseDoc = loadFixture('tests/fixtures/bookmarklet/html/toppreise.ch/one_set.html');
    const toppreiseItems = extractAll(toppreiseDoc, 'https://www.toppreise.ch/preisvergleich/Lego/set-40809');
    assert.equal(toppreiseItems.length, 24);

    const toppreiseSearchDoc = loadFixture('tests/fixtures/bookmarklet/html/toppreise.ch/search_results.html');
    const toppreiseSearchItems = extractAll(toppreiseSearchDoc, 'https://www.toppreise.ch/s?q=lego');
    assert.equal(toppreiseSearchItems.length, 25);

    const brackSearchDoc = loadFixture('tests/fixtures/bookmarklet/html/brack.ch/search_results.html');
    const brackSearchItems = extractAll(brackSearchDoc, 'https://www.brack.ch/search?query=lego');
    assert.equal(brackSearchItems.length, 47);

    const brackOneSetDoc = loadFixture('tests/fixtures/bookmarklet/html/brack.ch/one_set.html');
    const brackOneSetItems = extractAll(brackOneSetDoc, 'https://www.brack.ch/lego-technic-koenigsegg-sadairs-spear-hypercar-42232-2112722');
    assert.equal(brackOneSetItems.length, 21);

    const galaxusOneSetDoc = loadFixture('tests/fixtures/bookmarklet/html/galaxus.ch/one_set.html');
    const galaxusOneSetItems = extractAll(galaxusOneSetDoc, 'https://www.galaxus.ch/de/s5/product/lego-weihnachtliches-lebkuchenhaus-40809-lego-iconic-lego-65234203');
    assert.equal(galaxusOneSetItems.length, 19);

    const galaxusSearchDoc = loadFixture('tests/fixtures/bookmarklet/html/galaxus.ch/lego_search.html');
    const galaxusSearchItems = extractAll(galaxusSearchDoc, 'https://www.galaxus.ch/de/s5/producttype/lego-277');
    assert.equal(galaxusSearchItems.length, 31);

    const legoDoc = loadFixture('tests/fixtures/bookmarklet/html/lego.com/one_set.html');
    const legoItems = extractAll(legoDoc, 'https://www.lego.com/de-ch/product/porsche-911-10295');
    assert.equal(legoItems.length, 20); // 20 unique sets

    const legoSearchDoc = loadFixture('tests/fixtures/bookmarklet/html/lego.com/search_results.htm');
    const legoSearchItems = extractAll(legoSearchDoc, 'https://www.lego.com/de-ch/search?q=lego');
    assert.equal(legoSearchItems.length, 22);

    const amazonOneDoc = loadFixture('tests/fixtures/bookmarklet/html/amazon.de/one_product.html');
    const amazonOneItems = extractAll(amazonOneDoc, 'https://www.amazon.de/dp/B0DJ26QG3W');
    assert.ok(amazonOneItems.length >= 18);

    const amazonSearchDoc = loadFixture('tests/fixtures/bookmarklet/html/amazon.de/search.html');
    const amazonSearchItems = extractAll(amazonSearchDoc, 'https://www.amazon.de/s?k=lego');
    assert.ok(amazonSearchItems.length >= 50);
});
