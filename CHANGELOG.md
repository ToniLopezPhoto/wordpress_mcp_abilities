# Changelog

All notable changes to WordPress MCP Abilities are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the major version is `0`, minor releases may include breaking changes to abilities; they are always called out here.

## [Unreleased]

## [0.16.0] - 2026-09-21

First public release, distributed as an installable zip (`wordpress-mcp-abilities-0.16.0.zip`) on the GitHub Releases page. It contains only the plugin runtime: the main file, `includes/`, `uninstall.php` and the reference `README.md`.

### Added

- **Ability catalog.** 285 abilities in the `wp-mcp/` namespace, registered through the WordPress Abilities API in 15 `wp-mcp-*` categories and exposed to AI agents through the WordPress MCP Adapter. Abilities are flagged for MCP only and are not exposed through additional REST routes. `WP_MCP_Ability_Matrix` is the single source of truth for every ability's category, capability, destructive and idempotent flags.
- **Agent role.** The `wp_mcp_agent` role (*WordPress MCP Agent*) is created on activation with `read`, `edit_posts`, `edit_published_posts` and `publish_posts`, and is reconciled automatically when its definition changes. Deactivation preserves it; uninstalling removes it only when no user holds it.
- **Content.** Posts and pages: CRUD, publish, unpublish, schedule, status changes, trash, restore, permanent delete, author, slug, sticky, password, page attributes, revisions, autosave, duplication and bulk trash.
- **Custom post types.** Discovery of registered post types, then CRUD, lifecycle, revisions, featured image and registered post metadata through a fixed set of abilities in which the post type is a validated selector, never a dispatcher.
- **Media.** Upload from base64 content or from a remote URL, metadata updates, replacement, attach and detach, featured image, metadata regeneration, trash, restore, permanent delete and bulk trash.
- **Taxonomies.** Category, tag and custom taxonomy discovery, term CRUD, assignment and removal (single and bulk), and registered term metadata.
- **Comments.** List, read, create, reply, edit, approve, unapprove, spam, trash, restore, permanent delete and bulk moderation.
- **Users, roles and Application Passwords.** Profiles, user creation and deletion with an explicit reassignment target, role changes, role and capability management (the plugin-managed agent role is protected), and Application Password creation, listing and revocation.
- **Navigation and site editor.** Classic menus, locations and menu items; templates, template parts, patterns, synced patterns, navigation blocks, global styles and widget areas.
- **Plugins and themes.** List, install from the WordPress.org repository or a URL, activate, deactivate, switch, update (single, selected or all), auto-update toggles and delete. Installing never activates or switches; deleting an active plugin or theme is refused.
- **Settings.** General, Writing, Reading, Discussion, Media, Permalinks and Privacy settings through a declarative per-field allowlist, with settings export and import. Raw option names are never accepted.
- **System.** Site and environment information, Site Health tests, core and translation updates, cron events (with a permanent hook denylist), object-cache and transient cleanup, maintenance mode, and WXR content export and import.
- **Privacy.** Personal data export and erasure requests, including confirmation email resend and processing.
- **Multisite.** Network administration of sites, network users and memberships, network plugins and themes, network settings and the network update state. On a single site these abilities are registered but never offered, and answer `wp_mcp_network_unsupported`.
- **Integrations.** An integration framework with two discovery abilities, plus adapters that register only when their plugin is active: WooCommerce (7 abilities), Yoast SEO (2), Contact Form 7 (2), Gravity Forms (2) and WPForms (2). Further popular plugins are detected but expose no abilities yet.
- **Discovery.** Nine read-only abilities: global search, post statuses, MIME types, block types, pattern categories, template types, the caller's own capabilities and feature support.
- **Audit logging.** The `wp_mcp_audit_log` action fires for every write, with a fixed event shape and structural scrubbing of credential-shaped keys. Setting `WP_MCP_AUDIT_LOG` also writes one line per event to the PHP error log.
- **Test suites and CI.** Unit, cross-cutting security, multisite, MCP Adapter end-to-end and documentation-drift suites, run in CI across PHP 7.4 and 8.3 and WordPress 6.9 and latest, alongside PHPCS (WordPress coding standards, PHP 7.4 compatibility) and PHPStan.
- **Release packaging.** Every `vX.Y.Z` tag builds the installable zip with GitHub Actions, verifies that it contains only the plugin runtime, publishes it with a SHA-256 checksum (`wordpress-mcp-abilities-X.Y.Z.zip.sha256`, standard `sha256sum` format) and uses this changelog section as the release notes. The maintainer checklist is in `docs/RELEASING.md`.
- **Documentation.** The README is now a full project front page: installation from a release, first-run setup, abilities overview by area, security model, upgrading and uninstalling, development, releases and versioning, troubleshooting and roadmap. `SECURITY.md` gains supported versions and download verification, and `CONTRIBUTING.md` documents the documentation-drift test and the pull request workflow.

### Security

- Every ability is capability-checked with native WordPress capabilities; mutations re-verify per-object meta-capabilities.
- Closed input and output schemas, bounded pagination and bulk sizes, and size limits on uploads and downloads.
- SSRF-aware validation of every remote URL the plugin fetches.
- No arbitrary PHP, SQL, shell or filesystem access, and no generic option or metadata read/write.

[Unreleased]: https://github.com/ToniLopezPhoto/wordpress_mcp_abilities/compare/v0.16.0...HEAD
[0.16.0]: https://github.com/ToniLopezPhoto/wordpress_mcp_abilities/releases/tag/v0.16.0
