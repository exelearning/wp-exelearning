---
tracking_issue: 88
title: "Make the editor bootstrap a view that returns its HTML"
status: implemented
date: 2026-08-04
legacy_id: SDD-0004
implementation_prs: [88]
related_adrs: [ADR-72-01]
related_changes: ["88-testable-editor-bundle-paths"]
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# Make the editor bootstrap a view that returns its HTML — design

> Historical record. This document was written as `SDD-0004` and is preserved
> whole as the design record for the
> [#88](https://github.com/exelearning/wp-exelearning/pull/88) change. See
> [`docs/architecture/changes/README.md`](../README.md) for the current
> change-document model.

## Summary

`admin/views/editor-bootstrap.php` builds the whole embedded editor page — the
REST endpoint and nonce the editor saves through, the `<base>` tag its assets
resolve against, the approved style registry — and then printed it and ended the
process. Nothing about it could be checked, because merely running it tore down
PHPUnit's output buffering and called `exit`. The view now returns its HTML and
the caller owns the side effects. The same split is applied to
`ExeLearning_Export_Bootstrap::maybe_render()`.

## Context

The [`88-testable-editor-bundle-paths`](../88-testable-editor-bundle-paths/design.md)
design removed the first half of
the blocker: tests can now supply an editor bundle instead of depending on
whether the machine had run `make build-editor`. It listed this extraction as a
follow-up, because supplying a bundle does not help when the file under test
ends the process on the way out.

`tests/unit/EditorPageTest.php` @ `22b6676` documented the situation in its own
header: "The template itself cannot run under PHPUnit (it tears down every
output buffer and exits), so these tests cover the guards that decide whether
the request ever gets that far."

## Problem statement

84 executable lines — the entire contract between WordPress and the embedded
editor — had no test. A typo in the injected REST URL, a nonce created for the
wrong action, a `<base>` tag pointing at the wrong directory: each breaks
editing outright, and each would ship unnoticed. The E2E suite does not open the
editor page either (`tests/e2e/` @ `22b6676` covers activation, the shortcode
viewer and viewer enhancements).

The same shape blocked 11 lines of `ExeLearning_Export_Bootstrap::maybe_render()`
@ `22b6676`, where composing the document and sending it were one method.

## Goals

- The editor page can be built, inspected and asserted on from a test.
- The bytes served are unchanged by the refactor.
- Only genuinely process-ending code stays untestable.

## Non-goals

- Moving the 450 lines of inline JavaScript into PHP. They are a template's
  content and belong in a template; relocating them into a class would make
  them harder to read and risk a transcription error in the one file with no
  test to catch it.
- Changing anything the editor receives.

## Proposed design

**The view returns instead of printing.** PHP's `include` evaluates to whatever
the included file returns, so the view stays exactly where it is and becomes a
function of its inputs:

- the `while ( ob_get_level() )` teardown, the headers and the `echo` move out;
- the "no bundle" case returns `false` instead of redirecting and exiting;
- the final `echo $exelearning_template` becomes `return`.

**The caller owns the side effects.** `ExeLearning_Editor` gains
`build_bootstrap_page()` (includes the view and returns its result),
`serve_bootstrap_page()` (decides between redirect and send) and two protected
one-liners, `redirect_and_exit()` and `send_and_exit()`, that hold everything
which ends the process. This is the existing
`ExeLearning_Admin_Styles::finish_request()` pattern
(`admin/class-admin-styles.php:146` @ `22b6676`), which a test subclass
overrides for exactly this reason.

**Export bootstrap, same split.** `maybe_render()` becomes a two-liner over a
public `build_page()` and a protected `send()`.

## Affected areas

- [x] PHP plugin classes (`includes/`)
- [x] Admin UI (`admin/views/editor-bootstrap.php`)
- [x] Embedded editor install/build flow

## Data model or storage impact

Not applicable.

## WordPress hooks/filters impact

Not applicable. No hook added, changed or removed.

## REST API impact

Not applicable. The page still advertises the same `exelearning/v1` base and a
`wp_rest` nonce; what it sends is now asserted rather than assumed.

## Shortcode/block impact

Not applicable.

## Security considerations

The capability and nonce checks stay in `render_editor_page()`, ahead of
everything this design touches, and are unchanged: `wp_verify_nonce` against
`exelearning_editor`, `current_user_can( 'upload_files' )`, an `.elpx`
extension, and `current_user_can( 'edit_post', $attachment_id )`.

Two things are now covered that were not: that the injected nonce actually
verifies against `wp_rest` (a nonce for the wrong action would leave every save
rejected), and that the page reports the signed-in user rather than a stale one.

`send_and_exit()` keeps `X-Content-Type-Options: nosniff` and the explicit
content type. Output escaping is untouched — the same
`WordPress.Security.EscapeOutput` exemption applies to the same assembled
document, now printed one layer up.

## Privacy considerations

Not applicable. The page carries the display name and ID of the user who
requested it, exactly as before.

## Accessibility considerations

Not applicable — the served markup is unchanged, except that a stray `<base>`
no longer appears in the body (see below), which is a validity fix.

## Internationalization considerations

No new user-facing strings.

## Backward compatibility

The served document is byte-identical, with one deliberate exception. The diff
of the view touches only its docblock, the buffer teardown, the bundle-missing
branch and the tail; the data preparation and every injection step are
untouched, so the output cannot differ.

The exception is a defect this work surfaced. The pattern that finds the head
element, `/(<head[^>]*>)/i`, also matches the editor's own
`<header id="head">`, so a second `<base href="…">` was being injected into the
middle of the body on every page. Browsers honour only the first `<base>`, so
nothing was visibly broken, but the markup was invalid. The pattern now uses a
word boundary and replaces once. Verified against the real 125 KB editor bundle:
one `<base>`, in the head, with `<header id="head">` intact.

## Migration/rollout

None. No stored data, no option, no contract.

## Testing strategy

`tests/unit/EditorBootstrapPageTest.php` (new, 16 tests) builds the page against
a fixture bundle and asserts the REST URL, a nonce that verifies, the attachment
and its URL, the signed-in user, the site language, the `<base>` tag, the
rewriting of `./` asset paths, the bridge script, the style registry, the
untitled-attachment fallback, the unreadable-template failure, and the full
front controller from a nonced request to a served document.

It deliberately carries no `@covers` annotation: the subject is a view file
rather than a class, and `@covers` restricts attribution to the named class and
discards everything the view executed — which is the whole point of the file.

`tests/unit/ExportBootstrapPayloadTest.php` gains three tests for `build_page()`.

## Acceptance criteria

- [x] The editor page is built and asserted on in tests.
- [x] The served bytes are unchanged (single-`<base>` fix aside).
- [x] `make test`, `make test-coverage` and PHPCS pass.

## Open questions

None.

## ADRs required or referenced

| Decision | ADR | Status |
|----------|-----|--------|
| `dist/static/` is the only runtime editor source | [ADR-72-01](../../adr/ADR-72-01-bundle-editor-exclusively-in-release-packages.md) | Accepted |

No new ADR. Nothing durable is decided here: the same page is produced by the
same steps, arranged so they can be observed.

## Evidence

- `tests/unit/EditorPageTest.php:5-9` @ `22b6676` — the template "cannot run
  under PHPUnit".
- `admin/class-admin-styles.php:146` @ `22b6676` — the `finish_request()`
  precedent for isolating `exit`.
- `git diff` of the view — four hunks, none in the data preparation or the
  injection steps.
- Rendered against the real bundle: 125,802 bytes in, 147,171 out, one `<base>`
  in the head, no leftover `./` paths.

### Measured outcome

| File | Before | After |
|------|--------|-------|
| `admin/views/editor-bootstrap.php` | 0% (84 uncovered) | 90.8% |
| `includes/class-exelearning-editor.php` | 98.57% | 93.7% |
| `includes/class-export-bootstrap.php` | 86.6% | 90.6% |
| PHP suite | 94.59% | 97.12% |

`class-exelearning-editor.php` drops because this design *adds* the two
side-effect-only methods to it; both are one-liners around `exit`. What remains
uncovered in the view is its `ABSPATH` guard and a `class_exists()` fallback for
a class the plugin always loads.

## Follow-up tasks

- [ ] Add `ExeLearning_Editor_Bundle::get_url()` and route the three remaining
      `EXELEARNING_PLUGIN_URL . 'dist/static'` call sites through it (carried
      over from [`88-testable-editor-bundle-paths`](../88-testable-editor-bundle-paths/design.md)).

## References

- [`88-testable-editor-bundle-paths`](../88-testable-editor-bundle-paths/design.md) —
  the bundle fixture this builds on, which listed this extraction as a follow-up.
- [ADR-72-01](../../adr/ADR-72-01-bundle-editor-exclusively-in-release-packages.md).
