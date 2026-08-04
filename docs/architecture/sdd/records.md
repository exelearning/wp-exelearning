# SDD Index

This page lists the Software Design Documents for `wp-exelearning`. See the
[SDD guide](README.md) for the policy and [`template.md`](template.md) for the
starting point.

The index is maintained by hand. When an SDD is added or changes status, update
the table and the per-status lists below.

| ID | Title | Status | Date | Related ADRs |
|----|-------|--------|------|--------------|
| [SDD-0001](SDD-0001-stale-content-url-redirects.md) | Stale content URL redirects to the latest ELPX extraction | Accepted | 2026-07-10 | [ADR-0001](../adr/ADR-0001-obsolete-hash-alias-storage.md) |
| [SDD-0002](SDD-0002-unify-release-packaging.md) | Unify release packaging on `.distignore` and `wp dist-archive` | Draft | 2026-08-03 | [ADR-0003](../adr/ADR-0003-distignore-single-source-of-truth.md) |
| [SDD-0003](SDD-0003-testable-editor-bundle-paths.md) | Resolve every editor bundle path through one helper, and let tests supply the bundle | Implemented | 2026-08-04 | [ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md) |
| [SDD-0004](SDD-0004-editor-bootstrap-view-returns-html.md) | Make the editor bootstrap a view that returns its HTML | Implemented | 2026-08-04 | [ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md) |

## Draft SDDs

- [SDD-0002](SDD-0002-unify-release-packaging.md) — Unify release packaging on
  `.distignore` and `wp dist-archive`.

## In Review SDDs

_No SDDs in review yet._

## Accepted SDDs

- [SDD-0001](SDD-0001-stale-content-url-redirects.md) — Stale content URL
  redirects to the latest ELPX extraction.

## Implemented SDDs

- [SDD-0003](SDD-0003-testable-editor-bundle-paths.md) — Resolve every editor
  bundle path through one helper, and let tests supply the bundle.
- [SDD-0004](SDD-0004-editor-bootstrap-view-returns-html.md) — Make the editor
  bootstrap a view that returns its HTML.

## Superseded SDDs

_No superseded SDDs yet._
