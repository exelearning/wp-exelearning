# AGENTS.md — eXeLearning

WordPress integration for uploading, managing, editing and embedding eXeLearning
ELPX content. Keep the WordPress 6.1 / PHP 8.0 minimum declared in `exelearning.php`.
Use the existing Bootstrap 5/jQuery UI and registered block; do not scaffold a
second plugin or replace the build pipeline when following a generic skill.

## Project boundaries

- `exelearning.php` bootstraps `includes/`; admin screens live in `admin/`,
  shortcode rendering in `public/class-shortcodes.php`.
- Archive processing, styles and content delivery go through the existing
  file-service, style-service and content-proxy classes. Preserve capability,
  nonce, path-validation and content-delivery boundaries when changing them.
- Update `docs/SHORTCODES.md` with shortcode attributes and `docs/HOOKS.md` with
  public actions/filters in the same change.
- The embedded editor is built with `make build-editor`; `npm run build` is only
  a reminder, not a build. Do not hand-edit generated editor output.
- `.distignore` controls `wp dist-archive` releases; `.gitattributes` controls
  source archives used by Playground. They are different contracts, not lists to
  synchronize. Root-only dist rules need `/` so they do not strip editor assets.
- For durable architecture changes, use `docs/architecture/README.md` and its
  ADR/change guides. Records use the carrying PR number (issues are disabled).
  Preserve accepted history, record `ai_assistance`, and run `make architecture-check`.

## Verification

`composer install` and the committed dependency configuration provide tooling;
do not add coding-standard packages just because an upstream example does.
`make lint` / `composer phpcs` use `.phpcs.xml.dist`; use `make fix` only when
formatting needs correction. Do not replace the repository ruleset with a bare
`--standard=WordPress .` scan.

- PHP: `make lint`, `make test`, `make phpmd`; do not raise `phpmd.xml` budgets.
- Browser JS/block behavior: `npm run test:js`; affected UI flows: `make test-e2e`.
- Translation changes: `make check-untranslated` and commit the generated catalogs.
- Architecture records: `make architecture-check`.
- Plugin distribution: `make check-plugin`; inspect the actual release archive.
- `make check` also applies automatic fixes; it is not a read-only verification command.

PHPUnit factories live in `tests/`. For coverage attribution and the local
commands, read [testing notes](.agents/references/testing.md).

## Working conventions

- Branches use English names with `feature/` or `hotfix/`; PRs target `main`.
- Follow the repository PHPCS ruleset and current source. English PHPDoc precedes
  functions/methods. Unslash request data before sanitizing; escape at output.
- Check capabilities and resource ownership as well as nonces at write boundaries;
  follow the full caller chain before declaring a deliberately delegated guard missing.
- Read only the domain docs needed by the task. Keep changes focused and report
  what changed, what was verified, and any unresolved check failure concisely.
- Agent guidance/workflow changes need frontmatter, link, provenance and `actionlint`
  checks. Runtime changes need the relevant tests above. Do not weaken CI gates.
- No production deployment, release publication or data mutation is implied by
  a local implementation task. Respect authorization already given in the session.

English source strings use the plugin text domain; Spanish translations and
assertions preserve the user-facing language. Update catalogs with string changes,
use plural-aware translation functions, and add `translators:` comments for
placeholders. JS strings/nonces/URLs use the existing localization pipeline.

## Skills

Load only the skill relevant to the task. Local contracts override generic examples.
- [blueprint](.agents/skills/blueprint/SKILL.md): WordPress Playground blueprint JSON.
- [github-actions-hardening](.agents/skills/github-actions-hardening/SKILL.md): Author/review GitHub Actions workflows.
- [playwright-cli](.agents/skills/playwright-cli/SKILL.md): Terminal browser exploration; keep the existing test runner.
- [security-audit](.agents/skills/security-audit/SKILL.md): Requested vulnerability audits.
- [wp-block-development](.agents/skills/wp-block-development/SKILL.md): Existing Gutenberg block metadata and rendering.
- [wp-performance](.agents/skills/wp-performance/SKILL.md): Measured backend performance work.
- [wp-plugin-development](.agents/skills/wp-plugin-development/SKILL.md): WordPress hooks, lifecycle and settings.
- [wp-plugin-directory-guidelines](.agents/skills/wp-plugin-directory-guidelines/SKILL.md): Distribution/readme and directory checks.
- [wp-project-triage](.agents/skills/wp-project-triage/SKILL.md): Identify existing WordPress tooling and layout.
- [wp-rest-api](.agents/skills/wp-rest-api/SKILL.md): REST schemas, routes and permissions.

### Skill maintenance

Install upstream skills with `gh skills install OWNER/REPO skills/NAME --dir .agents/skills`.
Keep upstream text and `metadata.github-*` unchanged; fix upstream and reinstall.
Local skills have no GitHub provenance and the updater skips them. Put project
exceptions in local guidance, not inside installed upstream folders.

WordPress skills may target 7.0+: verify APIs against this project's supported
versions. Do not upgrade requirements, scaffold new packages or change architecture
merely because a generic skill recommends it. Resolve example `skills/...` paths
under the actual `.agents/skills/` installation; use existing commands first.

New Claude entries are symlinks to `../../.agents/skills/NAME`.
Claude reads the same skill directories through those symlinks.

`.github/workflows/update-agent-skills.yml` checks weekly/on dispatch, scoped to
installed skills, and opens a review PR on `main`. It never merges updates.
Review prompt diffs as behavior changes. PRs made with the default GitHub token
may not trigger CI; do not assume green checks will appear automatically.

The block skill must not force apiVersion 3 or script modules while WordPress 6.1
remains supported. Preserve the existing block registration/build compatibility.
