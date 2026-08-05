---
tracking_issue: NNN   # the pull request number that carries this change
title: "Short change title"
status: draft
date: YYYY-MM-DD
implementation_prs: []
related_adrs: []
related_changes: []
external_refs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: ""
  model: ""
---

<!--
How to use this template:
1. Find the change's GitHub tracking NUMBER. Issues are disabled on this
   repository, so it is always the PULL REQUEST number that carries the change.
   That number IS the change's identity — there is no global counter and nothing
   to compute. NEVER open an issue just to get a number.
2. Create `docs/architecture/changes/<number>-<change-slug>/`.
3. Copy the frontmatter above into each document you create, and copy the
   matching section skeleton below into that document.
4. CREATE ONLY THE DOCUMENTS THAT CARRY REAL CONTENT. Empty placeholders are not
   required. A small change may be a single `proposal.md`; a large one may use
   all five (proposal.md, spec.md, design.md, research.md, tasks.md).
5. Do not duplicate content across proposal.md, spec.md and design.md.
6. `implementation_prs` belongs ONLY in the canonical document — the first of
   proposal.md, spec.md, design.md, research.md, tasks.md that exists. CI rejects
   a second copy.
7. `implementation_prs` takes bare numbers of THIS repository. Links to the main
   eXeLearning repository, the Moodle plugin or anywhere else go in
   `external_refs` as full URLs — their numbers come from other sequences.
8. Status lives in the frontmatter only. Do not add a `## Status` section.
9. Mark sections that truly do not apply as "Not applicable" (with a one-line
   reason) rather than deleting them, and delete these guidance comments before
   submitting.
10. Cite a verifiable source for each technical claim (repo path + commit,
    WordPress documentation, benchmark, experiment, PR, or ADR).
11. Capture durable decisions in "ADRs required or referenced" — link an existing
    ADR or mark it "ADR needed".
12. Record AI assistance in `ai_assistance` (values, or `none` if not used).
13. Use PR links for attribution — do not add people's names.
14. Run `make architecture-check` to validate.
Editing is free while draft / in-review. Once implemented, only fix typos/links.
See ./README.md for the full policy.
-->

# Short change title — <document kind>

<!-- ======================================================================
     proposal.md — motivation, problem, scope, goals, non-goals
     ====================================================================== -->

## Summary

<!-- One or two paragraphs: what this changes and why it matters. -->

## Context

<!-- The background a reviewer needs: how the relevant part of the plugin works
today at a high level, and what prompted this change. -->

## Problem statement

<!-- The problem being solved, and who has it (site admins, content authors,
developers integrating with the hooks). -->

## Goals

<!-- What success looks like. Make these testable where possible. -->

## Non-goals

<!-- What this change explicitly does not attempt. -->

<!-- ======================================================================
     spec.md — observable behavior, requirements, scenarios, acceptance
     ====================================================================== -->

## Requirements

<!-- Normative statements. Use must / must not / may. Number them so reviews and
tests can cite them. -->

## Scenarios

<!-- Concrete user-visible, hook-visible or REST-visible scenarios:
given / when / then. -->

## Acceptance criteria

<!-- Concrete, checkable conditions for "done". -->

- [ ] ...

<!-- ======================================================================
     design.md — technical implementation design
     ====================================================================== -->

## Current behavior

<!-- How things work today, in detail. Cite repository paths + commits
(e.g. `includes/class-elp-upload-handler.php` @ `abc1234`). -->

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
- [ ] Embedded editor bundling (`includes/class-editor-bundle.php`, `admin/views/editor-bootstrap.php`, `assets/js/wp-exe-bridge.js`)
- [ ] Style registry (`includes/class-styles-service.php`, `includes/class-style-package.php`)
- [ ] Build / packaging (`Makefile`, distribution)

## Data model or storage impact

<!-- New/changed attachment post meta keys, the
`wp-content/uploads/exelearning/{hash}/` extraction layout, uploaded style
storage under `wp-content/uploads/exelearning-styles/`, options, transients, or
any on-disk structure. Note new keys and how existing data is handled. -->

## WordPress hooks/filters impact

<!-- New or changed actions/filters (all prefixed `exelearning_`). Keep
`docs/HOOKS.md` in sync when this ships. Note any hook whose contract other
plugins may depend on. -->

## REST API impact

<!-- New/changed endpoints under the `exelearning/v1` namespace, their methods,
capabilities, nonce/permission callbacks, request/response shapes, and backward
compatibility. Mark "Not applicable" if the change adds no REST surface. -->

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

## ADRs required or referenced

<!-- List durable decisions. Link an existing ADR, or mark it "ADR needed".
New ADRs are named `ADR-<tracking-number>-<NN>-<decision-slug>.md`. -->

| Decision | ADR | Status |
|----------|-----|--------|
| Example durable decision | ADR-NNN-01 | Proposed |

<!-- ======================================================================
     research.md — evidence, experiments, alternatives, source analysis
     ====================================================================== -->

## Evidence

<!-- The verifiable basis for the design: repo paths + commits, WordPress docs,
benchmarks, reproducible experiments, PRs, ADRs. No technical claim without a
source. -->

## Alternatives considered

<!-- Options that were evaluated and rejected, with the reason. -->

## External prior art

<!-- Primary sources: specifications, official WordPress documentation,
comparable plugins. Cite links; do not paste large excerpts. -->

<!-- ======================================================================
     tasks.md — implementation plan and progress
     ====================================================================== -->

## Follow-up tasks

<!-- The steps to build it, roughly in order, plus any deferred work. Link PRs
when they exist. -->

- [ ] ...

## Open questions

<!-- Unresolved points that reviewers should weigh in on. -->

## References

<!-- All sources cited above, plus related PRs, ADRs and change directories. -->
