# APFIMUR Agent Abilities

A security-focused WordPress plugin that exposes explicit **WordPress Abilities** to AI agents through the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).

The project is built around one rule: **agents get narrow, auditable capabilities — never arbitrary execution**.

> This open-source export contains no APFIMUR production configuration, credentials, user data, infrastructure details or deployment secrets.

**Catálogo de Abilities (285)** · 477 test cases · 48 security tests

## What it provides

- Content, media, taxonomies, comments and users
- Classic navigation and Site Editor operations
- Plugins, themes, core updates and explicit settings
- Custom post types and registered post metadata
- Multisite/network administration
- Integrations for selected third-party plugins
- Capability-filtered discovery for agents
- Optional audit logging

The plugin intentionally does **not** expose arbitrary PHP, SQL, shell, filesystem access or generic WordPress option read/write.

## Security model

Every ability is a fixed operation with its own input/output schema, permission callback and capability checks.

Key principles:

- least privilege
- native WordPress capability and meta-capability checks
- ownership enforcement
- closed schemas
- explicit allowlists
- SSRF-aware remote URL validation
- no generic dispatchers
- no arbitrary option, filesystem or code execution
- bounded discovery surfaces
- destructive operations explicitly identified

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

The test suite covers the ability registry, permissions, security boundaries, multisite behaviour and real MCP Adapter JSON-RPC integration.

## Repository layout

```text
apfimur-agent-abilities/   WordPress plugin source
  includes/                Ability implementations and security boundaries
  tests/                   Unit, security, multisite and E2E tests
bin/                       WordPress test-environment tooling
docs/                      Technical test and coverage documentation
.github/workflows/         Public CI and release automation
```

## Production separation

This repository is an open-source codebase, not a production deployment export.

Do not commit:

- production URLs or infrastructure details
- WordPress Application Passwords
- OAuth/API credentials
- user or member data
- database dumps
- `.env` files
- deployment-specific configuration

## License

GPL-2.0-or-later.
