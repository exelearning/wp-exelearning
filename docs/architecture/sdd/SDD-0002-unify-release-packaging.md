---
id: SDD-0002
title: "Unify release packaging on .distignore and wp dist-archive"
status: Draft
date: 2026-08-03
related:
  issues: []
  prs: []
  adrs:
    - ADR-0003
  sdds: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# SDD-0002: Unify release packaging on .distignore and wp dist-archive

## Status

Draft

## Summary

Four sibling plugins — `wp-exelearning`, `wp-decker`, `wp-documentate` and
`wp-autofirma` — each build their release ZIP with a different tool, and each
carries both a `.distignore` and a `.gitattributes` that overlap without agreeing.
In three of the four the `.distignore` is not read by anything at all. This design
puts all four on `wp dist-archive` + `.distignore`, and scopes `.gitattributes`
down to the one job only it can do.

This repository is the least affected: it already packages with `wp dist-archive`.
Its share of the work is fixing the rules and recording the decision (ADR-0003) so
the other three can cite it.

## Context

`make package` here builds the ZIP with
`wp dist-archive . <zip> --plugin-dirname=exelearning --force` (`Makefile:557` @
`2d9fcb7`). The other three repositories use `composer archive` (`wp-decker`,
`wp-documentate`) or `git archive` plus a staging script (`wp-autofirma`).

The four repositories share their agent skills, conventions and Makefile shape, so
a maintainer moving between them reasonably expects packaging to work the same
way. It does not.

## Problem statement

Maintainers of the four plugins cannot tell which file controls the release, and
two of the four ship a ZIP that WordPress installs into the wrong directory.

Concretely, measured by building each package:

- **`wp-decker` and `wp-documentate` produce a ZIP with no top-level directory.**
  `composer archive` never adds a path prefix. WordPress then names the plugin
  folder after the ZIP file (`wp-admin/includes/class-wp-upgrader.php:640-643`,
  reached because `WP_PLUGIN_DIR` is a protected directory, with the working
  directory named at `:378` from the package filename). `decker-1.2.3.zip`
  installs into `wp-content/plugins/decker-1.2.3/`, so every release lands in a
  new folder, WordPress treats it as a different plugin and deactivates the
  previous one.
- **Dev files ship.** `wp-documentate` ships `fixtures/`, `docs/` and `scripts/`,
  1.1 MB of a 4.0 MB ZIP, including `propuestagasto.odt.bak` and
  `propuestagasto_OLD.odt`; `wp-decker` ships `jest.config.js` and `SECURITY.md`;
  `wp-autofirma` ships `codecov.yml` and `phpunit-integration.xml.dist`; this
  repository shipped `test-results/`.
- **The dead lists are the good ones.** The `.distignore` files in `wp-decker`,
  `wp-documentate` and `wp-autofirma` list exactly the paths that leak, and
  nothing reads them.
- **Unanchored rules reach into the bundled editor here.** See ADR-0003.

## Goals

- One packaging command across the four repositories, differing only in the slug.
- One exclusion list per repository, with its scope stated in the file.
- Every release ZIP extracts to a directory equal to the plugin slug.
- No development file in any release ZIP, verified by diffing the ZIP file list.

## Non-goals

- Publishing any of the four to the WordPress.org directory.
- Changing the version-bumping flow (`make package VERSION=…` and the
  `sed`-in-place dance) in any repository.
- Changing what `blueprint.json` installs. The Playground previews keep using
  GitHub's source ZIP.

## Current behavior

| Repository | Tool | List actually read | Top-level dir |
|---|---|---|---|
| `wp-exelearning` | `wp dist-archive` | `.distignore` | `exelearning/` |
| `wp-decker` | `composer archive` | `.gitattributes` | none |
| `wp-documentate` | `composer archive` | `.gitattributes` | none |
| `wp-autofirma` | `git archive` + staging | `.gitattributes` | `wp-autofirma/` |

`composer archive` honours `.gitattributes` `export-ignore` and ignores
`.gitignore` entirely, verified on Composer 2.10.2 with a controlled repository:
a `.gitignore`d untracked file was archived, an `export-ignore`d directory was
not, and the archive had no path prefix.

## Proposed design

**Packaging.** Every repository runs, from its `package` target:

```make
./vendor/bin/wp dist-archive . "$(CURDIR)/<slug>-$(VERSION).zip" \
    --plugin-dirname=<slug> --force
```

`wp-cli/dist-archive-command: ^3.1` goes into `require-dev` where it is missing.
The pin matters: 3.2+ requires `wp-cli ^2.13`, which has no stable release, and
3.1.0 shells out to the `zip` binary so no `ext-zip` is needed.

`--plugin-dirname` is what makes `wp-exelearning` extract as `exelearning/`, and
it is what fixes the folder name for `wp-decker` and `wp-documentate`.

**`.distignore`.** Becomes the real list in each repository: pruned of the rules
inherited from `WordPress/plugin-check` that match nothing, extended with the
paths that currently leak, and with root-only rules anchored by a leading slash.

**`.gitattributes`.** Reduced to line-ending normalisation plus a short
`export-ignore` block for tracked development paths (`.agents/`, `.claude/`,
`.github/`, `docs/`, `tests/`), with a header stating that it does not feed the
release. Untracked paths need no rule because `git archive` never sees them.

**`wp-autofirma`** needs `vendor/` inside its ZIP, which is why its script
installs runtime dependencies into a staging tree. It adopts the pattern the two
sibling plugins already use: `composer.json` `post-install-cmd` copies the runtime
library into a committed tree, and a small PSR-4 autoloader loads it when Composer
is absent — `wp-documentate` already vendors the very same
`erseco/autofirma-intermediate-server` this way
(`wp-documentate/includes/autofirma/class-documentate-autofirma-bundled-autoloader.php`).
Its packaging script then reduces to a version check plus the shared command.

## Affected areas

- [x] Build / packaging (`Makefile`, distribution)

Everything else in the template is untouched: no PHP classes, admin UI, public
rendering, block, shortcode, REST endpoint, Media Library integration, upload or
extraction path, content proxy or style registry changes.

## Data model or storage impact

Not applicable — no stored data changes.

## WordPress hooks/filters impact

Not applicable — no hooks added or changed.

## REST API impact

Not applicable — no REST surface changes.

## Shortcode/block impact

Not applicable — no shortcode or block contract changes.

## Security considerations

The change removes files from the distributed package rather than adding any, and
crosses no trust boundary in plugin code. Two points are relevant:

- Pruning unmatched rules removes guards against files that do not exist yet. Each
  repository keeps a deliberate block of rules that must match at any depth —
  `.git`, `node_modules`, `.DS_Store`, `.idea`, `sftp-config.json` — so a
  credential file dropped in a working tree still cannot reach a release.
- `wp-autofirma` moves from packaging `HEAD` to packaging the working tree, so a
  local build from a dirty tree now includes uncommitted changes. In CI the
  checkout is clean and the release workflow already asserts `git diff
  --exit-code -- build`.

## Privacy considerations

Not applicable — no personal data is handled.

## Accessibility considerations

Not applicable — no user-facing surface changes.

## Internationalization considerations

No new strings. The `.distignore` rules that keep `.po`/`.pot` sources out while
shipping `.l10n.php`, `.mo` and the JS `.json` files are preserved verbatim,
including the leading path segment that already anchors them.

## Backward compatibility

For this repository the packaged file list changes by +16 files inside
`dist/static/` (previously stripped by unanchored rules), plus `LICENSE.txt`,
minus `test-results/`. No installed site is affected by a re-packaging change; the
plugin folder name is unchanged.

For `wp-decker` and `wp-documentate` the installed folder name changes from
`<slug>-<version>` to `<slug>`. Sites that installed a previous release from
GitHub keep the old directory and must remove it by hand once; plugin options are
keyed by option name, not by path, so no data is lost.

## Migration/rollout

One pull request per repository, in this order, each verified by diffing the ZIP
file list against the previous build:

1. `wp-exelearning` — rules and documentation only, no command change.
2. `wp-decker` — smallest of the three tool switches.
3. `wp-documentate` — same switch, plus the largest cleanup.
4. `wp-autofirma` — the tool switch plus vendoring the runtime dependency.

Rollback is reverting the pull request; nothing persists outside the repository.

## Testing strategy

Packaging has no unit-test surface. Verification is the ZIP file-list diff shown
in ADR-0003, run for every repository and pasted into its pull request. The
existing `make lint`, `make test` and `make check-plugin` gates continue to apply
and must stay green.

## Acceptance criteria

- [ ] The four repositories build their ZIP with `wp dist-archive` and
      `--plugin-dirname` set to the plugin slug.
- [ ] Every ZIP extracts to a single directory named after the slug.
- [ ] No `tests/`, `docs/`, `fixtures/`, `scripts/`, `node_modules/`,
      `test-results/`, CI config or agent instruction file appears in any ZIP.
- [ ] `LICENSE.txt` appears in all four ZIPs.
- [ ] Each `.gitattributes` states that it does not feed the release ZIP.
- [ ] `wp-autofirma` loads its runtime dependency from the committed tree with no
      `vendor/` directory present.

## Open questions

None outstanding; the three design choices this document rested on were settled
before drafting (tool, `wp-autofirma` dependency handling, shipping `LICENSE.txt`).

## ADRs required or referenced

| Decision | ADR | Status |
|----------|-----|--------|
| `.distignore` is the single source of truth; `.gitattributes` only shapes the source ZIP | [ADR-0003](../adr/ADR-0003-distignore-single-source-of-truth.md) | Proposed |
| The embedded editor is a release artifact | [ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md) | Accepted |

## Evidence

- Packages built from each repository at their current `main`, and their file
  lists compared: `wp-decker` 154 files with no top-level directory,
  `wp-documentate` 128 files / 4.0 MB with no top-level directory,
  `wp-autofirma` 28 entries under `wp-autofirma/`, `wp-exelearning` 3910 entries
  under `exelearning/`.
- `wp-admin/includes/class-wp-upgrader.php:378` and `:640-643` — how WordPress
  derives the installed folder name.
- `vendor/wp-cli/dist-archive-command/src/Dist_Archive_Command.php:77-84` —
  `.distignore` is the only list read.
- Composer 2.10.2 probe repository — `composer archive` ignores `.gitignore`,
  honours `.gitattributes`, adds no prefix.
- `wp-documentate/composer.json` (`copy-runtime-dependencies`) and
  `wp-decker/composer.json` (`post-install-cmd`) — the existing vendoring pattern.

## Follow-up tasks

- [ ] Open the four pull requests in the order listed under Migration/rollout.
- [ ] Once `.gitattributes` no longer carries the blanket `.*` rule, revisit the
      `git checkout-index` workaround in `.github/workflows/ci.yml:127-128`, which
      exists only because that rule hid `.phpcs.xml.dist` from `git archive`.

## References

- [ADR-0003](../adr/ADR-0003-distignore-single-source-of-truth.md)
- [ADR-0002](../adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md)
- [wp-cli/dist-archive-command](https://github.com/wp-cli/dist-archive-command)
- [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy)
  — `.distignore` takes precedence over `.gitattributes`.
