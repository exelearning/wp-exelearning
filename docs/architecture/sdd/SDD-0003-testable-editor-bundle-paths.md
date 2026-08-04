---
id: SDD-0003
title: "Resolve every editor bundle path through one helper, and let tests supply the bundle"
status: Implemented
date: 2026-08-04
related:
  issues: []
  prs: []
  adrs:
    - ADR-0002
  sdds: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# SDD-0003: Resolve every editor bundle path through one helper, and let tests supply the bundle

## Status

Implemented

## Summary

Two classes built their own path to the bundled static editor instead of asking
`ExeLearning_Editor_Bundle`, and no test could point the plugin at a bundle of
its own. The result was a block of code that behaved differently depending on
the machine it ran on: unreachable in CI, and exercised against an arbitrary
editor build locally. This routes both paths through the existing helper and
adds a test-only seam so the suite supplies its own fixture bundle.

## Context

The embedded editor lives in `dist/static/` inside the plugin directory.
[ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md)
makes that the only runtime editor source: release ZIPs ship it, the plugin
never downloads it, and a source checkout has nothing there until
`make build-editor` runs.

`ExeLearning_Editor_Bundle` (`includes/class-editor-bundle.php` @ `690a2d8`)
exists to answer "where is the bundle" and "is it usable", and documents its
`get_plugin_dir()` as resolvable by subclassing "so tests can point the helper
at a fixture directory".

CI never builds the editor. `.github/workflows/ci.yml` @ `690a2d8` runs
`make test-coverage` with no `make build-editor` step, and `.gitignore` @
`690a2d8` excludes `dist/static/`. So under CI the bundle is always absent and
on a developer machine it is usually a full editor build.

## Problem statement

Everything that reads the bundle was untestable, and the tests that touched it
were untrustworthy.

Measured on `main` @ `e9096dd` through the Codecov API, 120 of the 183 uncovered
PHP lines — two thirds of the entire PHP gap — sat behind "is there a bundle":

| Location | Lines | Why unreachable in CI |
|----------|-------|-----------------------|
| `admin/views/editor-bootstrap.php` | 84 | redirects and exits when the bundle is missing |
| `admin/class-admin-settings.php:420-438` | 16 | `list_builtin_themes()` is empty, so the renderer returns early |
| `includes/class-export-bootstrap.php:72-86,145-149` | 14 | `load_editor_template()` calls `wp_die()` without `index.html` |
| `includes/class-styles-service.php:178-183` | 6 | reads `dist/static/data/bundle.json` |

Worse than the coverage number: four tests branched on the environment rather
than asserting a fixed outcome. `tests/unit/AdminSettingsScreenTest.php:94` and
`tests/unit/AdminSettingsTest.php:266` @ `690a2d8` each ran
`if ( ExeLearning_Editor_Bundle::is_available() )` and asserted something
different in each branch, and two tests in
`tests/unit/ExportBootstrapPayloadTest.php` @ `690a2d8` skipped each other out
depending on the same condition — one of them showed up as the suite's single
skipped test on a developer machine. A test whose assertion depends on whether
someone ran `make build-editor` is not testing the plugin.

The blocker is mechanical: `includes/class-styles-service.php:173` and
`includes/class-export-bootstrap.php:140` @ `690a2d8` composed
`EXELEARNING_PLUGIN_DIR . 'dist/static/…'` inline, and the helper's
subclass-based seam cannot intercept a static call to
`ExeLearning_Editor_Bundle::get_path()` from production code.

## Goals

- One place resolves the bundle path; nothing else spells out `dist/static`.
- Every bundled-editor test asserts the same thing in CI, in a source checkout
  and on a machine with a full editor build.
- No test branches on `ExeLearning_Editor_Bundle::is_available()`, and none skip
  because of it.

## Non-goals

- Making `admin/views/editor-bootstrap.php` testable. That template tears down
  every output buffer and ends in `echo`, so it needs its own refactor; this
  design only removes the bundle half of the blocker.
- Covering `ExeLearning_Export_Bootstrap::maybe_render()` lines 72-86, which end
  in `exit` and cannot be driven from PHPUnit.
- Building the editor in CI. That needs Bun and the editor submodule, and would
  add minutes to every run to produce something a fixture models in bytes.
- Any runtime relocation of the editor. See *Security considerations*.

## Current behavior

`ExeLearning_Editor_Bundle::get_path()` returns
`trailingslashit( EXELEARNING_PLUGIN_DIR ) . 'dist/static/'`, and
`is_available()` requires a readable `index.html` plus one of `app/`, `libs/` or
`files/`. `admin/views/editor-bootstrap.php:65` @ `690a2d8` already asked the
helper. `includes/class-styles-service.php:173` and
`includes/class-export-bootstrap.php:140` did not.

## Proposed design

Two changes, both small.

**1. Route the remaining paths through the helper.**

```php
// includes/class-styles-service.php
$bundle_path = ExeLearning_Editor_Bundle::get_path() . 'data/bundle.json';

// includes/class-export-bootstrap.php
$static_index = ExeLearning_Editor_Bundle::get_path() . 'index.html';
```

No behavior change: both compose the same string the helper already returns.

**2. Add a guarded test seam to the helper.**

```php
private static $path_override = null;

public static function set_path_override( $dir ) {
    if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
        return;
    }
    self::$path_override = null === $dir ? null : (string) $dir;
}

protected static function get_plugin_dir() {
    if ( null !== self::$path_override ) {
        return self::$path_override;
    }
    return EXELEARNING_PLUGIN_DIR;
}
```

`WP_TESTS_DOMAIN` is defined by the WordPress test bootstrap and by nothing
else, so on a live site the setter returns without assigning.

**3. A fixture builder for the suite.**

`tests/helpers/class-bundle-fixture.php`, loaded from `tests/bootstrap.php`,
writes a minimal valid bundle to a temporary directory — `index.html`, an `app/`
directory and a `data/bundle.json` declaring two known themes — and points the
helper at it. `create_empty()` models the source checkout; `destroy()` restores
the real directory.

### Alternatives considered

- **A fixture at the real `dist/static/` path, no production change.** Tests
  would still run against a real build when one exists, so assertions about
  bundle contents could only check shape, not values. Rejected: it buys
  coverage and leaves the untrustworthy assertions in place.
- **Parameter injection on the two readers.** Deterministic for those two, but
  `admin/class-admin-settings.php:414` calls `list_builtin_themes()` with no
  arguments, so the styles table stays environment-dependent. Reaches roughly
  20 of the 120 lines.
- **A `exelearning_editor_bundle_path` filter.** Rejected on ADR-0002 grounds;
  see *Security considerations*.

## Affected areas

- [x] PHP plugin classes (`includes/`)
- [x] Admin UI (`admin/`) — tests only
- [x] Embedded editor install/build flow (`includes/class-editor-bundle.php`)
- [x] Style registry (`includes/class-styles-service.php`)

## Data model or storage impact

Not applicable. No option, meta key, transient or on-disk layout changes. The
fixture writes only under `get_temp_dir()` and removes itself.

## WordPress hooks/filters impact

Not applicable — and deliberately so. No hook is added; see *Security
considerations*.

## REST API impact

Not applicable.

## Shortcode/block impact

Not applicable.

## Security considerations

The obvious way to make the path injectable is a filter. That is the option
[ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md)
rejects: it decides that `dist/static/` inside the plugin directory is the only
runtime editor source, and rejects Option 3 ("let administrators supply the
editor") precisely because it decouples the served editor from the reviewed
release. A public filter would reintroduce that, letting any plugin point the
editor at a directory outside the reviewed package — remotely sourced
executable code by another route.

`set_path_override()` is therefore not an extension point:

- It no-ops unless `WP_TESTS_DOMAIN` is defined, which happens only under the
  WordPress test bootstrap.
- It is marked `@internal` and documented as a test seam.
- It fires no hook, so nothing can reach it by filtering.

The override changes only where the plugin *reads* the editor from. It relaxes
no capability check, nonce or escaping, and the bundle validation in
`is_available()` still applies to whatever directory it points at.

## Privacy considerations

Not applicable.

## Accessibility considerations

Not applicable — no rendered output changes. The built-in styles table the new
tests exercise keeps its existing `aria-label` per checkbox
(`admin/class-admin-settings.php:486`).

## Internationalization considerations

No new user-facing strings. One existing assertion was resolving an English
literal and failed once the test site was switched to `es_ES`; it now compares
against `esc_html__()` so it holds in any locale. This is the same class of
environment dependence the rest of this design removes.

## Backward compatibility

Full. Both routed call sites resolve to the byte-identical path they built
inline, and on a live site `set_path_override()` cannot change anything. No
stored data, ELPX package, shortcode, block or hook is affected.

## Migration/rollout

None required. Ships as a normal change; nothing to migrate and nothing to gate.

## Testing strategy

- `tests/unit/StylesServiceBuiltinsTest.php` (new) covers
  `list_builtin_themes()` against a fixture that declares two known themes, plus
  the absent, missing-manifest, empty, corrupt and no-themes cases.
- `tests/unit/AdminSettingsTest.php` gains three tests for the built-in styles
  table: the rows it renders, the cleared checkbox for a disabled built-in, and
  the explanation shown when no editor is bundled.
- The four environment-branching tests are split into pairs that each assert one
  outcome against an explicit fixture.
- `make test` for the suite, `make test-coverage` for the gate
  (`MIN_COVERAGE = 94`), `./vendor/bin/phpcs --standard=.phpcs.xml.dist`.

### Measured outcome

Of the 120 bundle-gated lines, this recovers 25 and leaves 95 to the two
refactors listed under *Follow-up tasks*. The fixture is a precondition for
those 95, not a substitute: `admin/views/editor-bootstrap.php` still has to stop
tearing down output buffering, and `maybe_render()` still ends in `exit`.

| File | Before | After |
|------|--------|-------|
| `admin/class-admin-settings.php` | 94.65% (17 uncovered) | 100% |
| `includes/class-styles-service.php` | 94.87% (13) | 96.8% (8) |
| `includes/class-export-bootstrap.php` | 81.81% (14) | 86.6% (11) |
| `admin/views/editor-bootstrap.php` | 0% (84) | 0% (84) |

"Before" is the Codecov report for `main` @ `e9096dd`; "after" is
`make test-coverage` locally. The suite total is 94.59% and reports no skipped
tests, against 94.37% and one skip before.

One line stays uncovered by construction: the `return` in
`set_path_override()` taken when `WP_TESTS_DOMAIN` is undefined cannot execute
inside a suite that defines it. That is the guard working.

## Acceptance criteria

- [x] No production file outside `includes/class-editor-bundle.php` spells out
      the `dist/static` filesystem path.
- [x] No test calls `ExeLearning_Editor_Bundle::is_available()` to decide what
      to assert, and the suite reports zero skipped tests.
- [x] `set_path_override()` is a no-op when `WP_TESTS_DOMAIN` is undefined.
- [x] `make test` and PHPCS pass.

## Open questions

- Three URL call sites still build `EXELEARNING_PLUGIN_URL . 'dist/static'` by
  hand (`includes/class-export-bootstrap.php:73`,
  `includes/class-download-button-renderer.php:187-188`,
  `admin/views/editor-bootstrap.php:82`). A matching `get_url()` on the helper
  would finish the consolidation. Left out here because the URL does not gate
  testability, and widening the change would blur what this one is for.

## ADRs required or referenced

| Decision | ADR | Status |
|----------|-----|--------|
| `dist/static/` in the plugin directory is the only runtime editor source | [ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md) | Accepted |

No new ADR: this design adds no durable decision of its own, it makes an
existing one enforceable in one place and testable from the suite.

## Evidence

- `includes/class-styles-service.php:173` and
  `includes/class-export-bootstrap.php:140` @ `690a2d8` — the two inline paths.
- `includes/class-editor-bundle.php:64-70` @ `690a2d8` — the helper documenting
  its seam as "so tests can point the helper at a fixture directory".
- `tests/unit/AdminSettingsScreenTest.php:94`,
  `tests/unit/AdminSettingsTest.php:266`,
  `tests/unit/ExportBootstrapPayloadTest.php:146,162` @ `690a2d8` — the four
  environment-dependent tests.
- `.github/workflows/ci.yml` @ `690a2d8` — no `make build-editor` before
  `make test-coverage`; `.gitignore:64` @ `690a2d8` — `dist/static/` ignored.
- Codecov API for `main` @ `e9096dd` — the per-file uncovered line numbers in
  the table above.
- WordPress test bootstrap — `WP_TESTS_DOMAIN` is defined by
  `wp-tests-config.php`, verified under `make test` in this environment
  (`localhost:8889`).

## Follow-up tasks

- [ ] Add `ExeLearning_Editor_Bundle::get_url()` and route the three remaining
      URL call sites through it.
- [ ] Extract the body of `admin/views/editor-bootstrap.php` into a method that
      returns the HTML, so the template stops being a 84-line blind spot.

## References

- [ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md) —
  Bundle the embedded editor exclusively in release packages.
- [`docs/architecture/sdd/records.md`](records.md) — SDD index.
