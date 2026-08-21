# Piece Analyzer

![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)
![Symfony 7.4 / 8.0](https://img.shields.io/badge/Symfony-7.4%20%2F%208.0%20ready-black.svg)
![CI](https://github.com/GenieTim/PieceAnalyzer/actions/workflows/ci.yml/badge.svg)
![PHPUnit](https://img.shields.io/badge/Tests-42%20Passing-brightgreen.svg)
![PHPStan](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)

Piece Analyzer is a Symfony application designed to help LEGO builders and AFOLs find the best sets to buy when targeting specific pieces. Given a desired piece (by part number, category, or color), Piece Analyzer calculates piece-to-price ratios and ranks sets so you can get the most target pieces at the lowest unit cost.

---

## Features & Capabilities

- **Piece Value Ranking**: Calculates $\frac{\text{Set Price}}{\sum \text{Target Pieces}}$ to find the highest density and lowest price per piece.
- **Filtering & Search**: Filter sets by piece color, part category, or type.
- **Automated Data Ingestion**:
  - **Rebrickable CSV Downloader**: Automatically downloads and decompresses daily public CSV database dumps (`sets.csv.gz`, `parts.csv.gz`, `inventories.csv.gz`, `inventory_parts.csv.gz`, `colors.csv.gz`, `themes.csv.gz`, `part_categories.csv.gz`) via `php bin/console app:data:download-csv`.
  - **Rebrickable REST API v3**: Live piece inventory lookup, element images, and set details via official REST API.
  - **Brickset Price Loader**: Multi-currency official MSRP / RRP (EUR, USD, GBP, CAD) via Brickset API v3 and automated public web scraping fallback.
  - **BrickLink API v1**: Authenticated OAuth 1.0 client for secondary market listings, 6-month sales averages, and subsets.
  - **BrickPicker Price Loader**: Retail and secondary market pricing crawler.
- **Modern PHP 8.2+ Architecture**: Strict typing, PHP 8 attributes (Routing, Doctrine ORM), PHPStan Level 8 clean, Rector modernization, and PHPUnit 11 test suite.

---

## LEGO Data Sources & API Guide

To make Piece Analyzer as effective as possible, the application integrates multiple data sources:

| Source | Best Used For | Format / Protocol | License / Terms |
| :--- | :--- | :--- | :--- |
| **[Rebrickable](https://rebrickable.com/downloads/)** | Complete set inventories, part mappings, element IDs, colors | Daily CSV database dumps (gzip) & REST API v3 | **CC BY 4.0** (Attribution required). Free bulk downloads updated daily. |
| **[Brickset API v3](https://brickset.com/tools/webservices/v3)** | Official MSRP / RRP (USD, EUR, GBP), launch/exit years, piece counts | REST API (JSON / SOAP) with API Key + userHash | Free non-commercial API key. |
| **[BrickLink API v1](https://www.bricklink.com/v2/api/register_consumer.page)** | Secondary market market prices, 6-month sales averages, part-out value | REST API (OAuth 1.0a HMAC-SHA1) | Free developer API keys (per registered store/account). 5,000 calls/day. |
| **[BrickOwl API](https://www.brickowl.com/api_docs)** | Secondary market lots, catalog downloads | REST API + Open Catalog CSVs | Open catalog for personal/commercial use. |
| **[Keepa API / CamelCamelCamel](https://keepa.com/)** | Amazon retail discounts & historical price tracking | REST API (Paid tier for high volume) | Commercial tracking of retail discounts below MSRP. |

### Valuation Models

- **Gross Cost Ratio**:
  $$\text{Unit Cost} = \frac{\text{Set Price}}{\text{Quantity of Target Piece in Set}}$$
- **Net Residual Cost (Part-Out Deduction)**:
  $$\text{Net Unit Cost} = \frac{\text{Set Price} - \sum (\text{Non-Target Parts} \times \text{Market Value})}{\text{Quantity of Target Piece in Set}}$$

---

## Installation & Setup

### Prerequisites

- PHP >= 8.2 (compatible with PHP 8.2, 8.3, 8.4, and 8.5)
- Composer 2.x
- MySQL, MariaDB, PostgreSQL, or SQLite database
- (Optional) Yarn / npm for asset compilation

### 1. Clone and Install Dependencies

```bash
git clone https://github.com/GenieTim/PieceAnalyzer.git
cd PieceAnalyzer
composer install
```

### 2. Environment Configuration

Copy `.env` to `.env.local` and configure your database connection, data directory, and API keys:

```bash
cp .env .env.local
```

Edit `.env.local`:
```dotenv
DATABASE_URL="mysql://db_user:db_password@127.0.0.1:3306/piece_analyzer?serverVersion=8.0"
DATA_PATH="/path/to/piece-analyzer/data"
BASE_URL="http://localhost:8000"

# Optional API Keys for live fetching:
REBRICKABLE_API_KEY=""
BRICKSET_API_KEY=""
```

### 3. Database Initialization

```bash
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
```

### 4. Automated Data Download & Import

```bash
# 1. Download latest daily CSV dumps directly from Rebrickable CDN
php bin/console app:data:download-csv

# 2. Import sets and pieces into the database
php bin/console app:data:import-csv

# 3. Reload set pricing (from Brickset / BrickPicker)
php bin/console app:prices:reload

# 4. Clean any duplicate records
php bin/console app:data:remove-duplicates
```

### 5. Start Local Server

```bash
symfony server:start
# or
php -S 127.0.0.1:8000 -t public/
```

---

## Quality Assurance & Testing

Piece Analyzer includes CI automated testing, static analysis, and code quality workflows:

```bash
# Run PHPUnit test suite (42 tests, 175 assertions, 100% passing)
composer test

# Run PHPStan static analysis (Level 8 - 0 errors)
composer phpstan

# Run Rector code modernizer (dry-run check)
composer rector

# Apply Rector fixes automatically
composer rector-fix
```

GitHub Actions automatically runs tests across PHP 8.2, 8.3, and 8.4 on every pull request and push to `master`/`main`.

---

## Contributing

Pull requests, feature suggestions, and bug reports are welcome. Please ensure that all changes pass `composer test` and `composer phpstan` before submitting.

---

## Disclaimer

LEGO® is a trademark of the LEGO Group of companies which does not sponsor, authorize, or endorse this project.
