# Releasing

Maintainer checklist for publishing a release of WordPress MCP Abilities. A release is an annotated Git tag named `vX.Y.Z`; pushing that tag makes GitHub Actions build the installable zip and publish it, with a SHA-256 checksum, on the GitHub Releases page.

- [Rules](#rules)
- [Pre-flight](#pre-flight)
- [Tag and push](#tag-and-push)
- [What the workflow does](#what-the-workflow-does)
- [Packaging](#packaging)
- [Verify the artifact locally](#verify-the-artifact-locally)
- [Post-release checks](#post-release-checks)
- [When a release fails or is wrong](#when-a-release-fails-or-is-wrong)
- [What must never be in the repository or the zip](#what-must-never-be-in-the-repository-or-the-zip)
- [What releases do not provide](#what-releases-do-not-provide)

## Rules

- **Semantic Versioning.** While the major version is `0`, a minor bump may contain breaking changes to abilities; use a patch bump for fixes only.
- **Tag format is exactly `vMAJOR.MINOR.PATCH`** (for example `v0.16.0`). Pre-release suffixes such as `-rc.1` are not supported: the workflow compares the tag with the plugin header `Version`, and the documentation-drift test only accepts `MAJOR.MINOR.PATCH`.
- **Tag the commit that passed CI on `main`.** Never tag an unmerged branch.
- **A tag is published immediately.** There is no approval step between pushing the tag and the public release.

## Pre-flight

1. **CI is green on `main`.** Static analysis, unit tests, multisite tests, MCP Adapter E2E and documentation drift. The `latest` WordPress legs of the unit job are informational (`continue-on-error`), but read any failure there before releasing.
2. **Bump the version in three places**, all to the same `X.Y.Z`:
   - the plugin header `Version:` in `wordpress-mcp-abilities/wordpress-mcp-abilities.php`;
   - the `WP_MCP_VERSION` constant in the same file;
   - the `**Version:**` line in `wordpress-mcp-abilities/README.md`.

   ```bash
   grep -nE "^ \* Version:|WP_MCP_VERSION'|\*\*Version:\*\*" \
     wordpress-mcp-abilities/wordpress-mcp-abilities.php wordpress-mcp-abilities/README.md
   ```

   The header and the constant must match each other, and the README line must match the header; `test_version_is_coherent_across_header_constant_and_readmes` in `wordpress-mcp-abilities/tests/test-documentation.php` enforces both. `composer.json` carries no version. `WP_MCP_ROLE_VERSION` is a separate role-schema version: change it only when the agent role's capability set changes, not on every release.
3. **Update [`CHANGELOG.md`](../CHANGELOG.md).** Move the `[Unreleased]` entries under a new `## [X.Y.Z] - YYYY-MM-DD` heading (the tag date), leave an empty `## [Unreleased]` above it, and update the comparison links at the bottom. The workflow publishes exactly that section as the release notes and **fails if the section is missing or empty**. Keep the heading in the `## [X.Y.Z]` form and use `###` for sub-headings, because the section ends at the next `## [`. Link-reference lines such as `[X.Y.Z]: https://...` are left out of the notes.
4. **Check the documentation numbers.** The drift test covers the ability count, the declared test counts, the inner catalog and the version. Also check by hand what it does not: the per-area counts in the root README, and the MCP Adapter version mentioned there against `MCP_ADAPTER_REF` in `.github/workflows/ci.yml`.
5. **Confirm nothing sensitive is in the tree** (see [the list below](#what-must-never-be-in-the-repository-or-the-zip)).
6. **Merge through a pull request** (for example titled `chore: release X.Y.Z`) and wait for CI on the merge commit.

## Tag and push

```bash
git switch main
git pull --ff-only
git tag -a vX.Y.Z -m "WordPress MCP Abilities X.Y.Z"
git push origin vX.Y.Z
```

Confirm `git log -1` shows the commit that passed CI before pushing the tag.

## What the workflow does

[`.github/workflows/release.yml`](../.github/workflows/release.yml) runs on every pushed tag matching `v*`, with `contents: write` permission:

1. **Checks the tag against the header.** `vX.Y.Z` must equal the `Version:` in the plugin header, otherwise the run fails before anything is built.
2. **Builds the zip** from the tagged commit:
   `git archive --format=zip --prefix=wordpress-mcp-abilities/ -o wordpress-mcp-abilities-X.Y.Z.zip <commit>:wordpress-mcp-abilities`
3. **Verifies the zip.** Every entry is under `wordpress-mcp-abilities/`, no development-only path is present (a `tests` folder, `.git*` files, `.github`, or editor and tool state directories), and `wordpress-mcp-abilities/wordpress-mcp-abilities.php` is present.
4. **Writes the checksum**, `wordpress-mcp-abilities-X.Y.Z.zip.sha256`, in standard `sha256sum` output format (`<hash>  <filename>`), so users can run `sha256sum -c`.
5. **Extracts the release notes** for `X.Y.Z` from `CHANGELOG.md`.
6. **Publishes the GitHub Release** for the tag with the notes as its body and the zip and checksum as assets.

## Packaging

Only the `wordpress-mcp-abilities/` subtree is archived, so repository-level tooling (`bin/`, `docs/`, `stubs/`, `.github/`, `composer.json`, `phpcs.xml`, the root README and so on) never enters the zip. Inside the subtree, `wordpress-mcp-abilities/.gitattributes` marks `/tests` and itself as `export-ignore`. That file is the only `.gitattributes` that governs the archive; do not add development-only files to the subtree without export-ignoring them.

A correct zip contains the main plugin file, `includes/`, `uninstall.php` and the plugin `README.md`, all under a single `wordpress-mcp-abilities/` folder, so it unpacks into a correctly named plugin directory.

## Verify the artifact locally

You can build the same archive before tagging (from `HEAD`) or after (from the tag). Built zips are ignored by `.gitignore`; never commit one.

```bash
git archive --format=zip --prefix=wordpress-mcp-abilities/ \
  -o wordpress-mcp-abilities-X.Y.Z.zip vX.Y.Z:wordpress-mcp-abilities

# Entries: everything must live under wordpress-mcp-abilities/
unzip -Z1 wordpress-mcp-abilities-X.Y.Z.zip | grep -vc '^wordpress-mcp-abilities/'    # expect 0

# Development-only paths (a tests folder or any dotfile or dot-directory): expect no output
unzip -Z1 wordpress-mcp-abilities-X.Y.Z.zip | grep -E '(^|/)(tests|\.[^/]+)(/|$)'

# Checksum, in the same format the release publishes
sha256sum wordpress-mcp-abilities-X.Y.Z.zip
```

`git archive` output is deterministic for a given commit (entries are stamped with the commit time), so rebuilding from the tag should give the same content as the release asset. Compressed bytes can still differ between Git or zlib versions, so if the zip hashes differ, compare the extracted trees instead. The published `.sha256` is the reference for the published file.

## Post-release checks

1. The **Release** workflow run for the tag is green.
2. The release page shows the zip, the `.sha256` file and the notes from the changelog, and `/releases/latest` points at it.
3. Download the zip from the release page and run `sha256sum -c` against the downloaded `.sha256`.
4. Install the downloaded zip on a scratch WordPress site with MCP Adapter active: activation succeeds without notices, the Plugins screen shows `X.Y.Z`, the *WordPress MCP Agent* role exists, and `mcp-adapter-discover-abilities` lists the `wp-mcp/*` abilities.
5. `CHANGELOG.md` on `main` has an empty `## [Unreleased]` section ready for the next change.

## When a release fails or is wrong

- **The workflow failed before publishing.** Read the error. For a transient problem (network, Actions outage), re-run the failed job from the Actions tab: it uses the same tag and commit. For a content problem (version mismatch, missing changelog section, zip check), fix it on `main` through a pull request, then move the tag:

  ```bash
  git push --delete origin vX.Y.Z
  git tag -d vX.Y.Z
  # after the fix is merged and CI is green:
  git switch main && git pull --ff-only
  git tag -a vX.Y.Z -m "WordPress MCP Abilities X.Y.Z"
  git push origin vX.Y.Z
  ```
- **A release was published with a wrong or broken artifact.** If it has only just been published and nobody could have installed it, delete the GitHub Release and the tag, then re-tag as above. Otherwise do not rewrite a version people may already have (their checksums would stop matching): publish a fixed patch release, and say in its changelog entry which version it supersedes. Edit the bad release's notes to point to the fixed one.
- **A security problem is found in a published release.** Follow [`SECURITY.md`](../SECURITY.md); only the latest release receives fixes, so the remedy is a new release.

## What must never be in the repository or the zip

- credentials of any kind: passwords, Application Passwords, API keys, tokens, private keys, `.env` files, `auth.json`;
- production or staging URLs, hostnames, IP addresses and email addresses (use `example.com` or `your-site.example` placeholders);
- user data, database dumps and exports (WXR files, settings exports, personal data exports);
- private infrastructure details, deployment-specific configuration and internal issue or repository references;
- development-only files inside the plugin folder: tests, CI configuration, editor or tool state, dotfiles, built archives.

The zip is public and permanent once released. If something sensitive reached a tag, treat it as disclosed: rotate it, then follow the failed-release steps above.

## What releases do not provide

Releases are not signed, carry no build attestations, are not listed on WordPress.org, and WordPress does not notify sites of new versions. The checksum protects against corrupted downloads and against a file that differs from the one published next to it; it does not prove who published it. If that changes, update [`SECURITY.md`](../SECURITY.md) and the root README together.
