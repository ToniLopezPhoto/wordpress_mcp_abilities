# Security Policy

WordPress MCP Abilities exposes administrative operations to AI agents, so security boundaries are part of the public API.

## Supported versions

Only the latest released `0.x` version receives security fixes. Fixes are published as a new release; they are not backported to older releases. The latest version is the newest one on the [Releases page](https://github.com/ToniLopezPhoto/wordpress_mcp_abilities/releases). If you run an older version, please say so in your report and, when you can, check whether the problem reproduces on the latest release.

| Version | Security fixes |
|---|---|
| Latest `0.x` release | Yes |
| Older `0.x` releases | No, upgrade to the latest |

## Reporting a vulnerability

Please use GitHub private vulnerability reporting when available. If it is not available, open a minimal issue requesting a private contact channel and do not include exploit details, credentials or production information in the public issue.

## Scope

Security reports are especially useful for:

- capability or ownership bypasses
- privilege escalation
- SSRF
- arbitrary code, SQL, filesystem or option access
- schema bypasses
- unintended sensitive-data disclosure
- unsafe destructive operations
- MCP-visible operations that exceed their documented scope

## Verifying downloads

Install the plugin only from the [Releases page](https://github.com/ToniLopezPhoto/wordpress_mcp_abilities/releases) of this repository. Every release is built by GitHub Actions from the tagged commit, and its checksum is generated in the same run. The workflow refuses to publish when the tag does not match the plugin version, or when the zip has entries outside the plugin folder or development-only files such as tests.

Each release publishes two files: `wordpress-mcp-abilities-X.Y.Z.zip` and `wordpress-mcp-abilities-X.Y.Z.zip.sha256`. With both in the same folder:

```bash
sha256sum -c wordpress-mcp-abilities-X.Y.Z.zip.sha256    # macOS: shasum -a 256 -c ...
```

```powershell
# Windows PowerShell: the two values must be identical (case does not matter)
(Get-FileHash .\wordpress-mcp-abilities-X.Y.Z.zip -Algorithm SHA256).Hash
Get-Content .\wordpress-mcp-abilities-X.Y.Z.zip.sha256
```

What this proves, and what it does not:

- It detects a corrupted, truncated or altered download. A mismatch means: do not install, download again, and report it if it persists.
- The checksum is published on the same release page as the zip, so it does not protect against a compromise of the release itself. Releases are not signed and carry no build attestations.
- For stronger assurance, rebuild the zip from the tag with `git archive` and compare its contents with the release asset; the steps are in [`docs/RELEASING.md`](docs/RELEASING.md#verify-the-artifact-locally).

## Production data

This repository must not contain real credentials, production URLs, user data, database dumps, private infrastructure details or deployment-specific secrets.
