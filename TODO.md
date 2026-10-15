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
- [x] Self-service user registration with email verification (`RegistrationController`, `RegistrationFormType`, `UserChecker`).
- [ ] Password reset / forgot password flow (`symfonycasts/reset-password-bundle`).

### 4. Community Price Moderation Queue
- [x] Web dashboard for administrators (`/admin/prices/moderation`, `PriceModerationController`, `templates/admin/price_moderation.html.twig`) to view and filter unverified bookmarklet reports.
- [x] One-click approve/reject actions with CSRF protection, set cached price updates, and audit flash logs.
- [x] Auto-verification rules: auto-verifies matching unverified prices upon approval, plus consensus action (`/admin/prices/moderation/auto-verify-matching`) verifying reports confirmed by 2+ distinct contributors.
- [x] Navigation bar admin dropdown integration for quick access to moderation queue.

### 5. API Token Security Hardening
- [x] Token expiration timestamps (`api_token_expires_at`, `isApiTokenExpired()`) with rejection in `PriceReportController`.
- [x] Self-service token rotation and regeneration workflow in Profile and Bookmarklet settings.

---

## Phase 11: Release Year Filtering, Security Hardening & Modern Symfony Refactoring (Completed)

### 1. Release Year Filtering
- [x] Filter for release year (`minYear`) in `FilterFormType` (number input, min 1949, placeholder e.g. 2020) so buyers can exclude vintage sets from the 90s that can barely be bought anymore.
- [x] Doctrine DQL filtering in `SetRepository` supporting `minYear` (`s.year >= :minYearDate`) and `maxYear` (`s.year <= :maxYearDate`), accounting for `Set::$year` Date column storage format.
- [x] Controller binding in `ListController` supporting both form submission and direct query parameter (`?minYear=2020`).
- [x] UI filter form layout: responsive 12-column grid in `templates/form/filter_form.html.twig` with active filter indicator pill and reset option in `templates/list/list_all.html.twig`.
- [x] Test coverage in `FilterFormTypeTest`, `SetRepositoryPartFilterTest`, and `ListControllerTest`.

### 2. Security Hardening
- [x] `PriceReportController`: Fix Stored XSS vulnerability by enforcing strictly `http` and `https` URL schemes.
- [x] `PriceReportController`: Fix retailer domain spoofing in `isHostMatching()` (verifying exact host or subdomain match rather than suffix match).
- [x] `PriceReportController`: Add upper price sanity bound (max 10,000,000 cents / 100,000 CHF/EUR/USD) to reject absurd prices.
- [x] `LoadController`: Protect administrative `/range` import action with `#[IsGranted('ROLE_ADMIN')]` and update `security.yaml` access control.
- [x] `RegistrationController`: Protect `/resend-verification` against CSRF attacks with token validation and CSRF token input in `resend_verification.html.twig`.
- [x] `PreferenceController`: Harden referer validation against open redirects (`/\evil.com` and protocol-relative redirect bypasses).
- [x] `AppUserPromoteCommand`: Re-index `$roles` array via `array_values()` to prevent JSON array serialization issues when revoking roles.

### 3. DRY Principles & Modern Symfony Updates
- [x] `Currency` backed Enum: centralize currency symbols, minor units, labels, and supported currency list in `App\Enum\Currency`.
- [x] Deduplicate currency presentation across `AppExtension` and Twig templates (`minor_unit()` Twig function).
- [x] Deduplicate price extremum calculations in `Set` entity via reusable `findExtremumPrice()` helper method.
- [x] Add `ItemRepository::findByNo()` helper method.
- [x] Fix PHP 8.4 `fgetcsv()` deprecation in `CsvLegoLoaderService` by setting `$escape = ''`.
- [x] Uncomment `failure_transport: failed` in `messenger.yaml` to ensure failed messages are captured in dead letter queue.
- [x] Centralize external provider resilience: Implement `ProviderResilienceManager` and `CircuitBreakerState` with unit test suite covering circuit breaker (403 block threshold), AIMD adaptive pacing delay, and 429 backoff handling.

---

## Phase 12: Architectural Improvements & Long-Term Backlog

### 1. Security & Authentication
- [ ] Store SHA-256 hashed API tokens in database (`api_token_hash`) rather than plaintext tokens, providing one-time display on generation.
- [ ] Store hashed email verification tokens (`email_verification_token_hash`) with short-lived expiration timestamps.
- [ ] Refactor API authentication to use Symfony `AccessTokenHandlerInterface` and firewall authenticator rather than manual `Authorization: Bearer` extraction in `PriceReportController`.
- [x] Moderation consensus rule: Require that auto-verifying consensus reports come from verified contributors (`isVerifiedContributor=true`) rather than arbitrary accounts to prevent Sybil attacks.

### 2. Performance & Database Optimization
- [ ] Eliminate Set Explorer N+1 queries by caching `pieceCount` integer column directly on `lego_set` table (updated during CSV import) instead of lazy-loading the `pieces` collection.
- [ ] Eager load prices for the current page of sets in `ListController` using `LEFT JOIN FETCH` or batch relation loading.
- [x] Wire `ProviderResilienceManager` into `BricksetPriceLoaderService` and `BrickPickerPriceLoaderService` via `RateLimitRetryTrait` and constructor injection.

### 3. Modern Symfony & Architecture
- [ ] Convert `PriceReportController` request payload parsing to Symfony `#[MapRequestPayload]` with a typed DTO and Symfony Validator constraints.
- [ ] Refactor `RegistrationController` inline HTML string concatenation to modern Twig `TemplatedEmail` templates.
- [ ] Convert `ListController` filter criteria handling into a typed `SetFilterCriteria` DTO.

---

## Phase 13: Comprehensive Codebase Review Roadmap (October 2026 Review)

### Completed Immediate Implementations
- [x] **Test Isolation & Integrity**: Configured dedicated test database `var/test.db` in `phpunit.xml.dist` and `.env.test`, auto-initialized via `tests/bootstrap.php`, eliminating test runs mutating the dev database.
- [x] **Security: Host Header Hardening**: Hardened default `TRUSTED_HOSTS` in `docker-compose.yaml` to prevent verification token hijacking.
- [x] **Security: Role-Based Controller Hardening**: Added explicit `#[IsGranted('ROLE_ADMIN')]` to `LoadController::refresh` and `loadPrices`, and `#[IsGranted('ROLE_USER')]` to `BookmarkletController`.
- [x] **Security: Unverified Contributor Rejection**: Enforced `isEmailVerified()` check in `PriceReportController` (returns 403 Forbidden).
- [x] **Security: CSRF & API Protection**: Disallowed session-only auth without Bearer tokens on `POST /api/prices/report` and added IP-based rate limiting for unauthenticated/failed requests.
- [x] **Security: Reverse Tabnabbing / Phishing Defense**: Added `rel="noopener noreferrer"` to all external outbound merchant and retailer links.
- [x] **Security: Directory Access Restriction**: Hardened `.htaccess` rules denying direct web access to `var/`, `data/`, and `tests/`.
- [x] **Performance: Database Composite Indexes**: Added composite indexes to `SetPrice` (dedup `['set_id', 'retailer', 'currency', 'amount']`, moderation `['status', 'retailer', 'recorded_at']`, user `['submitted_by_id', 'status']`) and `Piece` (`['set_id', 'color', 'category']`).
- [x] **Performance: Moderation Count Aggregation**: Replaced 3 separate count queries in `PriceModerationController` with a single grouped query.
- [x] **Performance: Webpack Encore Production Cache**: Enabled `cache: true` in `config/packages/prod/webpack_encore.yaml`.
- [x] **Performance: Messenger Async Retries**: Configured exponential backoff retry strategy in `messenger.yaml`.
- [x] **Test Comprehensiveness**: Added unit test suites for `User` entity (`UserTest`), `Retailer` and `Currency` enums (`RetailerTest`, `CurrencyTest`), `UserChecker` (`UserCheckerTest`), price calculations on `Set` (`SetTest`), and resolved ambiguous test assertions.
- [x] **Code Quality & Tooling**: Removed obsolete files (`bin/.phpunit`, `config/reference.php`, `nbproject/`, `src/Migrations/`, duplicate `docker-compose.yml`), fixed PSR-12 violations with PHPCS (0 errors remaining), and validated PHPStan level 8 (0 errors across 64 files).

---

### Architectural & Major Backlog

#### 1. Architecture & Domain Decoupling
- [ ] **Extract Price Domain Services**:
  - Create `App\Service\PriceIngestionService` to centralize price validation, bounds checking, 24-hour deduplication, and persistence across `PriceReportController`, `FetchSetPriceMessageHandler`, and scrapers.
  - Create `App\Service\PriceModerationService` to manage status changes, consensus matching, and set best-price cache invalidation.
- [ ] **Decouple Financial Calculations from `Set` Entity**:
  - Extract `findExtremumPrice()`, `getHistoricalLowestPrice()`, `getHistoricalHighestPrice()`, and `getHistoricalSavingsDelta()` into a dedicated domain service (`App\Service\SetPriceCalculator` or extend `ExchangeRateService`).
  - Remove hardcoded exchange rates (`EUR => 0.96`, etc.) and service parameter dependencies from `src/Entity/Set.php`.
- [ ] **Migrate to Versioned Doctrine Migrations**:
  - Generate baseline migration (`doctrine:migrations:diff`).
  - Update `docker/docker-entrypoint.sh` to run `doctrine:migrations:migrate --no-interaction` instead of hazardous `doctrine:schema:update --force`.
- [x] **Clean Up Legacy Scraper Services**:
  - Remove deprecated `BricklinkLegoLoaderService` and unused BrickLink consumer keys/secrets from `services.yaml` and `.env.example`.
- [ ] **Upgrade PHPStan to 2.x**:
  - Upgrade `phpstan/phpstan` to `^2.2` and configure `objectManagerLoader` for full entity inference with `phpstan-doctrine`.

#### 2. Efficiency & Scalability
- [ ] **Persistent `piece_count` Column (Eliminate 50 N+1 Queries)**:
  - Add integer column `lego_set.piece_count` (`Set::$pieceCount`) populated directly during CSV sync from `sets.csv` (`num_parts`).
  - Provide recalculation console command (`app:data:recalculate-piece-counts`).
  - Avoid lazy-loading the entire `$pieces` collection on `/filter` Set Explorer listing.
- [ ] **Batch Loading of Prices in Set Explorer**:
  - Execute a single batch DQL query (`SELECT sp FROM App\Entity\SetPrice sp WHERE sp.set IN (:activePageIds)`) after KnpPaginator resolves active IDs, reducing SQL queries per page from 102+ to 2–3.
- [ ] **Memory Optimization in `CsvLegoLoaderService`**:
  - Replace `findAll()` / `findBy(['price' => null])` in `loadPrices()` with cursor iteration (`$query->toIterable()`) with batch flush and clear every 50–100 records.
  - Prevent detached entity exceptions by caching integer entity IDs rather than entity references across `$em->clear()`.
- [ ] **Distributed Cache for `ProviderResilienceManager`**:
  - Replace in-memory array state with Symfony Cache (`cache.app` / Redis / filesystem) so circuit breaker trips and AIMD pacing delays are shared across web requests and CLI messenger workers.
- [ ] **Cache Static CSV Choice Lookups**:
  - Memoize parsed categories and colors in `FilterFormType` and `ListController` using `cache.app` (invalidated on CSV sync).
- [ ] **Decouple Synchronous Console Commands from Web Requests**:
  - Convert `LoadController::refresh` (`/files`) and `loadPrices` (`/price/brickpicker`) to dispatch asynchronous Messenger tasks rather than blocking HTTP execution.

#### 3. Security Hardening
- [ ] **Token Hashing & Expiry Overhaul**:
  - Store SHA-256 hashed API tokens (`hash('sha256', $token)`) in database.
  - Implement Symfony `AccessTokenHandlerInterface` for native firewall authentication on `/api/prices/report`.
- [x] **Abuse Prevention & Rate Limiting**:
  - Add rate limiting on `/register` and `/resend-verification` (e.g. max 3 requests / 5 minutes per IP) to prevent mailer spam.
- [ ] **Content Security Policy (CSP) Hardening**:
  - Eliminate `'unsafe-inline'` from `script-src` in `nelmio_security.yaml` by migrating the remaining 4 inline `onclick` handlers in bookmarklet and profile templates to Stimulus/assets.
- [x] **Custom Branded Error Templates**:
  - Implement `templates/bundles/TwigBundle/Exception/error404.html.twig` and `error500.html.twig`.

#### 4. Test Comprehensiveness & Quality
- [ ] **Install `dama/doctrine-test-bundle`**:
  - Wrap every integration test in an automatic database transaction rollback.
  - Remove destructive `cleanDatabase()` raw DQL purges from `ItemPricesControllerTest`, `SetDetailControllerTest`, `PriceModerationControllerTest`, `ProfileControllerTest`, and `AppUserAdminCommandsTest`.
- [x] **Moderation Consensus Test Suite**:
  - Add integration tests for `POST /admin/prices/moderation/auto-verify-matching` (testing >= 2 verified contributor consensus, duplicate submitter rejection, and cache updates).
- [x] **Reporting API Edge Case Suite**:
  - Add tests for batch limits (> 100 items), empty payloads, and lower sanity bounds rejection (< 2.00 CHF on large sets).
- [ ] **`CsvLegoLoaderService` Pipeline Test Suite**:
  - Add integration tests for `loadSets()`, `loadPieces()`, and inventory reconciliation using mock CSV fixtures in `tests/fixtures/csv/`.
- [ ] **High-Fidelity Integration Tests for `SetRepository`**:
  - Replace mock-everything tests in `SetRepositoryTest` with real query execution against the test database, validating multi-currency sorting and part filtering.

