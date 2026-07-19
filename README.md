# Rishe ERP

A modular ERP and omnichannel operations plugin for WordPress and WooCommerce.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- Composer 2
- MySQL 8+ or MariaDB 10.6+

## Development

```bash
composer install
composer lint
composer test
```

Install the repository as a WordPress plugin, run `composer install`, and activate **Rishe ERP** from the WordPress administration panel.

## Current milestone

The foundation milestone provides plugin bootstrapping, versioned database migrations, ERP capabilities, a protected health endpoint, transaction handling, audit storage, idempotency storage, an outbox table, coding standards, and CI.

See `AGENTS.md` for Codex implementation rules and `docs/architecture.md` for architectural boundaries.
