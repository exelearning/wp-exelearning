# AGENTS.md — eXeLearning

WordPress plugin for uploading, managing, editing and embedding eXeLearning `.elpx`
packages. Keep the WordPress 6.1 / PHP 8.0 minimum declared in `exelearning.php`.
Use the existing Bootstrap 5/jQuery UI and registered block; do not scaffold a
second plugin or replace the build pipeline when following a generic skill.

This is the single guide for every coding agent; `CLAUDE.md` only imports it.

## How the plugin works

Packages are plain WordPress **attachments** — there is no custom post type.

1. An `.elpx` is uploaded to the Media Library (`includes/class-mime-types.php`
   registers the type; legacy `.elp` v2 files are rejected).
2. `ExeLearning_Elp_File_Service` validates it (ZIP + `content.xml`, zip-slip,
   entry-count and size limits) and extracts it to
   `wp-content/uploads/exelearning/{sha1}/`.
3. Package metadata is stored as attachment post meta.
4. Content is served by the REST content proxy and embedded with the
   `[exelearning]` shortcode or the `exelearning/elp-upload` block.

| Area | Files |
|------|-------|
| Bootstrap | `exelearning.php` (`require_once` for every class), `includes/class-exelearning.php` (creates components; most register their own hooks), `includes/class-upgrader.php` (option migrations keyed on `exelearning_db_version`), `includes/class-filesystem.php` (shared recursive delete) |
| Ingestion | `includes/class-elp-upload-handler.php` (upload, extraction, delete cleanup), `includes/class-elp-file-service.php` |
| Reprocessing | `includes/class-elp-reprocessor.php` (class `ExeLearning_Reprocessor`), used by REST save/reprocess, the Media Library bulk action and `wp exelearning reprocess` (`includes/class-cli-command.php`); `includes/class-content-hash-aliases.php` keeps retired hashes as redirects (ADR-68-01) |
| Content delivery | `includes/class-content-proxy.php`: `GET /wp-json/exelearning/v1/content/{hash}/{file}` with security headers and CSP. A generated `.htaccess` blocks direct HTML/SVG/XML access under `uploads/exelearning/` (Apache only); the `exelearning_content_origin` filter serves content from a separate host |
| Frontend | `public/class-shortcodes.php`, `includes/class-elp-upload-block.php` (Block API v3), `assets/js/exelearning-embed.js` (fullscreen and poster behavior shared by shortcode and block, enqueued only when an embed renders), `includes/class-download-button-renderer.php` + `includes/class-export-bootstrap.php` (exports through the editor in a hidden `?exe_export=1` iframe) |
| Admin | `admin/class-admin-settings.php`, `includes/integrations/class-media-library.php` (columns, meta boxes, previews, bulk reprocess), styles: `admin/class-admin-styles.php`, `includes/class-styles-service.php`, `includes/class-style-package.php` (`uploads/exelearning-styles/{slug}/`) |
| Embedded editor | `includes/class-exelearning-editor.php`, `admin/views/editor-bootstrap.php` (loads `dist/static/index.html` with WordPress config), `assets/js/exelearning-editor.js` (modal) ↔ postMessage ↔ `assets/js/wp-exe-bridge.js` (inside the editor iframe), `includes/class-editor-bundle.php` |

### REST API (`exelearning/v1`)

| Route | Method | Permission |
|-------|--------|------------|
| `/content/{hash}/{file}` | GET | public (hash is the capability) |
| `/save/{id}` | POST | `edit_post` on the attachment |
| `/create` | POST | `upload_files` |
| `/elp-data/{id}` | GET | `edit_post` |
| `/reprocess/{id}` | POST | `edit_post` |

### Data

- Post meta: `_exelearning_title`, `_exelearning_description`,
  `_exelearning_license`, `_exelearning_language`, `_exelearning_resource_type`,
  `_exelearning_version`, `_exelearning_extracted` (SHA1 of the extraction folder),
  `_exelearning_has_preview` (`1` when `index.html` exists),
  `_exelearning_obsolete_hash` (retired hashes kept as redirect aliases).
- Options: `exelearning_db_version`, `exelearning_proxy_assets`,
  `exelearning_styles_registry`, `exelearning_styles_block_import`,
  `exelearning_disabled_styles`; `uninstall.php` deletes them and keeps user content.

## Project boundaries

- Archive processing, styles and content delivery go through the existing
  file-service, style-service and content-proxy classes. Preserve capability,
  nonce, path-validation and content-delivery boundaries when changing them.
  Package HTML is untrusted author content served on the site origin. Do not
  drop `allow-same-origin` from its iframes piecemeal: packages need storage and
  cookies, embedded video needs the parent relay, and Playground cannot serve
  opaque-origin subframes. The opaque-origin viewer lands as a whole in #56.
- Update `docs/SHORTCODES.md` with shortcode attributes and `docs/HOOKS.md` with
  public actions/filters in the same change.
- The block uses Block API version 3 (change 89), which WordPress 6.1 still loads.
  Do not move it to script modules while WordPress 6.1 remains supported.
- `.distignore` controls `wp dist-archive` releases; `.gitattributes` controls
  source archives used by Playground. They are different contracts, not lists to
  synchronize. Root-only dist rules need `/` so they do not strip editor assets.
- For durable architecture changes, use `docs/architecture/README.md` and its
  ADR/change guides. Records use the carrying PR number (issues are disabled).
  Preserve accepted history, record `ai_assistance`, and run `make architecture-check`.

### Embedded editor build

`exelearning/` is a gitignored clone of `exelearning/exelearning`, not a submodule;
`dist/static/` is its generated build. Do not hand-edit either. `npm run build`
is only a reminder, not a build.

- `make build-editor`: fetch the source and build it (needs Bun).
- `make build-editor-if-needed`: build only when `dist/static/.build-commit` differs
  from the clone's HEAD (used by `make up`; keeps the existing build when offline).
- `EXELEARNING_EDITOR_REF` / `_REF_TYPE` (`auto`, `branch`, `tag`; env or `.env`)
  choose the ref, default `main`. Releases pin the editor tag in `.editor-version`.

## Local environment

- `make up` / `make down`: wp-env on http://localhost:8888 (admin/password); the
  tests site is on 8889. `make clean` resets it, `make destroy` removes it.
- The tests container's uploads directory persists between runs: register every
  file or folder a test creates and delete it in `tear_down()`, never inline after
  the assertions.

## Verification

`composer install` and the committed dependency configuration provide tooling;
do not add coding-standard packages just because an upstream example does.
`make lint` / `composer phpcs` use `.phpcs.xml.dist`; use `make fix` only when
formatting needs correction. Do not replace the repository ruleset with a bare
`--standard=WordPress .` scan.

- PHP: `make lint`, `make test` (`FILTER=Name` narrows it), `make phpmd`; do not
  raise `phpmd.xml` budgets.
- Browser JS/block behavior: `npm run test:js`; affected UI flows: `make test-e2e`.
- Translations: `make translations` (`composer make-translations`, vendored WP-CLI;
  never the wp-env container's `wp`), then `make check-untranslated`; commit the
  generated catalogs.
- Architecture records: `make architecture-check`.
- Workflows: `actionlint`.
- Plugin distribution: `make check-plugin`; inspect the actual release archive.
- `make check` also applies automatic fixes; it is not a read-only verification command.

PHPUnit factories live in `tests/`. For coverage attribution and the local
commands, read [testing notes](.agents/references/testing.md).

## Working conventions

- Branches use English names with `feature/` or `hotfix/`. Standalone PRs target
  `main`; a stacked PR may target the previous branch of its stack, and the stack
  is merged in order.
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

English source strings use the plugin text domain; every shipped locale gets a
translation, and assertions preserve the user-facing language (the test site runs
`es_ES`). Use plural-aware translation functions and add `translators:` comments
for placeholders. JS strings/nonces/URLs use the existing localization pipeline.

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
New Claude entries are symlinks to `../../.agents/skills/NAME`, so Claude reads
the same skill directories.

WordPress skills may target 7.0+: verify APIs against this project's supported
versions. Do not upgrade requirements, scaffold new packages or change architecture
merely because a generic skill recommends it. Resolve example `skills/...` paths
under the actual `.agents/skills/` installation; use existing commands first.

`.github/workflows/update-agent-skills.yml` checks weekly/on dispatch, scoped to
installed skills, and opens a review PR on `main`. It never merges updates.
Review prompt diffs as behavior changes. PRs made with the default GitHub token
may not trigger CI; do not assume green checks will appear automatically.

### GitHub Actions

Keep actions on version tags maintained by Renovate, not SHAs: `actions/checkout@v7`,
`peter-evans/create-pull-request@v8`, and `devantler-tech/actions/update-agent-skills@v13.3.3`
(prefer the floating `v13` once upstream provides it). Pass external values
(release tags, dispatch inputs, event fields) to `run:` steps through `env:`, never
`${{ }}` inside the script, and use `persist-credentials: false` on checkouts in
jobs that install third-party packages.
