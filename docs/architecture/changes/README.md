# Architecture changes

## Purpose

A **change** is one unit of significant technical work, identified by its GitHub
tracking number. Its documents describe *what* will be built, *why*, *how* it
will be validated, and *how* it affects the plugin: goals, non-goals, the
proposed design, data/storage impact, security, accessibility,
internationalization, backward compatibility, testing and rollout — agreed
**before** implementation starts.

Change documents make a big change reviewable as a whole, instead of arriving as
a large pull request that reviewers must reverse-engineer.

## A note on the term "SDD"

These documents were previously called **Software Design Documents (SDD)** and
numbered `SDD-NNNN`. That name is retired for two reasons: the global counter it
used was unsafe on parallel branches, and "SDD" is increasingly used across the
wider ecosystem to mean **Spec-Driven Development**, which is a different thing.
The main repository retired it in
[`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232);
this plugin follows.

Use **"change document"**, **"design document"**, or simply the concrete filename
(`design.md`, `proposal.md`) instead. Existing documents are preserved;
[`../migration-map.md`](../migration-map.md) maps each retired identifier to its
current path.

## Changes vs ADRs

| Artifact | Answers | Lifetime |
|----------|---------|----------|
| **Change document** | *What* will be built and *how* it will be implemented | May become historical once implemented |
| **ADR** | *Which* durable decision was made and *why* | Long-lived, append-only |

A change is a **design**; an [ADR](../adr/README.md) is a **decision**. A single
change often contains several durable decisions (a storage choice, a
compatibility guarantee, a security boundary). Those belong in ADRs so they
outlive the feature work — the change then links to them via `related_adrs`
instead of burying them in prose.

A change document is **not a substitute for an ADR**: when a design introduces a
long-lived technical decision, capture that decision in an ADR (existing or newly
proposed) and link it from the *ADRs required or referenced* table.

> Every significant proposal may start with a change directory. Every durable
> architectural decision inside it should either link to an existing ADR or
> propose a new one — but do **not** create one ADR per section.

## Identification and layout

One directory per change:

```text
docs/architecture/changes/<tracking-number>-<change-slug>/
```

```text
docs/architecture/changes/68-stale-content-url-redirects/
docs/architecture/changes/86-unify-release-packaging/
```

- **A GitHub tracking number is required.** It is the change's issue when it has
  one, and its pull request when it does not — GitHub draws both from a single
  repository-wide sequence, so they never collide.

  > **In this repository the tracking number is always a pull request number.**
  > Issues are disabled on `exelearning/wp-exelearning`. A cross-repository issue
  > (for example in the main eXeLearning repository) comes from a different
  > sequence and cannot identify a change here; link it under `external_refs`.

- `<change-slug>` is lowercase kebab-case.
- **One tracking number may own more than one change directory**, for the same
  reason it may own more than one ADR: a single pull request sometimes delivers
  two independent designs. `88-testable-editor-bundle-paths` and
  `88-editor-bootstrap-view-returns-html` both shipped in
  [#88](https://github.com/exelearning/wp-exelearning/pull/88) and are separate
  designs, not two halves of one. Do not merge unrelated designs into one
  directory to force a one-to-one mapping — the slug keeps them distinguishable,
  and the index sorts by number and then by slug.
- Every document in the directory carries `tracking_issue`, and it must match the
  directory prefix. CI enforces this.

### Documents

| File | Responsibility |
|------|----------------|
| `proposal.md` | Motivation, problem, scope, goals, non-goals |
| `spec.md` | Observable behavior, requirements, scenarios, acceptance criteria |
| `design.md` | Technical implementation design |
| `research.md` | Evidence, experiments, alternatives, source analysis |
| `tasks.md` | Implementation plan and progress |

**Create only the files that carry real content.** Empty placeholders are not
required and should not be added to complete the set. A small change may be a
single `proposal.md`; a large one may use all five.

**Do not duplicate the same content** across `proposal.md`, `spec.md` and
`design.md`. Each answers a different question.

### Canonical metadata

Mutable change-level metadata (`title`, `status`, `implementation_prs`,
`related_adrs`) lives in exactly one file: the **first** of `proposal.md`,
`spec.md`, `design.md`, `research.md`, `tasks.md` that exists in the directory.
Other documents may repeat `tracking_issue`, `title`, `status` and `date`, but
must not declare `implementation_prs` — that would create a second source of
truth, and CI rejects it.

```yaml
tracking_issue: 68
title: "Stale content URL redirects to the latest ELPX extraction"
status: accepted
date: 2026-07-10
implementation_prs: [68]
related_adrs: [ADR-68-01]
external_refs:
  - https://github.com/exelearning/exelearning/issues/2150
```

`implementation_prs` is **traceability metadata**: it lists every PR of this
repository that implements the change. The change's identity is the single,
stable value in `tracking_issue`, not the growing list in `implementation_prs`.

`external_refs` holds full URLs to issues and pull requests in *other*
repositories — the main eXeLearning repository, the Moodle plugin, and so on.
Their numbers come from other sequences and must never be written as bare
numbers.

The frontmatter records tools and links, **not** the names of people — use PR
links for attribution.

- [`template.md`](template.md) is the canonical starting point.
- There is **no committed index**. Run `make architecture-records` to print one.

## Status values

| Status | Meaning |
|--------|---------|
| `draft` | Being written; not yet ready for review. |
| `in-review` | Under review; open for feedback. |
| `accepted` | Design agreed; implementation may start. |
| `implemented` | The design has shipped. Kept as a historical record. |
| `superseded` | Replaced by a newer change (see `superseded_by`). |
| `abandoned` | Dropped before implementation. Kept for the record. |

Status lives in the frontmatter **only**. Do not add a `## Status` section.

A change can be edited freely while it is `draft` or `in-review`. Once
`implemented`, avoid rewriting it except for typo/link fixes. If the design
changes substantially, create a new change directory under a new tracking number
and mark the previous one `superseded`. Implemented changes are **preserved as
historical design records** — do not delete them.

## When a change document is required

Write one for work that needs a design gate before implementation:

- significant new features;
- major refactors or rewrites of a subsystem;
- cross-cutting changes (ELPX upload/extraction, the embedded editor install
  flow, the content proxy, the style registry, Media Library integration);
- security-sensitive changes (upload validation, path-traversal protection,
  checksums, capabilities, nonces, the content-proxy security model);
- data or storage changes (attachment post meta, the
  `wp-content/uploads/exelearning/` layout, uploaded style storage);
- REST API changes, or changes to shortcode/block attribute contracts;
- build or distribution changes (packaging, the editor build/install flow);
- proposals with multiple implementation phases.

## When it is recommended

A change document is worth writing, even when not strictly required, when a
change is small in code but wide in impact — for example a new hook contract
other plugins will depend on, or a change that alters how existing ELPX packages
are stored or served.

## When it is not required

Skip it for:

- bug fixes and small enhancements;
- localized changes with an obvious implementation;
- translation-only or test-only changes;
- work already fully covered by an existing, current change document.

A durable decision that needs no full design can go straight to an
[ADR](../adr/README.md).

## Recording tests, validation, rollout and compatibility

Every change document must address, or explicitly mark as not-applicable:

- **Testing strategy** — which PHPUnit suites and, where relevant, Playwright
  specs will cover the change; how coverage is verified.
- **Backward compatibility** — impact on existing ELPX packages, stored meta,
  shortcodes, blocks and hooks; whether a migration is needed.
- **Migration/rollout** — order of merges, feature gating, and rollback.
- **Security, privacy, accessibility and internationalization** — the
  cross-cutting concerns a WordPress plugin must not skip.

## Relationship to OpenSpec and spec-driven workflows

Tools such as [OpenSpec](https://github.com/Fission-AI/OpenSpec) and
[GitHub Spec Kit](https://github.com/github/spec-kit) organize each change as a
self-contained folder carrying its proposal, specs, design and tasks. This layout
is deliberately modelled on that pattern, and the per-change directory names
follow the same kebab-case convention.

This plugin does **not** adopt OpenSpec or any external spec tool as a
dependency. They are referenced as prior art; adopting one formally would be its
own architecture decision, and would need its own ADR.

## Evidence and traceability

As with ADRs, technical claims should cite a verifiable source: a repository path
plus commit, official WordPress documentation, a benchmark, a reproducible
experiment, or a linked PR or ADR. Put evidence in `research.md` when there is
enough of it to warrant its own document; otherwise keep it inline.

## AI-assisted change documents

If an AI tool helped draft or research a document, disclose it in the
frontmatter:

```yaml
ai_assistance:
  tool: "Claude Code"        # tool / interface used
  model: "claude-opus-5"     # model, when relevant
```

If no AI tool was involved, set both fields to `none`.

## Referencing changes

- **From a PR:** mention the change directory, e.g.
  `changes/68-stale-content-url-redirects`.
- **From an ADR:** list the directory name under `related.changes`.
- **From docs or code:** link the concrete file,
  `[design](changes/68-stale-content-url-redirects/design.md)`.

Retired identifiers must not appear in new content. CI fails on them; use
[`../migration-map.md`](../migration-map.md) to find the current path.

## Tooling

| Command | What it does |
|---|---|
| `make architecture-check` | Validates identifiers, metadata and cross-references. Non-zero on failure. |
| `make architecture-records` | Prints the ADR and change indexes, derived from frontmatter. |

Both are thin wrappers around `node bin/architecture-records.mts`, also available
as `composer architecture-check` / `composer architecture-records`.

## Workflow

1. Identify the change's **GitHub tracking number** — the pull request that
   carries it. Do not open an issue just to get a number; you cannot here anyway.
2. Create `docs/architecture/changes/<number>-<change-slug>/`.
3. Copy the relevant sections of [`template.md`](template.md) into the documents
   you actually need. Start at `status: draft`.
4. Capture durable decisions as [ADRs](../adr/README.md) named
   `ADR-<number>-<NN>-<decision-slug>.md`, and list them in `related_adrs` and in
   the *ADRs required or referenced* table.
5. Run `make architecture-check` to validate. `make architecture-records` prints
   the current index if you want to read it.
6. Open (or reference) the PR and move to `in-review`.
7. On approval, set `accepted` and implement. When it ships, set `implemented`
   and record the PRs in `implementation_prs`.

## Review checklist

- [ ] The change has a tracking number (its pull request), and the directory
      uses it.
- [ ] Every document's `tracking_issue` matches the directory.
- [ ] Only documents with real content exist; no empty placeholders.
- [ ] Content is not duplicated across `proposal.md`, `spec.md` and `design.md`.
- [ ] Goals and non-goals are explicit.
- [ ] Data/storage, security, privacy, accessibility, i18n, compatibility and
      testing are addressed (or marked not-applicable).
- [ ] Durable decisions are captured as ADRs and listed in `related_adrs`.
- [ ] Every technical claim cites a verifiable source.
- [ ] `status` reflects reality, and appears only in the frontmatter.
- [ ] `ai_assistance` is filled in (values or `none`).
- [ ] Cross-repository links are in `external_refs` as full URLs.
- [ ] `make architecture-check` passes.
