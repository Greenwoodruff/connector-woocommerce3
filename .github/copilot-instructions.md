# Project Instructions

## What this project is

A WordPress plugin that synchronizes WooCommerce shop data (products, categories, customers, orders) with JTL-Wawi ERP via the `jtl/connector` core framework. The plugin exposes a `/jtlconnector` HTTP endpoint that JTL-Wawi calls to pull and push data.

Distribution: upstream packages a ZIP for wordpress.org via SVN. This fork (Greenwoodruff, for camplorer.de) is shipped as a hand-built ZIP (see "ZIP erstellen" in `CHANGES.md`).

**PHP 8.3 required — and the fork must stay on 8.3.** Upstream requires PHP 8.4 from 2.4.4 on, but the production server runs 8.3. `composer.json` pins `config.platform.php` to `8.3.0`, and `composer.lock` is resolved for 8.3. Never run a plain `composer update` or adopt upstream's lock file unchanged; see "PHP 8.3 statt 8.4" in `CHANGES.md`. Fork changes are marked in code with `// FORK ADDITION` / `// FORK FIX`.

## Commands

```bash
# Install dependencies (locally missing PHP extensions such as fileinfo/sqlite3/zip may be ignored; never use --ignore-platform-reqs, which also ignores the PHP version)
composer install --ignore-platform-req='ext-*'

# Run all tests (the session tests need ext-sqlite3; enable it ad hoc if php.ini lacks it)
composer run tests
php -d extension=sqlite3 vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/src/Controllers/ImageTest.php

# Run a single test method
./vendor/bin/phpunit --filter testMethodName tests/src/Controllers/ImageTest.php

# Code style check
composer run phpcs

# Auto-fix code style
composer run phpcs:fix

# Static analysis (PHPStan level max)
composer run phpstan

# Run both phpcs + phpstan
composer run analyse

# WordPress coding standards / plugin-check rules
composer run phpcs:wp

# Build release ZIP (requires phing)
./build.sh
```

CI uses `shivammathur/setup-php` with PHP 8.3. Upstream runs the phpcs/phpstan job in JTL's private image `ghcr.io/jtl-software/connector-utils-ci-docker/php/cli`, which the fork cannot pull, so the fork replaced it (`FORK FIX` in `ci.yaml`). Upstream uses PHP 8.4; the fork switched back to 8.3. CI checks all files, so phpcs and phpstan must stay at zero findings or the job turns red and PHPUnit/plugin-check are skipped.

## Architecture

### Request flow

`/jtlconnector` endpoint → `includes/JtlConnector.php::capture_request()` → creates `FileConfig` from `config/config.json` → instantiates `src/Connector.php` → delegates to `jtl/connector` framework `Application::run()`.

### Connector.php

Implements `ConnectorInterface`, `UseChecksumInterface`, and `HandleRequestInterface` from the core framework. Wires up the DI container (PHP-DI), registers controllers, and dispatches Symfony events (`HandlePullEvent`, `HandlePushEvent`, `HandleDeleteEvent`, etc.).

### Controllers

`src/Controllers/` contains one controller per JTL entity (Category, Customer, Product, CustomerOrder, Image, Manufacturer, Payment, ...). Controllers follow a pull/push/delete pattern defined by the core framework.

Product has a dedicated subdirectory `src/Controllers/Product/` with sub-controllers for variations, attributes, prices, stock levels, and third-party plugin specifics (Germanized, GermanMarket, B2BMarket, etc.). Orders have a similar `src/Controllers/Order/` subdirectory.

All controllers extend `AbstractController` / `AbstractBaseController`.

### Plugin integrations

`src/Integrations/PluginsManager.php` is the registry for third-party WordPress plugin integrations. Supported integrations: WPML (13+ components), Germanized, GermanMarket, PerfectWooCommerceBrands, RankMathSeo, YoastSeo, AdvancedCustomFields, B2BMarket.

Each integration hooks into the main controller pipeline via the Symfony EventDispatcher.

### SQL layer

Database access goes through `src/Utilities/Db.php` (a thin wrapper around WordPress `wpdb`). SQL queries for each entity are organized as PHP traits in `src/Utilities/Db/` (e.g., `ProductTrait`, `CategoryTrait`, `OrderTrait`). These traits are mixed into `Db.php` — add new queries there, not inline in controllers.

`src/Utilities/SqlHelper.php` provides shared query-building helpers.

### Key configuration

- `config/config.json` — runtime config loaded per request (token, logging level, etc.)
- `config/features.json` (optional, copy from `.example`) — feature flag toggles
- `build-config.yaml` — plugin version and minimum WC version (used by Phing)

### Custom database tables

11 custom tables are created on plugin activation (prefixed `jtl_connector_*`) for ID mapping (linking WC IDs to JTL IDs), checksum tracking, and category management. They are dropped by `uninstall.php`.

### Admin UI

`includes/JtlConnectorAdmin.php` handles the WooCommerce settings panel, AJAX endpoints for log management, and developer settings. It is largely self-contained.

## Testing

Tests live in `tests/src/` with namespace `JtlWooCommerceConnector\Tests\`. Bootstrap at `tests/bootstrap.php` loads WP_Mock to stub WordPress globals. PHPUnit config is `phpunit.xml` (strict: `forceCoversAnnotation`, `failOnRisky`, `failOnWarning`).

New tests must have `@covers` annotations. Use `AbstractTestCase` as the base class.

## CI/CD

Workflows in `.github/workflows/`:

| File | Trigger | Purpose |
|---|---|---|
| `ci.yaml` | push master / PRs / weekly / manual | phpcs + phpstan + phpunit (PHP 8.3) + WordPress plugin check |
| `plugin-check.yaml` | `workflow_call` (from `ci.yaml` / build) | WordPress Plugin Check in wp-env (PHP 8.3) |
| `build-and-deploy.yaml` | tag push `x.y.z` | Phing ZIP + SVN deploy to wordpress.org (upstream release path, not used for the fork) |
| `update-changelog.yaml` | tag push / manual | Calls reusable workflow in `changelog-extractor` repo |

Up to 2.4.2 there was also an `auto-draft-pr.yaml`, plus `lint-actions.yaml` and `lint-scripts.yaml`. Upstream removed them in 2.4.3/2.4.4, so pushing a feature branch no longer opens a draft PR automatically.

The bundled mini-plugins live in `plugins/JtlWooCommerceConnector/Plugins/` (before 2.4.4: `plugins/jtl/`).
