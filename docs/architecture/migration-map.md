# Architecture record migration map

This page maps every retired architecture identifier to its current location.

Identifiers were migrated from a globally sequential counter (`ADR-NNNN`,
`SDD-NNNN`) to **tracking-number-based** identifiers, following the decision
taken in the main repository,
[`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232)
(`ADR-2232-01`, *Identify architecture records by tracking issue instead of a
global counter*). The rationale, the rejected alternatives and the evidence live
there. This page records what changed **here**, and the deviations this
repository needs.

**Retired identifiers must not be used in new content.** `make architecture-check`
fails when one appears outside this page and the `legacy_id` frontmatter field.
Use the tables below to find the current identifier.

## Every tracking number here is a pull request number

Issues are **disabled** on `exelearning/wp-exelearning`:

```console
$ gh issue list --repo exelearning/wp-exelearning
the 'exelearning/wp-exelearning' repository has disabled issues
```

Work for this plugin is coordinated from the main
[`exelearning/exelearning`](https://github.com/exelearning/exelearning)
repository. Those issue numbers come from a *different* repository-wide sequence,
so they cannot identify a record here — `#2150` in the main repository and `#2150`
here would be two unrelated things. The number that identifies a record in this
repository is therefore always the **pull request** that carries it, and
cross-repository links are preserved under `external_refs` as full URLs.

Every tracking number below was established from the squash-merge commit subject
of the pull request that introduced the record (`git log --follow`), cross-checked
with `gh pr view`. No issue was opened for this migration.

## Architecture Decision Records

| Old identifier | New identifier | Tracking PR | Current path |
|---|---|---|---|
| `ADR-0001` | `ADR-68-01` | [#68](https://github.com/exelearning/wp-exelearning/pull/68) | [`adr/ADR-68-01-resolve-obsolete-hashes-via-attachment-post-meta.md`](adr/ADR-68-01-resolve-obsolete-hashes-via-attachment-post-meta.md) |
| `ADR-0002` | `ADR-72-01` | [#72](https://github.com/exelearning/wp-exelearning/pull/72) | [`adr/ADR-72-01-bundle-editor-exclusively-in-release-packages.md`](adr/ADR-72-01-bundle-editor-exclusively-in-release-packages.md) |
| `ADR-0003` | `ADR-86-01` | [#86](https://github.com/exelearning/wp-exelearning/pull/86) | [`adr/ADR-86-01-make-distignore-single-source-of-truth.md`](adr/ADR-86-01-make-distignore-single-source-of-truth.md) |

Slugs were rewritten where the original named the mechanism or the topic rather
than the decision:

- `ADR-0001-obsolete-hash-alias-storage` → `ADR-68-01-resolve-obsolete-hashes-via-attachment-post-meta`
- `ADR-0003-distignore-single-source-of-truth` → `ADR-86-01-make-distignore-single-source-of-truth`

`ADR-0002` already named a decision, so its slug is unchanged.

`ADR-0002` was later touched by [#88](https://github.com/exelearning/wp-exelearning/pull/88);
the identifier follows the pull request that **introduced** the record, not the
last one to edit it.

Every file kept its content. Only the frontmatter identifiers, the H1, the
cross-references and the duplicated `## Status` section changed. Renames were made
with `git mv`, so `git log --follow` still resolves the full history.

Each tracking number here owns exactly one ADR, so every local sequence is `-01`.
The original relative order is preserved trivially: `ADR-0001` < `ADR-0002` <
`ADR-0003` map to `68` < `72` < `86`.

## Software Design Documents

Design documents moved out of `docs/architecture/sdd/` into per-change directories
under `docs/architecture/changes/`. Each one moved **whole** into `design.md`:
all five were already written as complete designs, and splitting a shipped design
record across `proposal.md` / `spec.md` / `design.md` would rewrite history for no
benefit.

| Old identifier | Current path | Tracking PR |
|---|---|---|
| `SDD-0001` | [`changes/68-stale-content-url-redirects/design.md`](changes/68-stale-content-url-redirects/design.md) | [#68](https://github.com/exelearning/wp-exelearning/pull/68) |
| `SDD-0002` | [`changes/86-unify-release-packaging/design.md`](changes/86-unify-release-packaging/design.md) | [#86](https://github.com/exelearning/wp-exelearning/pull/86) |
| `SDD-0003` | [`changes/88-testable-editor-bundle-paths/design.md`](changes/88-testable-editor-bundle-paths/design.md) | [#88](https://github.com/exelearning/wp-exelearning/pull/88) |
| `SDD-0004` | [`changes/88-editor-bootstrap-view-returns-html/design.md`](changes/88-editor-bootstrap-view-returns-html/design.md) | [#88](https://github.com/exelearning/wp-exelearning/pull/88) |
| `SDD-0005` | [`changes/89-block-api-version-3/design.md`](changes/89-block-api-version-3/design.md) | [#89](https://github.com/exelearning/wp-exelearning/pull/89) |

Newly written changes use the full document set described in
[`changes/README.md`](changes/README.md). Existing implemented designs are not
retro-fitted into it.

Statuses were case-folded to the change vocabulary (`Accepted` → `accepted`,
`Draft` → `draft`, `Implemented` → `implemented`). The values themselves were
**preserved as they were**, not "corrected": `SDD-0001` stays `accepted` although
#68 shipped, and `ADR-0003` / `SDD-0002` stay `Proposed` / `draft` although #86
merged. Whether those are still accurate is a separate editorial question, not
part of a renumbering.

## Templates and indexes

| Old path | Current path | Notes |
|---|---|---|
| `adr/template.md` | [`adr/template.md`](adr/template.md) | Rewritten for the new grammar. `ADR-0000` is not a valid identifier under it. |
| `sdd/template.md` | [`changes/template.md`](changes/template.md) | Consolidated: one template covering all five change documents. Every plugin-specific section was kept. |
| `sdd/README.md` | [`changes/README.md`](changes/README.md) | Rewritten for the change-directory model. |
| `adr/records.md` | *removed* | The index is no longer committed. `make architecture-records` prints it from frontmatter. |
| `sdd/records.md` | *removed* | Same. |

The `docs/architecture/sdd/` directory no longer exists; its contents moved to
`docs/architecture/changes/`.

## Cross-repository identifiers cited here

Code and workflows in this repository cite decisions taken in the main
[`exelearning/exelearning`](https://github.com/exelearning/exelearning)
repository — the external-media family is built and published there, and this
plugin only vendors the bytes. That repository renumbered its own records under
the same scheme, so those citations were retired too. They are **not** records of
this repository: write them as `exelearning/exelearning ADR-<id>` so they never
read as local ones.

| Old identifier | Current identifier | Decision | Where it lives |
|---|---|---|---|
| `ADR-0017` | `ADR-2199-08` | The in-content embed shim stays inert until a host completes the handshake | [`exelearning/exelearning#2199`](https://github.com/exelearning/exelearning/pull/2199) |
| `ADR-0018` | `ADR-2199-09` | Dual-license the shared embedder family so one file ships under AGPL and GPL | [`exelearning/exelearning#2199`](https://github.com/exelearning/exelearning/pull/2199) |
| `ADR-0021` | `ADR-2199-12` | eXeLearning core is canonical for the external-media family, verified by manifest | [`exelearning/exelearning#2199`](https://github.com/exelearning/exelearning/pull/2199) |

Each mapping was read from the `legacy_id` frontmatter of the renamed record on
that pull request's branch, not inferred from the slug. The three decisions are
still under review upstream and have not reached `exelearning/exelearning@main`,
so they are cited by identifier and pull request rather than by file path.

## Deviations from the reference implementation

The main repository's model is adopted as-is except where this repository's own
conventions or constraints require otherwise. Each deviation is deliberate:

| Deviation | Why |
|---|---|
| **Tracking numbers are always PR numbers.** | Issues are disabled here (see above). The reference prefers an issue when one exists; here one never can. |
| **No `deciders` / `reviewers` fields.** The reference validator requires `deciders`. | `AGENTS.md` states plainly: *"Use issue/PR links for attribution — no people's names in frontmatter or templates."* No existing record here carries a `deciders` field, and inventing one would fabricate provenance. The requirement is dropped rather than the policy overturned. |
| **`external_refs` holds cross-repository links.** | The reference validates `related.prs` as bare positive integers. Records here legitimately cite `exelearning/exelearning#2150` and `moodle-mod_exelearning#106`, whose numbers belong to other sequences. Writing them as bare numbers would silently re-point them at this repository. |
| **A tracking number may own more than one change directory.** | [#88](https://github.com/exelearning/wp-exelearning/pull/88) delivered two independent designs. Forcing them into one directory would mean demoting one to `spec.md`, which misrepresents what it is. The reference validator already anticipates this: it keys uniqueness on the directory *name* and sorts by number then slug. |
| **The validator is PHP, not Bun/TypeScript.** | This repository has PHP and Composer in every developer environment and in CI; it has no Bun in the `lint_and_test` job. Adding a second runtime to lint documentation would be a new dependency for no gain. `bin/` is the established home for standalone CLI tooling here (`bin/validate-translations.php`, `bin/po-update.php`). |
| **A dedicated CI workflow runs the check.** | `.github/workflows/ci.yml` carries `paths-ignore: ['**.md', 'docs/**', ...]`, and `paths-ignore` does not support negation. A PR that only changes architecture records runs no CI at all under it, so the validator gets its own unfiltered workflow. It also stays wired into `make check` for code PRs. |

## Retired conventions

Two claims in the old policy no longer hold and were removed from every document
that repeated them:

- *"IDs are zero-padded, monotonic and never reused. The next ID is
  `max(existing) + 1`."* — there is no global counter any more. `max(existing)`
  was evaluated against whatever tree the author had checked out, which nothing
  coordinated across branches, and git cannot detect the resulting collision
  because it manifests as two *differently named* files that merge cleanly.
- *"`records.md` lists every ADR. The index is maintained by hand."* — the index
  is generated on demand and never committed.
