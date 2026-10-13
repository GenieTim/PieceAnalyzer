# Price Fetching & Multi-Source Overhaul TODO

## Objective
Transition the piece-analyzer price system from synchronous single-source scraping to a multi-source, asynchronous, Swiss-friendly architecture (inspired by toppreise.ch):
- Support multiple price sources with price history per set.
- Distinguish between data providers (`PriceSourceProvider`) and merchants/retailers (`Retailer`).
- Support Swiss retailers (`Galaxus.ch`, `Brack.ch`) and international retailers (`Amazon.de`, `Amazon.com`, `Lego Store`).
- Accurate monetary calculations using `moneyphp/money`.
- Decouple price fetching from set listings (sets should be listed even if unpriced).
- Asynchronous queuing using Symfony Messenger (Doctrine transport) and Docker/Coolify worker service.
- Reliable server-side sources (Brickset, BrickPicker) loaded via daily cron command (`app:prices:reload`).
- Client-side data collection via a browser Bookmarklet for anti-bot / captcha protected stores (Galaxus, Brack, Amazon).
- Secure reporting endpoint (`POST /api/prices/report`) with bounds validation, URL validation, and rate limiting.
- Session-based user preferences (currency: CHF/EUR/USD, preferred sources).

---

## Phases & Tasks

### Phase 1: Core Domain & Data Model
- [x] Install `moneyphp/money` dependency in `composer.json`.
- [x] Create backed enums:
  - `App\Enum\PriceSourceProvider` (`toppreise`, `brickset`, `brickpicker`, `bookmarklet`, `manual`, `direct_crawl`)
  - `App\Enum\Retailer` (`galaxus`, `brack`, `amazon_de`, `amazon_com`, `lego_store`, `other`)
  - `App\Enum\PriceVerificationStatus` (`verified`, `reported_unverified`, `rejected`)
- [x] Create `App\Entity\SetPrice` entity:
  - Links to `Set` (`ManyToOne`, cascade delete)
  - Provider enum, Retailer enum, Status enum
  - `amount` (int, cents/rappen) and `currency` (string) with `moneyphp/money` accessors (`getMoney()`, `setMoney()`)
  - `url` (?string)
  - `recordedAt` (`DateTimeImmutable`)
- [x] Update `App\Entity\Set`:
  - `prices` (`OneToMany` collection of `SetPrice`)
  - Helper methods: `addPrice()`, `getPrices()`, `getLatestPriceFor(Retailer $retailer)`, `getBestPrice(?string $currency = null)`
  - Keep cached `price` float and add `priceCurrency` for fast SQL ordering and backward compatibility.
- [x] Unit tests for `SetPrice`, `Set` price relations, and Money operations (`SetPriceTest`).

### Phase 2: Decouple Price Loading from Set Listing
- [x] Update `App\Repository\SetRepository`:
  - Remove `s.price IS NOT NULL` and `s.price != 0` filter in `getMostValuableQueryBuilder`.
  - Order sets with null prices last (`CASE WHEN s.price IS NULL OR s.price = 0 THEN 1 ELSE 0 END, pricePerPiece ASC`).
- [x] Update `templates/list/list_all.html.twig`:
  - Display "Check price" dropdown with direct retailer search links for unpriced sets instead of hiding them.
  - Display recorded price source count badge (`set.prices|length`).
  - Gracefully render unit cost per piece (shows `—` when unpriced).
- [x] Verify pagination and filtering with unpriced sets.

### Phase 3: Symfony Messenger & Queue Architecture
- [x] Add `symfony/messenger` and configure Doctrine transport (`doctrine://default`).
- [x] Create message `App\Message\FetchSetPriceMessage(int $setId, ?PriceSourceProvider $provider = null)`.
- [x] Create handler `App\MessageHandler\FetchSetPriceMessageHandler`.
- [x] Update `docker-compose.yml`:
  - Add `worker` service running `php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M`.
  - Add `AUTO_DB_INIT: 0` on worker to prevent database migration race conditions.

### Phase 4: Multi-Source Provider Architecture & Command Refactor
- [x] Update `FetchSetPriceMessageHandler` to load multi-currency prices from Brickset and BrickPicker and persist `SetPrice` records.
- [x] Update `AppPricesReloadCommand` (`app:prices:reload`):
  - Support `--async` option to dispatch messages to Symfony Messenger.
  - Record timestamped `SetPrice` history entries in database when running reload.

### Phase 5: Client-Side Reporting API & Bookmarklet
- [x] Create `App\Controller\Api\PriceReportController` (`POST /api/prices/report`):
  - Validate set existence (handles `-1` suffix seamlessly), retailer enum, positive amount, valid URL.
  - Validate sanity bounds against set piece count to prevent fraudulent reports.
  - Handle CORS preflight (`OPTIONS`) and headers for external retailer origins (Galaxus, Brack, Amazon).
  - Persist `SetPrice` with `PriceVerificationStatus::UNVERIFIED` and `PriceSourceProvider::USER_BOOKMARKLET`.
- [x] Build `/bookmarklet` view & generator (`BookmarkletController` and `templates/bookmarklet/index.html.twig`):
  - Draggable bookmarklet link with embedded cross-origin fetch script.
  - Parser logic for `galaxus.ch` / `digitec.ch`, `brack.ch`, `amazon.de`, `amazon.com`.
  - Added Bookmarklet entry to main navigation bar.

### Phase 7: Authentication, Rate Limiting & Rogue Protection
- [x] Create `App\Entity\User` implementing Symfony `UserInterface` & `PasswordAuthenticatedUserInterface`.
  - Store contributor API token (`apiToken`) for bookmarklet authorization.
- [x] Configure Symfony Security (`security.yaml`):
  - Form login (`/login`), logout (`/logout`), password hasher.
  - Require `ROLE_USER` for `/bookmarklet` generator.
- [x] Create console command `app:user:create` (`AppUserCreateCommand`) for creating users and generating API tokens.
- [x] Install `symfony/rate-limiter` and configure sliding window (10 requests / 1 min) for `/api/prices/report`.
- [x] Link reported prices to `submittedBy` user.
- [x] Create rogue purge command `app:prices:purge-user` (`AppPricesPurgeUserCommand`) to wipe data and recalculate cached best prices if a contributor goes rogue.
- [x] Add user account navbar controls (Sign In / Logout / User profile pill).

### Phase 8: Verification & Coolify Deployment
- [x] Live store verification with Playwright (verified Akamai/Kasada edge blocks confirming bookmarklet requirement).
- [x] Full PHPUnit test suite passing (65 tests, 290 assertions).
- [x] Full PHPStan level 5 static analysis passing (0 errors).
- [x] Deploy to Coolify and verify worker service configuration.

---

