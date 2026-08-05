---
id: ADR-NNN-01
title: "Short decision title"
status: Proposed
date: YYYY-MM-DD
tracking_issue: NNN   # the pull request number that carries this decision
related:
  prs: []
  changes: []
  adrs: []
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
   repository, so it is always the PULL REQUEST number that carries the
   decision. That number IS the identifier — there is no global counter and
   nothing to compute. NEVER open an issue just to get a number.
2. Copy this file to `ADR-<number>-<NN>-<decision-slug>.md`, where <NN> is the
   next free two-digit sequence FOR THAT TRACKING NUMBER ONLY (`01` if it is the
   first). The slug names the decision, not the topic.
3. Set `id` to `ADR-<number>-<NN>` and `tracking_issue` to that number. They must
   match the filename; CI enforces this.
4. Make the H1 below `# <id>: <title>`.
5. Fill every section below. Delete guidance comments before submitting.
6. Keep the file at `status: Proposed` until reviewers accept it. Status lives in
   the frontmatter only — do not add a `## Status` section.
7. Cite a verifiable source for each technical claim (repo path + commit,
   WordPress documentation, benchmark, experiment, PR, change document, or prior
   ADR).
8. `related.prs` takes bare numbers of THIS repository. Links to the main
   eXeLearning repository, the Moodle plugin or anywhere else go in
   `external_refs` as full URLs — their numbers come from other sequences.
9. Record AI assistance in `ai_assistance` (values, or `none` if not used).
10. Use PR links for attribution — do not add people's names.
11. Run `make architecture-check` to validate.
See ./README.md for the full policy.
-->

# ADR-NNN-01: Short decision title

## Context

<!-- The situation that forces a decision. What is happening, what constraints
apply (WordPress version support, PHP version, plugin-review rules), and why now.
State facts, not opinions. -->

## Problem

<!-- The specific question this ADR answers, phrased so a chosen option resolves
it. -->

## Decision drivers

<!-- The forces that matter: security, backward compatibility, performance,
accessibility, internationalization, maintainability, WordPress Coding Standards,
plugin-review constraints, implementation effort. -->

- Driver 1
- Driver 2

## Alternatives considered

### Option 1: ...

<!-- Describe the option, then its pros and cons. -->

### Option 2: ...

### Option 3: ...

## Evidence

<!-- The verifiable basis for the decision. Prefer:
- repository path + commit (e.g. `includes/class-elp-file-service.php` @ `abc1234`)
- official WordPress documentation or a specification (link)
- a benchmark or reproducible experiment (numbers + how to reproduce)
- a linked PR, change document, or prior ADR
No technical claim without a source. -->

## Decision

<!-- The option that was chosen, stated plainly. "We will ...". -->

## Consequences

### Positive

- ...

### Negative

- ...

### Neutral

- ...

## Risks

<!-- What could go wrong, and how likely / severe. -->

## Validation

<!-- How we will know the decision was correct: PHPUnit/Playwright tests,
metrics, a follow-up review date, an experiment to run. -->

## Follow-up work

<!-- Concrete next steps this decision creates. Link PRs when they exist. -->

## References

<!-- All sources cited above, plus related PRs, change documents and ADRs. -->
