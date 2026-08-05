# Architecture Documentation

This directory holds the **architecture records** for the `wp-exelearning`
WordPress plugin: durable decisions and significant designs that future
contributors should be able to read long after the pull request that introduced
them has scrolled out of view.

## Operational docs vs architecture records

The plugin keeps two different kinds of documentation, and they answer different
questions:

| Kind | Lives in | Answers |
|------|----------|---------|
| **Operational docs** | [`docs/SHORTCODES.md`](../SHORTCODES.md), [`docs/HOOKS.md`](../HOOKS.md), `README.md`, `readme.txt` | *How do I use this?* — the current shortcode attributes, hooks, install steps. Always describes the code as it is **now**. |
| **Architecture records** | `docs/architecture/` (this directory) | *Why is it built this way?* and *what are we about to build?* — the reasoning behind durable decisions and the design of significant changes. |

Operational docs are updated in place to match the code. Architecture records are
kept as a history: accepted decisions are not rewritten, they are superseded.

## What lives here

- **[Architecture Decision Records (ADR)](adr/README.md)** — one durable
  decision per record, with the context, the options, the evidence and the
  consequences. ADRs answer *"why is it built this way?"*
- **[Change documents](changes/README.md)** — the design gate for significant
  changes: goals, non-goals, the proposed design, and how it will be validated.
  They answer *"what are we about to build, and how?"* Each change lives in its
  own directory under `changes/`.
- **[Migration map](migration-map.md)** — where every retired identifier went.

## When to use each

- Reach for an **[ADR](adr/README.md)** when a change locks in a decision that
  future contributors should not have to re-litigate — a storage layout, a
  security boundary, a compatibility guarantee.
- Reach for a **[change document](changes/README.md)** when a change is large
  enough to deserve a design review before implementation — a new feature, a
  cross-cutting refactor, a change touching uploads, extraction, the REST API, or
  the embedded editor.
- A large change often starts with a change document; the durable decisions
  inside it are extracted into ADRs and linked, instead of being buried in the
  design prose.

See each guide for the exact rules on when a record is required, recommended, or
unnecessary.

## Identification

Records are identified by their **GitHub tracking number** — the number of the
change they belong to, not a global counter:

```text
adr/ADR-<tracking-number>-<NN>-<decision-slug>.md
changes/<tracking-number>-<change-slug>/{proposal,spec,design,research,tasks}.md
```

**Issues are disabled on this repository, so the tracking number is always the
pull request number.** Cross-repository issues — most of this plugin's work is
coordinated from the main eXeLearning repository — belong to a different number
sequence, and are recorded as full URLs under `external_refs` rather than used as
identifiers. See [`migration-map.md`](migration-map.md) for the full reasoning
and for every retired identifier's new home.

## Tooling

| Command | What it does |
|---|---|
| `make architecture-check` | Validates identifiers, metadata and cross-references. Non-zero on failure. Part of `make check`, and of the `Architecture records` CI workflow. |
| `make architecture-records` | Prints the ADR and change indexes, derived from frontmatter. |

Both wrap `python3 bin/architecture_records.py`, also reachable as
`composer architecture-check` / `composer architecture-records`. The indexes are
**generated, never committed**: a generated file in git conflicts on every
concurrent branch, and this index is contributor-facing rather than published
documentation.

## Relationship to the main eXeLearning repository

The main [eXeLearning](https://github.com/exelearning/exelearning) repository
adopted this ADR/design-document workflow in
[`exelearning/exelearning#2149`](https://github.com/exelearning/exelearning/pull/2149),
following the proposal in
[`exelearning/exelearning#2148`](https://github.com/exelearning/exelearning/issues/2148),
where the records live under `doc/architecture/`. It moved to tracking-number
identifiers in
[`exelearning/exelearning#2232`](https://github.com/exelearning/exelearning/issues/2232),
and this repository follows.

`wp-exelearning` adapts the **same lightweight approach** to a WordPress plugin.
Because this repository already stores its documentation under `docs/`, the
records live under `docs/architecture/` instead. The templates and guidance are
tailored to plugin concerns — WordPress capabilities and nonces, ELPX
upload/extraction, the embedded editor bundling, shortcode and block
rendering, the style registry, and the content proxy — rather than the main
repository's server, collaboration and export internals. The two repositories
keep separate, independent record histories, and their tracking numbers come from
separate sequences.

The deviations this repository makes from the main repository's model, and why
each one is necessary, are listed in [`migration-map.md`](migration-map.md).
