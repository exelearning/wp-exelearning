---
id: ADR-0001
title: "Resolve obsolete extraction hashes through attachment post meta aliases"
status: Accepted
date: 2026-07-10
related:
  issues:
    - https://github.com/exelearning/exelearning/issues/2150
  prs: []
  sdds:
    - SDD-0001
  adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-fable-5"
---

# ADR-0001: Resolve obsolete extraction hashes through attachment post meta aliases

## Status

Accepted

## Context

Extracted eXeLearning packages are served through public hash-based REST URLs
(`exelearning/v1/content/{hash}/{file}`). Saving an edited `.elpx` attachment
generates a fresh random extraction hash, commits it to the
`_exelearning_extracted` attachment post meta, and deletes the previous
extraction directory (`includes/class-exelearning-rest-api.php:375-495`,
`includes/class-elp-reprocessor.php:52-109` @ `50f0ed5`). Every previously
published URL for that attachment then returns `file_not_found` permanently.
The plugin stores all per-package state in attachment post meta by design
(`CLAUDE.md`, "Data Storage") and has no custom tables.

## Problem

Where and how should the relationship "this retired hash belonged to that
attachment" be persisted so the content proxy can redirect stale URLs to the
attachment's current extraction — durably, without redirect chains, without
unbounded growth, and with safe cleanup when attachments are deleted?

## Decision drivers

- Zero cost on successful content requests (public, high-traffic endpoint).
- Automatic cleanup when an attachment is permanently deleted.
- No redirect chains after multiple sequential saves; no loops.
- Legacy shared (content-derived) hashes must never be hijacked or deleted
  while still referenced.
- WordPress conventions: the plugin keeps all per-attachment state in post
  meta; plugin-review friction of custom tables is unjustified for a 40-byte
  relation.
- No autoloaded global state.

## Alternatives considered

### Option 1: Multi-value attachment post meta (`_exelearning_obsolete_hash`) — chosen

One meta row per retired hash on the owning attachment. Resolution finds the
owner by meta query (only on the proxy's 404 fallback path) and reads the
owner's current `_exelearning_extracted`.

- Pros: aliases resolve *through the attachment identity* to its single
  current hash — chains are structurally impossible; rows die with the
  attachment (core deletes post meta on `wp_delete_attachment()`); matches the
  plugin's storage model; trivially testable with attachment factories.
- Cons: resolution is a meta query rather than a keyed lookup (acceptable —
  it runs only after a request has already 404ed).

### Option 2: One non-autoloaded option per obsolete hash

`exelearning_alias_{hash}` → attachment ID.

- Pros: O(1) lookup by option name.
- Cons: no automatic cleanup — attachment deletion would need a reverse sweep
  over the options table; options-table growth; risk of autoload regressions;
  per-attachment state living outside the attachment contradicts the plugin's
  data model.

### Option 3: Old-hash → new-hash chain map

- Pros: append-only writes.
- Cons: N saves produce `a → b → c` chains that need walking and cycle
  detection; losing the attachment identity makes shared-hash ambiguity
  unresolvable; orphaned tails on deletion. Rejected.

### Option 4: Keep every historical extraction directory

- Pros: no redirect logic at all.
- Cons: unbounded disk growth on every save; stale content keeps being served
  as if current; contradicts the existing cleanup design. Rejected.

### Option 5: Custom database table

- Pros: purpose-built indexes.
- Cons: schema creation/migration/uninstall maintenance and plugin-review
  friction for a single 40-byte relation; contradicts the "no custom
  storage" architecture. Rejected.

A single autoloaded option holding a global alias map was excluded outright:
it would load on every request and its read-modify-write cycle races under
concurrent saves.

## Evidence

- Save transaction: `includes/class-exelearning-rest-api.php:375-495` @ `50f0ed5`.
- Random per-extraction hash: `includes/class-elp-reprocessor.php:301-305` @ `50f0ed5`.
- Post meta deleted with the attachment: https://developer.wordpress.org/reference/functions/wp_delete_attachment/
- Data-storage convention: repository `CLAUDE.md` ("Data Storage") @ `50f0ed5`.
- Full design and evaluation matrix: [SDD-0001](../sdd/SDD-0001-stale-content-url-redirects.md).

## Decision

We will persist each retired extraction hash as a **multi-value attachment
post meta row** (`_exelearning_obsolete_hash`) on the attachment that retired
it, written only after a successful save and verified before the old
extraction directory is deleted. The public content endpoint answers a
verified obsolete hash with a **temporary (`302`) same-origin redirect** to
the equivalent validated path under the attachment's current
`_exelearning_extracted` hash. Hashes still current for any attachment are
never aliased, never redirected, and their directories are never deleted by a
sharing attachment's save.

## Consequences

### Positive

- Published content URLs survive edits; redirects always land on the latest
  extraction in one hop.
- Alias data cannot outlive its attachment; no new cleanup surface.
- Zero overhead on successful content requests.
- The save transaction fails open for availability: if alias persistence
  fails, the old directory is retained instead of breaking URLs.

### Negative

- Resolution costs one bounded meta query on the 404 fallback path.
- Legacy identically-content-hashed attachments that were *both* edited leave
  their shared old hash unresolvable (safe 404) — fixing that would require
  changing the public URL contract.

### Neutral

- `wp_postmeta` grows by one small row per successful hash-changing save.

## Risks

- Meta rows manipulated out-of-band (direct SQL) could create ambiguous
  aliases — resolution refuses multi-owner and current-hash aliases, degrading
  to the safe 404.
- A very large number of saves accumulates alias rows; volume is small
  (40-byte values) and bounded per attachment by edit count.

## Validation

- PHPUnit suites `ContentHashAliasesTest`, `StaleContentRedirectTest`, plus
  `ReprocessorTest`/`RestApiTest` extensions (see SDD-0001, Testing strategy).
- Manual verification steps recorded in the implementing PR.

## Follow-up work

- Implement per SDD-0001 (issue
  https://github.com/exelearning/exelearning/issues/2150).
- Possible follow-up: reference-check the `delete_attachment` directory
  cleanup for legacy shared hashes.

## References

- [SDD-0001](../sdd/SDD-0001-stale-content-url-redirects.md)
- https://github.com/exelearning/exelearning/issues/2150
- https://developer.wordpress.org/reference/functions/wp_delete_attachment/
