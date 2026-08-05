# Architecture Decision Records

## Purpose

An **Architecture Decision Record (ADR)** captures a single durable architectural
decision together with the reasoning behind it: the context, the problem, the
options that were considered, the evidence that informed the choice, the decision
itself, and the consequences that follow.

ADRs exist so that `wp-exelearning` contributors — human and AI — can answer
*"why is it built this way?"* long after the fact, without archaeology through
pull request threads or chat logs. A decision recorded only inside a PR
description is easy to lose; an ADR is a first-class, long-lived document.

Guiding principles:

- **Evidence before preference.** Prefer a verifiable source over an assertion.
- **No technical claim without a source.** Cite a repository path + commit,
  official WordPress documentation, a benchmark, a reproducible experiment, an
  issue, a PR, a change document, or a previous ADR.
- **Separate facts, interpretation and decision.** Say what is observed, what it
  means, and what was decided — in that order.
- **Stable IDs.** An identifier is never reused and never changes once published.
- **Append-only.** Accepted decisions are not rewritten; they are superseded.

## ADRs vs change documents

ADRs and [change documents](../changes/README.md) are complementary, not
competing.

| Artifact | Answers | Lifetime |
|----------|---------|----------|
| **Change document** | *What* will be built and *how* a significant change will be implemented | May become historical once implemented |
| **ADR** | *Which* durable decision was made and *why* | Long-lived, append-only |
| **PR** | The concrete code/doc changes under review | Historical review record |

A large change usually starts with a proposal and a design in its
[change directory](../changes/README.md). Inside that design, the decisions that
will outlive the change itself — a storage layout, a file-format guarantee, a
security boundary — should be extracted into ADRs or linked to existing ones. The
design records *how*; the ADR records *what was decided and why*. Do **not** copy
a whole design document into an ADR.

## An ADR is not…

- **…an implementation plan.** That is what a
  [change document](../changes/README.md) is for.
- **…a changelog entry.** Use `readme.txt` for user-facing release notes.

## Identification

ADRs are identified by their **GitHub tracking number**, not by a global counter.

The tracking number is the change's **issue** when it has one, and its **pull
request** when it does not. GitHub draws issue and pull-request numbers from a
single repository-wide sequence, so the two can never collide — in GitHub's data
model a pull request *is* an issue, which is why `/issues/<n>` resolves to a pull
request.

> **In this repository the tracking number is always a pull request number.**
> Issues are disabled on `exelearning/wp-exelearning`; work is coordinated in the
> main [`exelearning/exelearning`](https://github.com/exelearning/exelearning)
> repository, whose issue numbers come from a *different* sequence and therefore
> cannot identify a record here. Link a cross-repository issue under
> `external_refs`, and identify the record by the pull request that carries it.

This follows the decision taken in the main repository,
[`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232)
(`ADR-2232-01`, *Identify architecture records by tracking issue instead of a
global counter*). The rationale, the rejected alternatives and the evidence live
there; this repository adopts the model and records only its own deviations —
see [`../migration-map.md`](../migration-map.md).

### Filename

```text
ADR-<tracking-number>-<local-sequence>-<decision-slug>.md
```

For example, pull request
[#88](https://github.com/exelearning/wp-exelearning/pull/88) could produce two
decisions:

```text
ADR-88-01-first-decision-slug.md
ADR-88-02-second-decision-slug.md
```

### Rules

- **A GitHub tracking number is required** before an ADR is finalized. Here that
  is the pull request that carries the decision. **Never open an issue solely to
  obtain a number** — and in this repository you cannot, because issues are
  disabled.
- `<tracking-number>` has no leading zeros.
- `<local-sequence>` is two digits, scoped **only** to that tracking number,
  starting at `01`. It is present even when a change has a single ADR, so that
  adding a second one later never renames the first.
- A local sequence is never reused within the same tracking number, even if a
  record is rejected or removed.
- `<decision-slug>` is lowercase kebab-case and names the **decision**, not the
  topic. `make-distignore-single-source-of-truth` is a decision;
  `release-packaging` is a topic.
- The frontmatter `id` must equal `ADR-<tracking-number>-<local-sequence>`, and
  `tracking_issue` must equal the tracking number. CI enforces both. The field
  keeps the name `tracking_issue` because GitHub models a pull request as an
  issue; it holds whichever number identifies the change.
- The document H1 must be `# <id>: <title>`.
- There is **no global counter** and no next-free-number to compute. Two branches
  can only collide if they share a tracking number.
- Identifiers are stable once published. If the situation around a record
  changes, record that in the change document — do not renumber.

### Where things live

- ADRs live in `docs/architecture/adr/`.
- [`template.md`](template.md) is the canonical template.
- There is **no committed index**. Run `make architecture-records` to print one,
  derived from frontmatter. A generated file in git conflicts on every concurrent
  branch, and this index is contributor-facing — it is not published docs.
- [`../migration-map.md`](../migration-map.md) maps every retired identifier to
  its current path.

## Status values

| Status | Meaning |
|--------|---------|
| `Proposed` | Under discussion; not yet agreed. |
| `Accepted` | Agreed and in force. |
| `Rejected` | Considered and declined. Kept for the record. |
| `Superseded` | Replaced by a later ADR (see `superseded_by`). |

A decision that is still being debated stays `Proposed`. It becomes `Accepted`
only after reviewer approval on the PR.

Status lives in the frontmatter **only**. Do not add a `## Status` section that
repeats it — one canonical source per mutable field.

## Canonical metadata

| Field | Required | Canonical source for |
|---|---|---|
| `id` | yes | the record's identity (must match the filename) |
| `title` | yes | the record's title (mirrored by the H1) |
| `status` | yes | lifecycle state |
| `date` | yes | creation date, `YYYY-MM-DD` |
| `tracking_issue` | yes | the GitHub number that owns this decision — a PR number in this repository |
| `legacy_id` | migrated records only | the retired identifier |
| `related.prs` | no | implementation / review traceability, as bare numbers |
| `related.changes` | no | change directories this decision belongs to |
| `related.adrs` | no | sibling decisions |
| `external_refs` | no | cross-repository issues and PRs, as full URLs |
| `supersedes` / `superseded_by` | no | decision history |
| `ai_assistance.tool` / `.model` | yes | provenance (`none` if unused) |

`related.prs` is **traceability metadata**: it lists every PR of *this*
repository that implements or reviews the decision, and it is not what identifies
the record. Its tracking number is the single value in `tracking_issue`, chosen
once and then stable, not the growing list in `related.prs`.

`external_refs` exists because this plugin's work is frequently coordinated from
the main eXeLearning repository or mirrored in the Moodle plugin. Those numbers
belong to other sequences, so they are stored as full URLs and never as bare
numbers.

The frontmatter deliberately records tools and links, **not** the names of
people — use PR links for attribution. This is why `deciders` and `reviewers`
are not part of the schema here, unlike in the main repository.

## When an ADR is required

Create or update an ADR when a change **introduces or modifies a durable
architectural decision** — one that future contributors should not have to
re-litigate. In this plugin that typically includes decisions affecting:

- the storage model for ELPX packages and their metadata (attachment post meta,
  the `wp-content/uploads/exelearning/` extraction layout, checksums);
- ELPX validation and extraction (ZIP handling, path-traversal protection,
  size limits);
- the embedded editor installation/build flow and where its assets are stored;
- the content proxy and the security model around serving extracted content;
- the style registry and where uploaded style packages are stored;
- WordPress integration boundaries: capabilities, nonces, REST API contracts,
  shortcode/block attribute contracts, Media Library integration;
- backward-compatibility guarantees for shortcodes, blocks, hooks, or stored
  metadata;
- any AI-assisted generation workflow or policy.

If in doubt, prefer writing a short ADR over losing the reasoning.

## When an ADR is recommended

An ADR is worth writing, even when not strictly required, when a change would
otherwise leave reviewers guessing *why* a non-obvious approach was chosen — for
example picking one WordPress API over another, or accepting a trade-off that a
later contributor might be tempted to "fix".

## When an ADR is not needed

Do **not** write an ADR for:

- bug fixes that restore intended behavior;
- routine refactors with no externally observable decision;
- dependency bumps, lint/format changes, copy edits;
- translation-only or test-only changes;
- purely local implementation details with no cross-cutting impact.

Do not create an ADR per section of a design document, and do not create empty
ADRs to fill a gap in the sequence — the sequence is expected to have gaps.

If a change is significant enough to need a design but does not yet lock a
durable decision, start with a [change document](../changes/README.md) instead.

## Evidence and traceability

Every technical claim in an ADR should cite a verifiable source. Acceptable
evidence includes:

- a repository path plus commit (e.g. `includes/class-elp-file-service.php` @ `abc1234`);
- official WordPress documentation or a specification;
- a benchmark or a reproducible experiment (numbers + how to reproduce);
- a linked PR, change document, or prior ADR.

Keep the evidence **inside** the ADR. This repository does not maintain a
separate `sources/` or `experiments/` tree.

## Recording alternatives and consequences

- **Alternatives considered** — list the realistic options, with their pros and
  cons, so a reader can see the decision was a choice and not an accident.
- **Consequences** — state the positive, negative and neutral effects honestly.
  A decision with no downsides is usually a decision that was not examined
  closely enough.

## AI-assisted ADRs

If an AI tool helped draft or research an ADR, disclose it in the frontmatter:

```yaml
ai_assistance:
  tool: "Claude Code"        # tool / interface used
  model: "claude-opus-5"     # model, when relevant
```

If no AI tool was involved, set both fields to `none`. Disclosure is about
traceability, not judgement: it records how the document was produced so the
evidence can be weighed accordingly.

## Superseding an ADR

Accepted ADRs are **append-only**. Do not rewrite them except to fix typos or
broken links.

To change an accepted decision:

1. Create a new ADR under the tracking number that motivates the change.
2. Set `supersedes: [ADR-<old-id>]` in the new ADR's frontmatter.
3. Set `status: Superseded` and `superseded_by: [ADR-<new-id>]` in the old ADR.
4. Run `make architecture-check` to validate the relationship.

CI rejects a one-sided relationship: both directions must be present, and a
superseded ADR must carry `status: Superseded`.

## Referencing ADRs

Refer to ADRs by their ID so links stay stable:

- **From code / comments:** `// See ADR-72-01: the editor ships in release packages only.`
- **From docs:** `[ADR-72-01](adr/ADR-72-01-bundle-editor-exclusively-in-release-packages.md)`
  (adjust the relative path).
- **From a change document:** list the ADR in `related_adrs` and in the *ADRs
  required or referenced* table.
- **From a PR:** mention `ADR-72-01` and link the file.

Retired identifiers — the old four-digit `ADR-NNNN` / `SDD-NNNN` form — must not
appear in new content. CI fails on them; use
[`../migration-map.md`](../migration-map.md) to find the current identifier.

## Tooling

| Command | What it does |
|---|---|
| `make architecture-check` | Validates identifiers, metadata and cross-references. Non-zero on failure. |
| `make architecture-records` | Prints the ADR and change indexes, derived from frontmatter. |

Both are thin wrappers around `node bin/architecture-records.mts`, also available
as `composer architecture-check` / `composer architecture-records`.
`make architecture-check` runs as part of `make check`, and in CI from the
`Architecture records` workflow, which — unlike the main CI workflow — is not
filtered by `paths-ignore` and therefore also runs on documentation-only PRs.

## Workflow

1. Identify the change's **GitHub tracking number** — the pull request that
   carries it.
2. Identify the durable decision (see *When an ADR is required*).
3. Copy [`template.md`](template.md) to
   `ADR-<number>-<NN>-<decision-slug>.md`, where `<NN>` is the next free local
   sequence **for that tracking number only** (`01` if it is the first).
4. Fill in context, problem, options, evidence, decision and consequences.
   Start at `status: Proposed`.
5. Run `make architecture-check` to validate. `make architecture-records` prints
   the current index if you want to read it.
6. Open (or reference) the PR. Reviewers discuss and, if agreed, the status moves
   to `Accepted`.
7. If a later change reverses the decision, supersede it — never edit the
   accepted record.

## Review checklist

- [ ] The change has a tracking number (its pull request), and the filename uses it.
- [ ] The local sequence is the next free one **for that number**, starting at `01`.
- [ ] The slug names the decision, not the topic.
- [ ] Frontmatter `id` matches the filename; `tracking_issue` matches the number.
- [ ] The H1 is `# <id>: <title>`.
- [ ] Context, problem, alternatives, decision and consequences are all present.
- [ ] Every technical claim cites a verifiable source.
- [ ] Positive, negative and neutral consequences are stated honestly.
- [ ] `status` reflects reality (`Proposed` while under discussion), and appears
      only in the frontmatter.
- [ ] `ai_assistance` is filled in (values or `none`).
- [ ] Cross-repository links are in `external_refs` as full URLs, not in
      `related.prs`.
- [ ] Superseding ADRs set `supersedes` / the old ADR sets `superseded_by` and
      `status: Superseded`.
- [ ] `make architecture-check` passes.
