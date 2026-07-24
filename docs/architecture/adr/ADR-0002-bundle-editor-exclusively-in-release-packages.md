---
id: ADR-0002
title: "Bundle the embedded editor exclusively in release packages"
status: Accepted
date: 2026-07-24
related:
  issues: []
  prs:
    - https://github.com/exelearning/moodle-mod_exelearning/pull/106
  sdds: []
  adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-fable-5"
---

# ADR-0002: Bundle the embedded editor exclusively in release packages

## Status

Accepted

## Context

The plugin embeds the static eXeLearning editor from `dist/static/` inside the
plugin directory (`admin/views/editor-bootstrap.php` @ `bda12cb`). Until this
decision, that directory could be populated in two ways:

1. **Release packaging.** The release workflow builds the editor from the
   matching editor tag and packages it into the official release ZIP
   (`.github/workflows/release.yml` @ `bda12cb`), and a scheduled workflow cuts
   a new plugin release whenever a new editor release is published
   (`.github/workflows/check-editor-releases.yml` @ `bda12cb`).
2. **Runtime installation.** `ExeLearning_Static_Editor_Installer`
   (`includes/class-static-editor-installer.php` @ `bda12cb`) let an
   administrator download the latest static editor ZIP from GitHub Releases
   from **Settings → eXeLearning**, with GitHub API release discovery, SHA-256
   digest verification, ZIP validation, extraction, atomic install with backup
   and rollback, a concurrency-guard transient
   (`exelearning_installing_editor`), a stored metadata option
   (`exelearning_static_editor`) and three lifecycle hooks
   (`exelearning_before_editor_install`, `exelearning_after_editor_install`,
   `exelearning_editor_install_failed`).

The runtime path duplicates what the release pipeline already guarantees, and
it has real costs:

- Downloading executable code at runtime is exactly what plugin-directory
  reviewers flag as *remotely sourced executable code*; the equivalent Moodle
  plugin removed its runtime installer for this reason
  (exelearning/moodle-mod_exelearning#106).
- Two sites reporting the same plugin version can serve different editor
  builds, which breaks support and diagnostics.
- The installer is a permanent security and maintenance surface: network, TLS,
  GitHub API parsing, ZIP extraction, filesystem rollback and locking — all to
  reproduce a file tree the release ZIP already contains.
- The plugin directory must be writable by PHP for the installer to work,
  which contradicts hardened WordPress deployments where `wp-content/plugins`
  is read-only.

WordPress Playground has its own bootstrap: `blueprint.json` @ `bda12cb`
already downloads a pinned editor release asset (`exelearning-static-v4.0.2.zip`)
and unpacks it into the plugin's `dist/` before use, entirely at blueprint
level, without touching the runtime installer.

## Problem

Which distribution mechanism(s) should be supported for the embedded editor,
and may the plugin ever download editor code at runtime?

## Decision drivers

- Plugin-review constraints: no remotely sourced executable code at runtime.
- Security: minimize network, ZIP-extraction and filesystem attack surface.
- Reproducibility: one plugin version ↔ one known editor build.
- Operational simplicity for administrators.
- Development and Playground workflows must remain viable.

## Alternatives considered

### Option 1: Keep the runtime installer (status quo)

- Pro: a source checkout can self-provision the editor from the admin UI.
- Con: remotely sourced executable code; version drift between sites; large
  security surface; requires a writable plugin directory.

### Option 2: Keep the installer but disable it by default

- Pro: escape hatch for unusual setups.
- Con: all the code and its attack surface remain shipped and reviewable; a
  single option flip re-enables remote code download. Rejected.

### Option 3: Let administrators upload an editor ZIP manually

- Pro: no network access from the plugin.
- Con: still decouples the served editor from the reviewed release, keeps the
  ZIP-extraction/rollback machinery, and adds an upload surface. Rejected.

### Option 4: Load the editor directly from a remote URL (CDN)

- Con: remote executable code on every page load, availability coupling, and
  breaks the offline/PWA design of the static editor. Rejected.

### Option 5: Bundle the editor exclusively in release packages (chosen)

- Pro: every byte of editor code served by the plugin is part of the reviewed
  release ZIP; single editor version per plugin version; the installer's
  entire surface is deleted; works on read-only plugin directories.
- Con: updating the editor requires publishing a plugin release (already
  automated by `check-editor-releases.yml`); source checkouts must run
  `make build-editor` (already required for wp-env, `Makefile` @ `bda12cb`).

## Evidence

- `includes/class-static-editor-installer.php` @ `bda12cb` — the runtime
  downloader this ADR removes.
- `.github/workflows/release.yml` and `.github/workflows/check-editor-releases.yml`
  @ `bda12cb` — release ZIPs already bundle the editor built from the matching
  tag, and a new editor release automatically produces a new plugin release.
- `blueprint.json` @ `bda12cb` — Playground provisions the editor at blueprint
  level from a pinned release asset, not through the plugin installer.
- exelearning/moodle-mod_exelearning#106 — the same architectural change in
  the Moodle plugin, motivated by marketplace review of remotely sourced
  executable code.
- [Detailed Plugin Guidelines §8](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
  — "Plugins may not send executable code via third-party systems … Executing
  outside code within a plugin when not acting as a service" is prohibited.

## Decision

We will treat the embedded editor exclusively as a release artifact:

- Official release ZIPs are the only supported distribution mechanism for the
  embedded editor; `dist/static/` inside the plugin directory is the only
  runtime editor source.
- The plugin never downloads editor code at runtime. The runtime installer,
  its admin-post action, settings UI button, stored option
  (`exelearning_static_editor`), concurrency transient and the three
  `exelearning_*_editor_install*` hooks are removed.
- When the bundle is absent or invalid, embedded editing is disabled cleanly:
  the editor screen redirects to the settings page with an explanatory notice,
  and client-side export formats are marked unavailable.
- `make package` refuses to produce a ZIP when `dist/static/index.html`, the
  expected asset directories, or a non-empty `.editor-version` are missing.
- WordPress Playground keeps fetching the editor at blueprint level, pinned to
  an exact release tag, before the plugin is used.

## Consequences

### Positive

- All executable editor code served by the plugin is part of the reviewed
  release package, satisfying plugin-review expectations.
- A plugin version corresponds to one known editor version.
- Reduced attack surface: no runtime network, TLS, GitHub API, ZIP extraction,
  rollback or locking code paths.
- Simpler settings screen and runtime architecture; works with read-only
  plugin directories.

### Negative

- Updating the editor requires publishing a new plugin release (mitigated by
  the automated `check-editor-releases.yml` workflow).
- Development checkouts do not contain `dist/static/` until `make build-editor`
  runs (already the documented workflow).

### Neutral

- The `exelearning_static_editor` option and `exelearning_installing_editor`
  transient are dropped by a DB migration; nothing else persists.
- A previously self-installed `dist/static/` copy is simply replaced on the
  next plugin update, which is the intent of this decision.

## Risks

- A release could theoretically be packaged without the editor if the guard is
  bypassed; the `make package` validation makes this loud and non-zero-exit.
- Sites that relied on the installer to fix a broken `dist/static/` must
  reinstall the plugin package instead; the settings notice explains this.

## Validation

- PHPUnit covers: a valid bundle detected, an absent bundle disabling editing
  cleanly, an invalid bundle (missing asset directories) rejected, and the
  bundled version marker being read.
- `make package` fails with a clear stderr message and non-zero exit when
  `dist/static/` or `.editor-version` is missing or empty, and produces no
  partial ZIP.

## Follow-up work

- None beyond the implementing PR; the Playground blueprint already conforms.

## References

- exelearning/moodle-mod_exelearning#106 — equivalent decision in the Moodle
  plugin.
- `docs/architecture/adr/records.md` — ADR index.
- [WordPress Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
