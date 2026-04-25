# vipers-toolset

Internal WordPress operations plugin for Pulheim Vipers to analyze and control frontend asset behavior safely.

## What This Plugin Does

- Adds an operations dashboard in `Tools -> Vipers Toolset`
- Tracks enqueued scripts/styles and builds scan logs for analysis
- Applies conditional rules for frontend assets
- Provides dry-run vs. apply workflows with event logging
- Supports a short-lived recovery bypass mechanism for emergency fallback
- Injects local Google Fonts CSS from local font files (privacy/performance)

## Core Concept

`vipers-toolset` is not a public feature plugin.  
It is an internal governance/operations plugin used to evaluate and control runtime assets in a production-like setup.

## Plugin Meta

- WordPress plugin name: `Vipers Toolset`
- Main file: `vipers-toolset.php`
- Version: `2.0.1`
- Text domain: `vipers-toolset`
- License: `GPL-2.0-or-later`

## Main Modules

- Scanner and capture pipeline:
  - Collects script/style handles and source URLs
  - Estimates local asset file sizes
  - Stores scan/compare logs in options
- Rules engine:
  - Evaluates path/context-based rules
  - Supports dry-run logging before rollout
- Safety controls:
  - Recovery bypass toggle via transient
  - Snapshot/restore workflows in admin actions
- Recommendations and aggregation:
  - Builds aggregate views and optimization hints from captured data
- Local font handling:
  - Allows controlled local Google font serving

## Scan Behavior (Current)

Scanner capture is intentionally restricted to explicit scan runs.  
Normal frontend requests should not continuously write scan logs.

## Repository Structure

- `vipers-toolset.php`: plugin logic (scanner, rules, safety, admin UI)
- `SYSTEM-OVERVIEW.txt`: architecture notes
- `CHANGES.md`: change history
- `SECURITY.md`: disclosure and security guidance

## Local Development

1. Start Local site `pulheim-vipers-test`.
2. Use path `wp-content/plugins/vipers-toolset`.
3. Activate plugin in wp-admin.
4. Open `Tools -> Vipers Toolset`.
5. Test critical pages after every rule/configuration change.

## Operational Guidance

- Prefer dry-run before apply.
- Roll out rules incrementally.
- Keep rollback/bypass path ready.
- Validate results with before/after performance checks.

## Security and Secrets

- Never commit credentials, tokens, private keys, dumps, or `.env` files.
- Follow `SECURITY.md` for reporting and hardening notes.
