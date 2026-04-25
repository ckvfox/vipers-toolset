# vipers-toolset

Internal WordPress utility plugin for Pulheim Vipers.

## Purpose

`vipers-toolset` provides governance and operational tooling around frontend assets and runtime behavior, including scan, rule-based decisions, and compare workflows intended for safe optimization in production-like environments.

## WordPress Plugin Meta

- Plugin Name: `Vipers Toolset`
- Main file: `vipers-toolset.php`
- Current version: `2.0.1`
- Text domain: `vipers-toolset`

## Functional Scope

- Admin tools page for scan/configuration actions
- Conditional rule handling for scripts/styles
- Asset capture and decision logging
- Recovery/bypass mechanisms for safer rollout

## Repository Contents

- `vipers-toolset.php`: main plugin implementation
- `SYSTEM-OVERVIEW.txt`: architecture and behavior overview
- `CHANGES.md`: release/change notes
- `SECURITY.md`: security considerations and reporting guidance

## Local Development

1. Run site in Local (`pulheim-vipers-test.local`)
2. Activate plugin in wp-admin
3. Open Tools -> Vipers Toolset for scan/rule actions
4. Validate frontend behavior on critical pages after each rule change

## Operational Notes

- Treat this plugin as a controlled operations tool, not only feature code.
- Prefer dry-run and incremental changes.
- Keep a rollback path before applying broad rule sets.

## Security

- Never commit credentials or environment secrets.
- Follow `SECURITY.md` for disclosure/reporting and hardening guidelines.

