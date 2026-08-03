---
id: ADR-0003
title: "Make .distignore the single source of truth for the release ZIP"
status: Proposed
date: 2026-08-03
related:
  issues: []
  prs: []
  sdds:
    - SDD-0002
  adrs:
    - ADR-0002
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# ADR-0003: Make .distignore the single source of truth for the release ZIP

## Status

Proposed

## Context

The repository carries two exclusion lists that look interchangeable and are not:

- `.distignore` is the only list `wp dist-archive` reads
  (`vendor/wp-cli/dist-archive-command/src/Dist_Archive_Command.php:77-84`), and
  `make package` builds the release ZIP with that command
  (`Makefile:557` @ `2d9fcb7`).
- `.gitattributes` `export-ignore` rules are read by `git archive`, which is what
  GitHub serves at `archive/refs/heads/*.zip` — the URL `blueprint.json` installs
  in WordPress Playground (`blueprint.json` @ `2d9fcb7`).

Before this decision both files listed roughly the same paths, 45 rules in
`.gitattributes` against 81 in `.distignore`, with only 41 in common and two rules
duplicated verbatim in `.gitattributes` (`README.md`, `phpunit.xml.dist`). The
overlap invited maintaining them as mirrors of each other, which is wrong in both
directions: a rule added to `.gitattributes` does not affect the release, and a
rule added to `.distignore` does not affect the Playground preview.

Roughly 35 rules in `.distignore` matched nothing in this repository. They were
inherited by copying the file from `WordPress/plugin-check`, which is visible in
the leftovers (`plugin-check.iml`, `behat.yml`, `.gherkin-lintrc`,
`/phpcs-sniffs/`, `phpstan.neon`).

The same two files, with the same drift, exist in `wp-decker`, `wp-documentate`
and `wp-autofirma`. SDD-0002 covers unifying all four.

## Problem

Which file decides what ships, and what is the other one for?

## Decision drivers

- **Correctness of the release ZIP.** It is the artifact users install; the source
  ZIP is a convenience.
- **Generated files must ship.** `dist/static/` (the bundled editor, ADR-0002) and
  the runtime translations are kept out of the repository by `.gitignore`, so any
  packaging method that reads git rather than the working tree cannot produce a
  valid release.
- **One list to maintain.** Two overlapping lists drift, and the drift is silent.
- **WordPress.org compatibility.** If the plugin is ever published there, the
  standard deploy action prefers `.distignore` and falls back to `.gitattributes`.
- **Faithfulness of the bundled editor.** What is packaged under `dist/static/`
  should equal what `make build-editor` produced.

## Alternatives considered

### A. `.gitattributes` + `git archive` as the single mechanism

Rejected. `git archive` only sees committed files, so the 92 MB editor build and
the generated runtime translations would be absent. Producing a valid ZIP would
require a staging script that copies the generated files back in — the
complexity this repository already removed when it adopted `wp dist-archive`.

### B. Keep both lists, generated from one source and checked in CI

Rejected as disproportionate. It adds a generator and a CI gate to keep a file in
sync that only shapes a preview ZIP.

### C. `.distignore` decides what ships; `.gitattributes` is scoped down

Chosen.

## Decision

`.distignore` is the single source of truth for the release ZIP. `.gitattributes`
keeps only line-ending normalisation and a short, stable set of `export-ignore`
rules whose sole purpose is shaping the source ZIP GitHub generates. The two files
are not mirrors and must not be synchronised with each other; both say so in a
header comment.

Two consequences are recorded as rules:

1. **Root-only `.distignore` rules carry a leading slash.** Matching in
   `wp dist-archive` is delegated to `inmarelibero/gitignore-checker`, which
   compiles every rule with the regex `i` flag and matches at any depth. An
   unanchored rule therefore reaches inside the bundled editor. This was not
   theoretical: `build`, `README.md`, `CHANGELOG.md` and `package.json` were
   silently stripping 16 files out of `dist/static/`, among them
   `dist/static/libs/yjs/build/` (7 files), `dist/static/CHANGELOG.md` and four
   third-party `README.md` files. An earlier instance of the same bug is recorded
   in the `LICENSE.txt` comment that this ADR's change replaces.
2. **`.gitattributes` lists only tracked paths.** `node_modules/`, `vendor/`,
   `dist/` and `artifacts/` are untracked, so `git archive` never sees them and
   rules for them are noise.

## Consequences

### Positive

- One list to maintain, and its scope is stated in the file itself.
- The packaged editor is now a faithful copy of `dist/static/`.
- `LICENSE.txt` ships (it did not before), and `test-results/` no longer does.
- `.gitattributes` drops from 45 rules to 6.

### Negative

- The source ZIP and the release ZIP no longer contain the same files. Anyone
  wanting a runnable plugin must take the release ZIP, which is already true
  today because the source ZIP has never contained the bundled editor.
- Pruning the unmatched rules removes guards against files that do not exist yet.
  A short block of rules that must match at any depth (`.git`, `node_modules`,
  `.DS_Store`, `.idea`, `sftp-config.json`) is kept deliberately for that reason.

### Neutral

- `make package` is unchanged; this ADR records the division of labour and fixes
  the rules, not the command.

## Verification

Build the ZIP before and after and diff the file lists, which is what caught both
regressions:

```bash
./vendor/bin/wp dist-archive . /tmp/before.zip --plugin-dirname=exelearning --force
# apply the change
./vendor/bin/wp dist-archive . /tmp/after.zip --plugin-dirname=exelearning --force
diff <(unzip -l /tmp/before.zip | awk '{print $4}' | sort) \
     <(unzip -l /tmp/after.zip  | awk '{print $4}' | sort)
```

## References

- `Makefile:557` @ `2d9fcb7` — the packaging command.
- `vendor/wp-cli/dist-archive-command/src/Dist_Archive_Command.php:77-84` —
  `.distignore` is the only list read.
- [ADR-0002](ADR-0002-bundle-editor-exclusively-in-release-packages.md) — the
  bundled editor is a release artifact.
- [SDD-0002](../sdd/SDD-0002-unify-release-packaging.md) — the cross-repository
  design this decision belongs to.
- [10up/action-wordpress-plugin-deploy](https://github.com/10up/action-wordpress-plugin-deploy)
  — `.distignore` takes precedence, `.gitattributes` is the fallback.
