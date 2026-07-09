---
id: SDD-0000
title: "Short design title"
status: Draft
date: YYYY-MM-DD
related:
  issues: []
  prs: []
  adrs: []
  sdds: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: ""
  model: ""
---

<!--
How to use this template:
1. Copy this file to `SDD-NNNN-short-kebab-case-title.md` with the next free ID.
2. Update the frontmatter above (id, title, date, related links).
3. Fill the relevant sections. Mark sections that truly do not apply as
   "Not applicable" (with a one-line reason) rather than deleting them, and
   delete these guidance comments before submitting.
4. Use an SDD for significant proposals, not small fixes.
5. Cite a verifiable source for each technical claim (repo path + commit,
   WordPress documentation, benchmark, experiment, issue, PR, or ADR).
6. Capture durable decisions in "ADRs required or referenced" — link an existing
   ADR or mark it "ADR needed".
7. Record AI assistance in `ai_assistance` (values, or `none` if not used).
8. Use issue/PR links for attribution — do not add people's names.
Editing is free while Draft / In Review. Once Implemented, only fix typos/links.
See ../README.md for the full policy.
-->

# SDD-0000: Short design title

## Status

Draft

<!-- One of: Draft | In Review | Accepted | Implemented | Superseded | Abandoned.
Keep it in sync with the frontmatter `status`. -->

## Summary

<!-- One or two paragraphs: what this changes and why it matters. -->

## Context

<!-- The background a reviewer needs: how the relevant part of the plugin works
today at a high level, and what prompted this design. -->

## Problem statement

<!-- The problem being solved, and who has it (site admins, content authors,
developers integrating with the hooks). -->

## Goals

<!-- What success looks like. Make these testable where possible. -->

## Non-goals

<!-- What this design explicitly does not attempt. -->

## Current behavior

<!-- How things work today, in detail. Cite repository paths + commits
(e.g. `includes/class-elp-upload-handler.php`). -->

## Proposed design

<!-- The design at a high level, then the detail. Diagrams welcome. Name the
classes/files that will change or be added under includes/, admin/, public/. -->

## Affected areas

<!-- Check/keep the areas this change touches; remove the rest. -->

- [ ] PHP plugin classes (`includes/`)
- [ ] Admin UI (`admin/`)
- [ ] Public rendering (`public/`)
- [ ] Gutenberg block / block assets (`assets/`, `includes/class-elp-upload-block.php`)
- [ ] Shortcode (`public/class-shortcodes.php`)
- [ ] REST endpoints (`includes/class-exelearning-rest-api.php`)
- [ ] Media Library integration (`includes/integrations/`)
- [ ] ELPX upload/extraction (`includes/class-elp-file-service.php`, `includes/class-elp-upload-handler.php`)
- [ ] Content proxy (`includes/class-content-proxy.php`)
- [ ] Embedded editor install/build flow (`includes/class-static-editor-installer.php`, `admin/views/editor-bootstrap.php`, `assets/js/wp-exe-bridge.js`)
- [ ] Style registry (`includes/class-styles-service.php`, `includes/class-style-package.php`)
- [ ] Build / packaging (`Makefile`, distribution)

## Data model or storage impact

<!-- New/changed attachment post meta keys, the
`wp-content/uploads/exelearning/{sha1}/` extraction layout, uploaded style
storage under `wp-content/uploads/exelearning-styles/`, options, transients, or
any on-disk structure. Note new keys and how existing data is handled. -->

## WordPress hooks/filters impact

<!-- New or changed actions/filters (all prefixed `exelearning_`). Keep
`docs/HOOKS.md` in sync when this ships. Note any hook whose contract other
plugins may depend on. -->

## REST API impact

<!-- New/changed endpoints under the `exelearning/v1` namespace, their methods,
capabilities, nonce/permission callbacks, request/response shapes, and backward
compatibility. Mark "Not applicable" if the design adds no REST surface. -->

## Shortcode/block impact

<!-- New/changed `[exelearning]` attributes or block attributes, defaults, and
backward compatibility. Keep `docs/SHORTCODES.md` in sync when this ships.
Mark "Not applicable" if untouched. -->

## Security considerations

<!-- Upload validation, ZIP/path-traversal protection, checksum verification,
capability checks, nonces, output escaping, input sanitization/unslashing, and
the content-proxy security model. State the trust boundaries this change crosses. -->

## Privacy considerations

<!-- Any personal data handled, stored, logged or exposed; retention; and how
uninstall (`uninstall.php`) treats it. Mark "Not applicable" if none. -->

## Accessibility considerations

<!-- Keyboard operation, screen-reader labels, focus management, contrast, and
the embedded editor / preview surfaces. -->

## Internationalization considerations

<!-- New user-facing strings wrapped in `__()`/`esc_html__()` etc. with the
`exelearning` text domain, `/* translators: */` comments for placeholders,
JS/block strings via `wp_set_script_translations`, and updating `.pot`/`.po`/`.mo`
(`make pot`, `make po`, `make mo`). Add translations for every shipped locale. -->

## Backward compatibility

<!-- Impact on existing ELPX packages, stored attachment meta, shortcodes,
blocks and hooks. What keeps working unchanged, and what does not. -->

## Migration/rollout

<!-- Data migration (e.g. reprocessing existing extractions), order of merges,
feature gating, staged enablement, and rollback. -->

## Testing strategy

<!-- Unit (PHPUnit) and, where relevant, E2E (Playwright) coverage. Which flows
get a test, how to run them (`make test`, `make test-e2e`), and the WordPress
Coding Standards / PHPMD / untranslated-string checks (`make lint`, `make phpmd`,
`make check-untranslated`). -->

## Acceptance criteria

<!-- Concrete, checkable conditions for "done". -->

- [ ] ...

## Open questions

<!-- Unresolved points that reviewers should weigh in on. -->

## ADRs required or referenced

<!-- List durable decisions. Link an existing ADR, or mark it "ADR needed". -->

| Decision | ADR | Status |
|----------|-----|--------|
| Example durable decision | ADR-XXXX | Proposed |

## Evidence

<!-- The verifiable basis for the design: repo paths + commits, WordPress docs,
benchmarks, reproducible experiments, issues, PRs, ADRs. No technical claim
without a source. -->

## Follow-up tasks

<!-- The steps to build it, roughly in order, plus any deferred work. Link
issues/PRs when they exist. -->

- [ ] ...

## References

<!-- All sources cited above, plus related issues, PRs, ADRs and SDDs. -->
