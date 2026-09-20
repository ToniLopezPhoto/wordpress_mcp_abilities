<div align="center">

# WordPress MCP Abilities

**Safe, explicit WordPress actions for AI agents over MCP.**

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![CI](https://img.shields.io/github/actions/workflow/status/ToniLopezPhoto/wordpress_mcp_abilities/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/ToniLopezPhoto/wordpress_mcp_abilities/actions)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-success?style=flat-square)](LICENSE)

**Catálogo de Abilities (285)** · **477 test cases** · **48 security tests**

</div>

---

## How it works

```mermaid
flowchart LR
    A[AI Agent] --> B[MCP Adapter]
    B --> C[Ability Registry]
    C --> D[Permissions]
    C --> E[Audit]
    C --> F[WordPress]
```

Every action is explicit, schema-bounded and checked against native WordPress capabilities.

## Surface

`content` · `media` · `taxonomies` · `comments` · `users` · `navigation` · `site editor` · `plugins` · `themes` · `settings` · `multisite` · `integrations` · `discovery`

### Security by design

- Least privilege & ownership checks
- Closed input/output schemas
- Explicit allowlists
- SSRF-aware remote URL validation
- Bounded discovery and pagination
- Auditable destructive operations

> No arbitrary PHP, SQL, shell, filesystem access or generic WordPress option read/write.

## Use it

Requires **WordPress 6.9+**, **PHP 7.4+** and the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter).

```bash
composer install
composer test
composer stan
vendor/bin/phpcs
```

For the full ability reference, schemas and security notes, see [`wordpress-mcp-abilities/README.md`](wordpress-mcp-abilities/README.md).

---

<sub>Reusable plugin code only — keep production URLs, credentials, user data and deployment-specific configuration out of the repository.</sub>

**GPL-2.0-or-later**
