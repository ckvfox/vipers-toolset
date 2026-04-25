# vipers-toolset

Internal WordPress operations plugin for Pulheim Vipers.

## Scope

`vipers-toolset` is used for controlled frontend/runtime operations in WordPress, including scan, rule application, decision tracking, and rollback-safe behavior for production-like environments.

## Plugin Meta

- WordPress plugin name: `Vipers Toolset`
- Main file: `vipers-toolset.php`
- Version (code): `2.0.1`
- Text domain: `vipers-toolset`

## Core Capabilities

- Admin tooling for scan/configuration workflows
- Conditional handling of scripts/styles
- Asset capture and decision logging
- Bypass/recovery helpers for safer rollout

## Repository Layout

- `vipers-toolset.php`: full plugin implementation
- `SYSTEM-OVERVIEW.txt`: architecture and behavior overview
- `CHANGES.md`: release notes and version history
- `SECURITY.md`: security policy and hardening notes

## Local Development

1. Start Local site `pulheim-vipers-test`.
2. Ensure repo path is:
   `wp-content/plugins/vipers-toolset`
3. Activate plugin in wp-admin.
4. Open `Tools -> Vipers Toolset`.
5. Validate critical frontend pages after each rule/config change.

## Deployment Model

- Source of truth: GitHub repository
- Production model: pull-only deployment on Strato
- Repository includes only plugin code and docs (no secrets, no DB, no uploads)

## Operational Guidelines

- Use incremental changes instead of broad rule jumps.
- Keep rollback path ready before applying aggressive optimizations.
- Prefer measurable before/after checks on key pages.

## Security

- Never commit credentials, tokens, private keys, or dumps.
- Follow `SECURITY.md` for disclosure, reporting, and hardening guidance.
