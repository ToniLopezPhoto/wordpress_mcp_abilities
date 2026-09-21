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

`composer test` needs PHP, a database and the WordPress test library (`bin/install-wp-tests.sh`); the [Development section of the README](README.md#development) and `.github/workflows/ci.yml` describe the setup. Without that environment, CI runs everything for you on the pull request. The documentation check below needs only PHP and Composer.

## Invariants

Changes must not introduce:

- arbitrary PHP, SQL or shell execution
- generic filesystem access
- generic WordPress option read/write
- dynamic "execute anything" dispatchers
- permission checks based on assumptions instead of WordPress capabilities
- production credentials, URLs, user data or deployment configuration

New abilities should be explicit, schema-bounded, permission-aware and covered by tests.

## Documentation must match the code

`wordpress-mcp-abilities/tests/test-documentation.php` is a merge-blocking check (the `Documentation drift` CI job). It fails when the READMEs claim something the code contradicts:

- the ability count in the root README header (`Catálogo de Abilities (N)`) and in the plugin README (`Abilities Available (N total)`) must equal the number of entries in `WP_MCP_Ability_Matrix::get()`;
- the declared test counts (`N test cases` or `N pruebas`) must equal the number of `test_*` methods in `test-abilities.php`, and the declared security-suite count (`N security tests`) the number in `test-security.php`;
- every ability in the matrix must appear, fully namespaced, in the plugin README catalog;
- the per-category counts in the coverage report under `docs/` must match the matrix, and every ability it names must exist;
- the plugin header `Version`, the `WP_MCP_VERSION` constant and the `**Version:**` line of the plugin README must agree.

Run it on its own, without WordPress or a database:

```bash
vendor/bin/phpunit --configuration phpunit.docs.xml.dist
```

When you add, remove or rename an ability or a test, update the same pull request: the matrix row, the plugin README catalog, the counts above, and the per-area table in the root README, which the check does not cover.

## Branches and pull requests

- Branch from `main` and open the pull request against `main`. Use a short, descriptive branch name such as `feat/...`, `fix/...`, `docs/...` or `chore/...`.
- Follow the commit style of the existing history: a Conventional Commits prefix (`feat:`, `fix:`, `docs:`, `chore:`, `ci:`) and a short summary.
- Keep pull requests focused. For a new or changed ability, say in the description which capability gates it and what it deliberately does not expose.
- Include tests and documentation with the change, and add a line under `## [Unreleased]` in [`CHANGELOG.md`](CHANGELOG.md) when the change is visible to users.
- The pull request should pass every CI job before it is merged.
- Do not bump the plugin version in a feature pull request; version bumps belong to the release commit.
- Never commit credentials, production URLs, user data or built zip archives.

## Releases

Maintainers publish releases by pushing a `vX.Y.Z` tag; the checklist is in [`docs/RELEASING.md`](docs/RELEASING.md). Report security problems privately as described in [`SECURITY.md`](SECURITY.md), not in a public issue or pull request.
