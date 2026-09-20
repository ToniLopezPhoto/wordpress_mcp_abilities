# Contributing

Contributions are welcome when they preserve the project's security model.

## Before opening a PR

Run:

```bash
composer install
composer test
composer stan
vendor/bin/phpcs
```

## Invariants

Changes must not introduce:

- arbitrary PHP, SQL or shell execution
- generic filesystem access
- generic WordPress option read/write
- dynamic "execute anything" dispatchers
- permission checks based on assumptions instead of WordPress capabilities
- production credentials, URLs, user data or deployment configuration

New abilities should be explicit, schema-bounded, permission-aware and covered by tests.
