# Security Policy

WordPress MCP Abilities exposes administrative operations to AI agents, so security boundaries are part of the public API.

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

## Production data

This repository must not contain real credentials, production URLs, user data, database dumps, private infrastructure details or deployment-specific secrets.
