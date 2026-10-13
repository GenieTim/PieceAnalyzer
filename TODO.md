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

### Phase 9: Currency, Price History & Security Hardening (Completed)
- [x] Harden security firewall: Add `login_throttling` (5 attempts) to `main` firewall.
- [x] Protect administrative scraping and import endpoints (`/files`, `/price/brickpicker`) with `ROLE_ADMIN`.
- [x] Enforce CSRF protection on token regeneration (`BookmarkletController::regenerateToken`).
- [x] Prevent open redirects in `PreferenceController` by strictly validating host against `Request::getHost()`.
- [x] Restrict CORS in `PriceReportController` to known trusted retailer domains.
- [x] Persist user preferences (`preferredCurrency`, `preferredRetailers`) directly to `User` entity for authenticated users with fallback to session.
- [x] Secure `app:user:create` CLI password argument with interactive hidden prompt (`askHidden`).
- [x] Fix nominal cross-currency comparison and verification checks in `Set::getBestPrice()` (strictly require `VERIFIED` status).
- [x] Reset cached prices on `Set` to null when price collection is emptied (prevent zombie prices).
- [x] Fix `AppPricesReloadCommand` to record real currencies (EUR/USD/GBP) and correct provider (`BRICKSET` vs `BRICKPICKER`).
- [x] Add composite and foreign key database indexes on `SetPrice` (`submitted_by_id`, `currency`, `status`).
- [x] UI: Eliminate `Check price` dropdown clutter for anonymous users (show clean `—` unless logged in).
- [x] UI: Replace hardcoded `$` in Value Tier badges with dynamic active currency symbol.
- [x] UI: Replace hardcoded `¢` (cents) in Price/Pc column with proper minor unit symbol (`Rp.` for CHF, `ct` for EUR, `p` for GBP, `¢` for USD).
- [x] UI: Refine navbar currency switcher and Sign In button layout, resolving visual clashing and adding GBP option.
- [x] Implement Option B: "Bring Your Own Prices" (BYOP) & Verified Contributors:
  - Add `isVerifiedContributor` boolean flag on `User` entity (`--verified` option on `app:user:create`).
  - Auto-assign `VERIFIED` status for submissions from trusted contributors (`isVerifiedContributor` or `ROLE_ADMIN`); assign `UNVERIFIED` for normal contributors.
  - Correlated DQL ranking query builder in `SetRepository` applying personal unverified prices to the submitter without cartesian product distortion.
  - Prioritize personal user price in `ListController` and render `👤 Your price` badge in `list_all.html.twig`.
  - Comprehensive integration test suite covering verified auto-verification, unverified scoping, and personal ranking overrides.

---

## Phase 10: Price History Visualizations, Moderation Queue & User Management (Completed)

### 1. Interactive Price History UI & Visualizations
- [x] Dedicated modal and endpoint (`GET /item/{id}/prices`, `ListController::itemPrices`) triggered from the `{{ set.prices|length }}` badge. Supports both JSON and HTML modal partials with graceful fallback.
- [x] Render price trend charts (responsive inline SVG sparklines and line chart) showing retailer price history over time, data point markers, and dates.
- [x] Display lowest historical price badge, highest/MSRP baseline, and savings delta percentage and monetary savings.

### 2. Multi-Currency Exchange Rate Engine
- [x] Automated exchange rate service (`App\Service\ExchangeRateService`) with daily European Central Bank / Frankfurter API rates and built-in offline fallback.
- [x] MoneyPHP and decimal conversions across EUR, CHF, USD, and GBP.
- [x] Console inspection command `app:prices:rates` (`AppExchangeRatesCommand`) with `--refresh` flag.
- [x] Update `SetRepository::getMostValuableByQuery` with real-time normalized currency conversion in SQL for globally accurate sorting across mixed currencies.

### 3. User Profile & Administrative Management
- [x] User profile settings page (`/profile`, `ProfileController`, `templates/profile/index.html.twig`) to manage password, preferred currency, and favorite retailers in web UI.
- [x] Administrative user management tools:
  - `app:user:list` (`AppUserListCommand`): list users with roles, submission counts, and verification status.
  - `app:user:promote` (`AppUserPromoteCommand`): grant/revoke `ROLE_ADMIN` and toggle verified contributor status.
  - `app:user:delete` (`AppUserDeleteCommand`): safely remove users with interactive confirmation or `--force`.
- [ ] Self-service user registration with email verification.
- [ ] Password reset / forgot password flow (`symfonycasts/reset-password-bundle`).

### 4. Community Price Moderation Queue
- [x] Web dashboard for administrators (`/admin/prices/moderation`, `PriceModerationController`, `templates/admin/price_moderation.html.twig`) to view and filter unverified bookmarklet reports.
- [x] One-click approve/reject actions with CSRF protection, set cached price updates, and audit flash logs.
- [x] Auto-verification rules: auto-verifies matching unverified prices upon approval, plus consensus action (`/admin/prices/moderation/auto-verify-matching`) verifying reports confirmed by 2+ distinct contributors.
- [x] Navigation bar admin dropdown integration for quick access to moderation queue.

### 5. API Token Security Hardening
- [x] Token expiration timestamps (`api_token_expires_at`, `isApiTokenExpired()`) with rejection in `PriceReportController`.
- [x] Self-service token rotation and regeneration workflow in Profile and Bookmarklet settings.
- [ ] Store SHA-256 hashed API tokens in database rather than plaintext.

---
