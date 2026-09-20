# WordPress MCP Abilities

A security-focused WordPress plugin that exposes explicit **WordPress Abilities** to AI agents through the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).

The design rule is simple: **agents get narrow, auditable capabilities — never arbitrary execution**.

**Catálogo de Abilities (285)** · 477 test cases · 48 security tests

## Scope

The plugin exposes bounded operations for content, media, taxonomies, comments, users, navigation, Site Editor, plugins, themes, settings, custom post types, multisite administration, integrations and capability-filtered discovery.

It intentionally does **not** expose arbitrary PHP, SQL, shell execution, generic filesystem access or generic WordPress option read/write.

## Security model

Every ability has its own schema, permission callback and WordPress capability checks. The project favors least privilege, closed schemas, explicit allowlists, ownership enforcement, SSRF-aware URL validation, bounded discovery and clearly identified destructive operations.

## Requirements

- WordPress 6.9+
- PHP 7.4+
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)

## Development

```bash
composer install
composer test
composer stan
vendor/bin/phpcs
```

The suite includes unit tests, multisite coverage, static analysis and real MCP Adapter JSON-RPC E2E tests.

## Repository layout

```text
wordpress-mcp-abilities/   WordPress plugin source
  includes/                Ability implementations and security boundaries
  tests/                   Unit, security, multisite and E2E tests
bin/                       WordPress test-environment tooling
docs/                      Coverage and test documentation
.github/workflows/         CI and release automation
```

## Production separation

This repository contains reusable plugin code only. Do not commit production URLs, credentials, Application Passwords, user data, database dumps, private infrastructure details or deployment-specific configuration.

## License

GPL-2.0-or-later.
