#!/usr/bin/env python3
"""
Automated Cronjob Price Scraper (Python Camoufox / Playwright)

Demonstrates shared architecture between the Browser Bookmarklet and Headless Scrapers:
Both utilize the exact same extractor engine (`assets/js/scraper/extractor.js`).

Includes stepped scrolling to trigger lazy loading, IntersectionObservers,
and dynamic carousel hydration on modern SPAs (Galaxus, Brack, LEGO.com, etc.).

Requirements:
  pip install camoufox playwright requests
  camoufox fetch

Usage:
  python scripts/camoufox_scraper.py --url "https://www.galaxus.ch/de/s5/product/..." --token "YOUR_API_TOKEN"
  python scripts/camoufox_scraper.py --url "https://www.brack.ch/search?query=lego" --token "YOUR_API_TOKEN"
"""

import sys
import os
import time
import json
import argparse
from pathlib import Path

EXTRACTOR_PATH = Path(__file__).parent.parent / "assets" / "js" / "scraper" / "extractor.js"

SCROLL_SCRIPT = """
async ({ maxScrollSteps, stepDistance, stepDelayMs, triggerCarousels }) => {
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

    // 1. Initial collection
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

        collect();

        const isAtBottom = (scrollTop + clientHeight >= scrollHeight - 60) || (scrollTop === lastScrollTop);
        if (isAtBottom) {
            consecutiveBottomCount++;
            if (consecutiveBottomCount >= 2) {
                break;
            }
        } else {
            consecutiveBottomCount = 0;
        }

        lastScrollTop = scrollTop;
    }

    // 3. Trigger next carousel buttons if present
    if (triggerCarousels) {
        const nextButtons = document.querySelectorAll(
            'button[aria-label*="next" i], button[aria-label*="weiter" i], button[aria-label*="suivant" i], ' +
            '[data-test*="carousel-next"], [data-test*="slider-next"], button[class*="carouselNext" i], button[class*="arrowRight" i]'
        );
        for (let i = 0; i < nextButtons.length; i++) {
            try {
                const btn = nextButtons[i];
                if (btn.offsetParent !== null) {
                    btn.click();
                }
            } catch (e) {}
        }
        if (nextButtons.length > 0) {
            await sleep(500);
            collect();
        }
    }

    return allItems;
}
"""

def get_browser_context():
    """Attempt to import Camoufox (anti-detect Firefox), fallback to Playwright."""
    try:
        from camoufox.sync_api import Camoufox
        print("[Scraper] Using Camoufox stealth browser engine")
        return Camoufox(headless=True)
    except ImportError:
        try:
            from playwright.sync_api import sync_playwright
            print("[Scraper] Using Playwright browser engine")
            p = sync_playwright().start()
            browser = p.firefox.launch(headless=True)
            return browser
        except ImportError:
            print("[Scraper] Error: Neither camoufox nor playwright is installed. Install via: pip install camoufox playwright")
            sys.exit(1)


def scrape_url(target_url, api_url=None, api_token=None, options=None):
    if options is None:
        options = {}

    scroll = options.get("scroll", True)
    max_steps = options.get("max_steps", 25)
    step_distance = options.get("step_distance", 650)
    step_delay_ms = options.get("step_delay_ms", 250)
    settle_delay_ms = options.get("settle_delay_ms", 1500)
    trigger_carousels = options.get("trigger_carousels", True)

    if not EXTRACTOR_PATH.exists():
        raise FileNotFoundError(f"Extractor script not found at: {EXTRACTOR_PATH}")

    extractor_source = EXTRACTOR_PATH.read_text(encoding="utf-8")

    print(f"[Scraper] Starting scrape for: {target_url}")

    # Launch browser
    try:
        from camoufox.sync_api import Camoufox
        with Camoufox(headless=options.get("headless", True), locale="de-CH") as page:
            page.goto(target_url, timeout=45000, wait_until="domcontentloaded")
            time.sleep(2.0)
            page.add_script_tag(content=extractor_source)

            if scroll:
                print(f"[Scraper] Scrolling page (max {max_steps} steps, {step_distance}px step)...")
                collected = page.evaluate(SCROLL_SCRIPT, {
                    "maxScrollSteps": max_steps,
                    "stepDistance": step_distance,
                    "stepDelayMs": step_delay_ms,
                    "triggerCarousels": trigger_carousels
                })
                time.sleep(settle_delay_ms / 1000.0)
                final_sweep = page.evaluate("() => window.LegoPriceExtractor.extractAll(document, window.location.href)")
                all_items = collected + final_sweep
            else:
                all_items = page.evaluate("() => window.LegoPriceExtractor.extractAll(document, window.location.href)")

            # Deduplicate
            seen = set()
            unique_items = []
            for item in all_items:
                key = f"{item.get('setNo')}:{item.get('retailer')}:{item.get('merchantName', '')}:{item.get('amountCents')}"
                if key not in seen:
                    seen.add(key)
                    unique_items.append(item)

            print(f"[Scraper] Successfully extracted {len(unique_items)} unique prices!")
            for it in unique_items[:10]:
                print(f"  - Set #{it.get('setNo')}: {it.get('price')} {it.get('currency')} ({it.get('retailer')})")

            # Submit to API if configured
            if api_url and api_token and unique_items:
                import urllib.request
                print(f"[Scraper] Submitting batch to Piece Analyzer API: {api_url}")
                payload = json.dumps({"items": unique_items, "token": api_token}).encode("utf-8")
                req = urllib.request.Request(
                    api_url,
                    data=payload,
                    headers={
                        "Content-Type": "application/json",
                        "Authorization": f"Bearer {api_token}"
                    },
                    method="POST"
                )
                with urllib.request.urlopen(req) as resp:
                    res_body = resp.read().decode("utf-8")
                    print(f"[Scraper] API Result: {res_body}")

            return unique_items
    except ImportError:
        # Fallback to standard playwright
        from playwright.sync_api import sync_playwright
        with sync_playwright() as p:
            browser = p.firefox.launch(headless=options.get("headless", True))
            page = browser.new_page(locale="de-CH")
            page.goto(target_url, timeout=45000, wait_until="domcontentloaded")
            time.sleep(2.0)
            page.add_script_tag(content=extractor_source)

            if scroll:
                print(f"[Scraper] Scrolling page (max {max_steps} steps, {step_distance}px step)...")
                collected = page.evaluate(SCROLL_SCRIPT, {
                    "maxScrollSteps": max_steps,
                    "stepDistance": step_distance,
                    "stepDelayMs": step_delay_ms,
                    "triggerCarousels": trigger_carousels
                })
                time.sleep(settle_delay_ms / 1000.0)
                final_sweep = page.evaluate("() => window.LegoPriceExtractor.extractAll(document, window.location.href)")
                all_items = collected + final_sweep
            else:
                all_items = page.evaluate("() => window.LegoPriceExtractor.extractAll(document, window.location.href)")

            # Deduplicate
            seen = set()
            unique_items = []
            for item in all_items:
                key = f"{item.get('setNo')}:{item.get('retailer')}:{item.get('merchantName', '')}:{item.get('amountCents')}"
                if key not in seen:
                    seen.add(key)
                    unique_items.append(item)

            print(f"[Scraper] Successfully extracted {len(unique_items)} unique prices!")
            browser.close()
            return unique_items


def main():
    parser = argparse.ArgumentParser(description="Camoufox / Playwright Price Scraper")
    parser.add_argument("--url", required=True, help="Target URL to scrape")
    parser.add_argument("--api", default=os.getenv("PIECE_ANALYZER_API_URL", "http://localhost/api/prices/report"), help="API report endpoint")
    parser.add_argument("--token", default=os.getenv("PIECE_ANALYZER_API_TOKEN", ""), help="API Bearer token")
    parser.add_argument("--json", action="store_true", help="Output extracted items as raw JSON to stdout")
    parser.add_argument("--no-scroll", action="store_true", help="Disable stepped scrolling")
    parser.add_argument("--max-steps", type=int, default=25, help="Max scroll steps")
    parser.add_argument("--step-distance", type=int, default=650, help="Step distance in pixels")
    parser.add_argument("--step-delay", type=int, default=250, help="Delay between scroll steps in ms")
    parser.add_argument("--settle-delay", type=int, default=1500, help="Settle delay after reaching bottom in ms")
    parser.add_argument("--no-carousels", action="store_true", help="Disable triggering carousel arrows")
    parser.add_argument("--headful", action="store_true", help="Run in visible browser mode")

    args = parser.parse_args()
    items = scrape_url(
        args.url,
        api_url=args.api if not args.json else None,
        api_token=args.token if not args.json else None,
        options={
            "scroll": not args.no_scroll,
            "max_steps": args.max_steps,
            "step_distance": args.step_distance,
            "step_delay_ms": args.step_delay,
            "settle_delay_ms": args.settle_delay,
            "trigger_carousels": not args.no_carousels,
            "headless": not args.headful
        }
    )
    if args.json:
        sys.stdout.write(json.dumps(items or []))


if __name__ == "__main__":
    main()
