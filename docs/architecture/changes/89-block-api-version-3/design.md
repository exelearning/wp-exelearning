---
tracking_issue: 89
title: "Move the eXeLearning block to Block API version 3"
authors:
  - "@erseco"
  - "claude-code"
status: implemented
date: 2026-08-04
legacy_id: SDD-0005
implementation_prs: [89]
related_adrs: []
related_changes: ["88-testable-editor-bundle-paths", "88-editor-bootstrap-view-returns-html"]
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# Move the eXeLearning block to Block API version 3 — design

> Historical record. This document was written as `SDD-0005` and is preserved
> whole as the design record for the
> [#89](https://github.com/exelearning/wp-exelearning/pull/89) change. See
> [`docs/architecture/changes/README.md`](../README.md) for the current
> change-document model.

## Summary

`exelearning/elp-upload` declared no `apiVersion`, so it registered as version
1, which WordPress 6.9 deprecates. Because a single API-version-1 block forces
the whole post editor onto the non-iframe path, this was not a local problem:
the plugin was holding every editor it was installed in back from a change
WordPress is completing. Moving to version 3 means the block's `edit` output
really renders inside the editor canvas iframe, which changes where its
stylesheets have to come from and breaks a script that watched the wrong
document.

## Context

WordPress renders the editor canvas in an iframe. It only does so when every
registered block can survive it, which the block's `apiVersion` declares. Ours
said 1 by omission, so the canvas was never iframed on any site with this plugin
active — and `tests/e2e/viewer-enhancements.spec.js` @ `eaa9bae` had that
written into it as an assumption: *"Its presence in the main document (not an
iframed canvas) is what lets the editor script find and sync the button."*

No linter reported the deprecation. It surfaced only when the block's new unit
tests began registering it against the real `@wordpress/blocks` package instead
of a hand-written stub (#88), which emits:

> Block with API version 2 or lower is deprecated since version 6.9. … set the
> `apiVersion` field to 3 and test the block inside the iframe editor.

## Problem statement

Three things stood between the block and the iframed canvas, and only the first
is the one-line part:

1. **No `apiVersion`.** Declared in the JS, and mirrored by `api_version` in
   the PHP `register_block_type()` call so the two agree.
2. **Editor styles arrived through `enqueue_block_editor_assets`**
   (`includes/class-elp-upload-block.php:86-100` @ `eaa9bae`), which puts them
   in the outer admin document. Inside the canvas iframe they would never reach
   the block.
3. **The fullscreen button was wired from outside.**
   `assets/js/elp-upload-fullscreen.js` @ `eaa9bae` attached a `click` listener
   and a `MutationObserver` to the admin document, found the button by
   `[data-type="exelearning/elp-upload"]`, and matched it to the preview iframe.
   Inside the canvas that DOM is in a different document, so the listener never
   fires and the observer never sees the button appear.

## Goals

- The block registers as API version 3 with no deprecation logged.
- Its styles reach it inside the canvas iframe.
- The fullscreen button keeps working, verified inside the iframed editor.
- Existing posts render unchanged.

## Non-goals

- Changing what the block saves. The attributes are untouched, so this is
  presentation-only for existing content.
- The frontend fullscreen button, which is wired by inline JavaScript emitted
  from PHP (`includes/class-elp-upload-block.php:399`,
  `public/class-shortcodes.php:459`) and never used the deleted script.

## Proposed design

**1. Declare the version in both places.** `apiVersion: 3` in
`registerBlockType()`, `'api_version' => 3` in `register_block_type()`. A PHPUnit
test asserts the two agree, because they are declared apart and disagreeing is
silent.

**2. Wrap the output in `useBlockProps()`.** API version 2+ requires the visible
output to hang off one element carrying the props the editor uses to attach the
block to the canvas. `edit()` returns from two branches — the upload placeholder
and the preview — so both get the wrapper, and the hook is called before the
early return so it runs on every render. `InspectorControls` and `BlockControls`
stay siblings of the wrapper: they are Slot/Fill and render into the editor's own
chrome, not into the canvas.

**3. Move the stylesheets onto the block type.** `wp_register_style()` for both
handles, then `'style' => 'exelearning-frontend'` and
`'editor_style' => 'exelearning-block-editor'` on the block type. WordPress
injects a block's declared styles into the canvas iframe; nothing enqueued from
`enqueue_block_editor_assets` gets there. `exelearning-frontend` is still
enqueued globally on `wp_enqueue_scripts` for the shortcode, and WordPress
deduplicates by handle.

The iframe receives the block's declared styles **and their dependencies**, and
nothing else — which makes `dashicons` a dependency of `exelearning-frontend`
rather than an incidental `wp_enqueue_style( 'dashicons' )` elsewhere. The
download and fullscreen buttons draw their icons from that font. Missing it does
not fail loudly: the buttons render as blank boxes in the editor while the
published page, where dashicons is enqueued separately, looks correct. This was
caught in review of this very change, not by any test, which is why the E2E now
asserts the computed `font-family` of an icon inside the canvas instead of
merely that a stylesheet link exists.

**4. Wire the fullscreen button in the component.** The `edit` component already
holds `iframeRef` pointing at the preview, so the button gets an `onClick` that
calls `requestFullscreen()` on it and a `disabled` state for a file with no
preview. `assets/js/elp-upload-fullscreen.js`, its enqueue in
`ExeLearning_Viewer_Enhancements` and its two test files are deleted: the script
existed only for the editor, and there is no version of it that can watch a
document it is not in.

## Affected areas

- [x] Gutenberg block / block assets (`assets/`, `includes/class-elp-upload-block.php`)
- [x] PHP plugin classes (`includes/class-viewer-enhancements.php`)

## Data model or storage impact

Not applicable. No attribute is added, removed or renamed, so existing posts
carry exactly the data the block already reads.

## WordPress hooks/filters impact

No plugin hook changes. `ExeLearning_Viewer_Enhancements` stops attaching to the
core `enqueue_block_editor_assets` action; `docs/HOOKS.md` documents only the
plugin's own `exelearning_*` hooks and is unaffected.

## REST API impact

Not applicable.

## Shortcode/block impact

The block's saved attributes and its server-side render are unchanged, so
`docs/SHORTCODES.md` needs no edit. What changes is only where the editor draws
the block and how its editor assets get there.

## Security considerations

Nothing crosses a new trust boundary. The preview iframe keeps its
`sandbox="allow-scripts allow-same-origin allow-popups"` and its proxied source;
moving the fullscreen call into the component removes a document-wide `click`
listener from the admin screen rather than adding one.

Worth noting for a reviewer: the preview iframe is now an iframe inside the
canvas iframe. Same-origin still holds — both are this site — which is what the
teacher-mode stylesheet injection depends on, and the E2E exercise below
confirms it end to end.

## Privacy considerations

Not applicable.

## Accessibility considerations

The fullscreen button keeps its `aria-label` and gains a real `disabled` /
`aria-disabled` state driven by whether a preview exists, which the deleted
script used to apply asynchronously after observing the DOM. It is now correct
on first render rather than shortly afterwards.

## Internationalization considerations

No new strings.

## Backward compatibility

Full for content. For the editor, the canvas becomes iframed on sites where
this plugin was the only thing holding it back — that is the intent, and it is
the behaviour every other up-to-date block already gets.

## Migration/rollout

None. No stored data or option changes.

## Testing strategy

- `tests/js/elp_upload.test.js`: API version 3 is declared; both `edit()`
  branches wrap their output in exactly one element carrying the block props;
  the sidebar and toolbar stay outside that wrapper; and five tests for the
  fullscreen button, including the vendor-prefixed fallbacks and the disabled
  state.
- `tests/unit/ElpUploadBlockTest.php`: the PHP and JS API versions agree, and
  the block type declares stylesheet handles that are actually registered.
- `tests/unit/ViewerEnhancementsTest.php`: no separate fullscreen script is
  enqueued or registered any more.
- `tests/e2e/viewer-enhancements.spec.js`: rewritten to find the block inside
  `iframe[name="editor-canvas"]`, with a helper that falls back to the page so
  the test describes where the block is rather than assuming. This is the test
  that proves the migration: it toggles the real inspector control in the outer
  sidebar and asserts the button appears **inside the canvas** and takes the
  real preview iframe fullscreen.

The E2E failure before the fullscreen work was the evidence the migration needed
it: `locator('[data-type="exelearning/elp-upload"] .exelearning-block-preview
iframe')` resolved to 0 elements in the main document.

## Acceptance criteria

- [x] `apiVersion: 3` in the JS and `api_version => 3` in PHP, asserted to agree.
- [x] No deprecation logged when the block registers.
- [x] Visible output wrapped in `useBlockProps()` in both branches.
- [x] Styles declared on the block type and registered.
- [x] Fullscreen button verified working inside the iframed editor.
- [x] `make test`, `npm run test:js`, `make test-e2e` and PHPCS pass.

## Open questions

None.

## ADRs required or referenced

| Decision | ADR | Status |
|----------|-----|--------|
| — | — | — |

No ADR. Adopting the current version of a WordPress API is following the
platform, not a durable decision of this plugin's own.

## Evidence

- `@wordpress/blocks` 15.25.0 deprecation notice, emitted when the block
  registers in `tests/js/elp_upload.test.js`.
- [Block API versions](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-api-versions/)
  — version 3 renders `edit` inside the iframed canvas.
- [Block migration for iframe editor compatibility](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-api-versions/block-migration-for-iframe-editor-compatibility/)
- `tests/e2e/viewer-enhancements.spec.js` @ `eaa9bae` — the comment recording
  the non-iframe assumption this change removes.
- `assets/js/elp-upload-fullscreen.js` @ `eaa9bae` — the document-scoped
  listener and observer that the canvas iframe puts out of reach.

## Follow-up tasks

- [ ] Nothing outstanding for this block. `ExeLearning_Editor_Bundle::get_url()`
      remains open from the two #88 designs and is unrelated.

## References

- [`88-testable-editor-bundle-paths`](../88-testable-editor-bundle-paths/design.md),
  [`88-editor-bootstrap-view-returns-html`](../88-editor-bootstrap-view-returns-html/design.md)
  — the coverage work whose real-package block tests surfaced the deprecation.
