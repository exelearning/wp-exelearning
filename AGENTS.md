<!-- AGENTS.md -->

# Agents Coding Conventions for Plugin “Exelearning”

These are natural-language guidelines for agents to follow when developing the Exelearning WordPress plugin.

## Project conventions

- Follow **WordPress Coding Standards**:
  - PHP code: indent blocks with **tabs** (WordPress default) and reserve spaces for alignment/continuations. Keep PSR‑12 compatibility when it does not contradict WP requirements. Always escape/sanitize properly and rely on WP APIs.
  - Use English for source code (identifiers, comments, docblocks).
  - Write all implementation notes, inline comments, and documentation in English.
  - Use Spanish for user‑facing translations/strings and test assertions to check no untranslated strings remain.
  - Keep class file names aligned with their class names (e.g., `class-exelearning-admin-helper.php` for `Exelearning_Admin_Helper`).
  - Add `/* translators: */` comments immediately before translations containing placeholders such as `%s` or `%d`.
  - Always unslash superglobals (e.g., `$_POST`) before sanitizing and storing their values.
  - When emitting standalone pages, enqueue styles/scripts with WordPress APIs (`wp_enqueue_style`, `wp_enqueue_script`) and print them via `wp_print_*` helpers instead of hard-coding `<link>` or `<script>` tags.
  - Ensure all code passes `phpcs --standard=WordPress` and is auto-fixable with `phpcbf --standard=WordPress` where applicable.
  - Install coding standard tooling with Composer in the project root: `composer require --dev dealerdirect/phpcodesniffer-composer-installer:^1.0 wp-coding-standards/wpcs:^3.0`.
  - After installation, run `vendor/bin/phpcbf --standard=WordPress .` to auto-fix violations before linting with `vendor/bin/phpcs --standard=WordPress .`; this step will also normalize any space-indented blocks back to tabs.

## Testing and development workflow

- Use **TDD** (Test‑Driven Development) with factories to create test fixtures.
- Tests live under `/tests/` and use factory classes.
- Run `phpcs --standard=WordPress` and `phpcbf --standard=WordPress` (or equivalent tooling) before submitting changes; the codebase must stay clean.
- Use `make lint` (PHP lint) and `make fix` (beautifier) to enforce standards.
- Use `make test` to run all unit tests.
- Ensure all PHPUnit test suites pass locally before requesting review.
- Use `make check-untranslated` to detect any untranslated Spanish strings.
- Run **PHPMD** with the repo ruleset before submitting: `make phpmd` (or
  `phpmd . text phpmd.xml --exclude vendor,node_modules,tests,dist`). It is the
  same scan CI runs and must report **no violations**. The thresholds live in
  `phpmd.xml`: CyclomaticComplexity 15, NPathComplexity 500, ExcessiveMethodLength
  150, ExcessiveClassComplexity 100, TooManyFields 15. Do **not** raise these
  thresholds to silence a finding — refactor instead (extract helper methods to
  cut cyclomatic/NPath; split a class or move a cohesive cluster of methods into
  a dedicated collaborator to cut class complexity; group related properties into
  an array to cut field count). Note that the null-coalescing operator (`??`) is
  not counted toward complexity, unlike the ternary (`?:`).

## Tooling quick start

- Run `composer install` in the project root to install PHP_CodeSniffer, WordPress Coding Standards, and other developer tools (requires outbound network access).
- Use `./vendor/bin/phpcbf --standard=.phpcs.xml.dist` first to apply automatic fixes (including converting stray spaces back to tabs), then `./vendor/bin/phpcs --standard=.phpcs.xml.dist` to ensure the codebase is clean.
- Composer scripts mirror these commands: `composer phpcbf` and `composer phpcs` respect the repository ignore list defined in `.phpcs.xml.dist`.
- The `.phpcs.xml.dist` ruleset bundles the WordPress standard, limits scanning to PHP files, enables colorized output, suppresses warnings, and excludes vendor, assets, node_modules, tests/js, wp, tests, and `.composer` directories.
- When working outside the `wp-env` Docker environment, call the binaries from `./vendor/bin/` directly. Inside wp-env, reuse the Make targets (`make fix` and `make lint`) which wrap `phpcbf`/`phpcs` with the same `.phpcs.xml.dist` ruleset path (`wp-content/plugins/exelearning/.phpcs.xml.dist`).
- The repository `composer.json` already whitelists the `dealerdirect/phpcodesniffer-composer-installer` plugin and exposes the scripts `composer phpcbf` and `composer phpcs`; these call the local binaries under `./vendor/bin/` with the shared `.phpcs.xml.dist` ruleset, so prefer them to keep tooling consistent.
- Run the beautifier before linting when fixing coding standards violations: `composer phpcbf` (or the equivalent binary invocation) followed by `composer phpcs`. `phpcbf` will repair mixed indentation before PHPCS evaluates the files.
- After writing or updating code, always run `composer phpcbf` followed by `composer phpcs` (or their `./vendor/bin/` equivalents) to keep the codebase compliant with the configured standards.

## Editor configuration

- Respect the root `.editorconfig`; it forces PHP files to use tabs for indentation (`indent_style = tab`, `tab_width = 4`). Most editors (and AI-assisted tooling) read this automatically, so leave it untouched.
- **Sublime Text**: ensure your project/user settings include `{ "translate_tabs_to_spaces": false, "tab_size": 4 }` within the `"php"` scope to keep tabs. Enable `"ensure_newline_at_eof_on_save": true` to match repository style.
- **Visual Studio Code**: add the following to your workspace `settings.json`:
  ```json
  {
    "[php]": {
      "editor.insertSpaces": false,
      "editor.tabSize": 4
    }
  }
  ```
- When using other editors, disable "convert tabs to spaces" for PHP files and set the tab width to 4 characters.

## Linting workflow checklist

1. Install/update tooling with `composer install` (run once per environment).
2. For automated fixes, execute `composer phpcbf` or `make fix` when inside wp-env.
3. Validate coding standards with `composer phpcs` or `make lint` inside wp-env.
4. Address any reported violations manually, then repeat steps 2 and 3 until clean.
5. Commit only after the lint command returns without errors.

## Environment and tools

- Develop plugin within `@wordpress/env` environment.
- Use Alpine‑based Docker containers if setting up with Docker.
- For Linux commands: assume **Ubuntu Server**.
- On macOS desktop (when relevant): use **Homebrew** to install tools.
- Use `vim` as terminal editor, not `nano`.

## Frontend technologies

- In admin or public UI, use **Bootstrap 5** and **jQuery** consistently.
- Keep frontend assets minimal: enqueue properly via WP APIs, use minified versions.

## Code style and structure

- All PHP functions and methods must have English docblock comments immediately before declaration.
- Prefer simplicity and clarity: avoid overly complex abstractions.
- Load translation strings properly (`__()`, `_e()`), text domain declared in main plugin file.
- Keep plugin bootstrap file small (`exelearning.php`), modularize into separate files/classes with specific responsibility.
- Keep the reference docs under `docs/` in sync with the code: `docs/SHORTCODES.md` documents the `[exelearning]` shortcode and all its attributes (including `teacher_mode` and `screenshot`), and `docs/HOOKS.md` documents the developer actions and filters. When you add or change a shortcode attribute or a hook, update the matching doc in the same change.

## Architecture decisions and design documents

Significant technical work is documented alongside the code under
[`docs/architecture/`](docs/architecture/README.md). Full policy:
[ADR guide](docs/architecture/adr/README.md),
[SDD guide](docs/architecture/sdd/README.md).

- Before implementing a significant architectural change, check
  [`docs/architecture/adr/records.md`](docs/architecture/adr/records.md) and
  [`docs/architecture/sdd/records.md`](docs/architecture/sdd/records.md).
- **Create or update an SDD** for large changes, cross-cutting features,
  security-sensitive changes, data/storage changes, REST API changes,
  shortcode/block contract changes, build/distribution changes, or changes
  affecting the embedded editor bundling/build flow. SDDs live under
  `docs/architecture/sdd/`.
- **Create an ADR** for durable technical decisions with long-term consequences
  (storage layout, ELPX validation/extraction, content-proxy security model,
  style registry, capability/nonce boundaries, REST/shortcode/block contracts).
  ADRs live under `docs/architecture/adr/`.
- Templates: `docs/architecture/adr/template.md`,
  `docs/architecture/sdd/template.md`. IDs are monotonic and never reused.
- Keep **accepted ADRs append-only** — supersede them with a new ADR
  (`supersedes` / `superseded_by`) instead of rewriting history. Preserve
  **implemented SDDs** as historical records; fix only typos/links.
- When both exist, **link the SDD and the ADR** (the SDD's *ADRs required or
  referenced* table and the ADR's `related.sdds`).
- Record AI assistance in the frontmatter (`ai_assistance.tool` /
  `ai_assistance.model`; `none` if not used). Use issue/PR links for
  attribution — no people's names in frontmatter or templates.
- **Do not** create ADRs/SDDs for trivial fixes, copy edits, translation-only or
  test-only changes, or straightforward bug fixes that do not change
  architecture.
- Keep all architecture docs in English. For plugin code, continue following
  WPCS and the existing testing/linting rules above.

## Skills

Recurring procedures live as skills in `.agents/skills/`, the path GitHub
Copilot, Codex and other agents read directly. Claude Code reads
`.claude/skills/`, which contains **symlinks** to those same directories, not
copies. When adding a skill, create it in `.agents/skills/` and link it from
`.claude/skills/`; never duplicate a `SKILL.md`.

| Skill | Read it before | Origin |
| --- | --- | --- |
| `wp-plugin-development` | Touching hooks, activation/uninstall, the Settings API, options, cron or release packaging | [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills), GPL-2.0-or-later |
| `wp-rest-api` | Adding or debugging routes: `register_rest_route`, `permission_callback`, schema/args, `register_meta`, `show_in_rest` — i.e. `includes/class-exelearning-rest-api.php` | idem |
| `wp-plugin-directory-guidelines` | Editing `readme.txt`, license headers or plugin naming — this is what `make check-plugin` enforces | idem |
| `blueprint` | Editing `blueprint.json` or the Playground preview | idem |
| `security-audit` | Hunting vulnerabilities and validating findings | [`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill) |

All of them are **third party and vendored verbatim**. Do not reformat or edit
them: diverging from upstream makes future updates harder. Fix the problem
upstream and re-vendor instead. The same set is used in `wp-decker`,
`wp-documentate` and `wp-autofirma`.

Skills are kept out of the release ZIP by `.distignore` and out of the source ZIP
by `.gitattributes`. Those two files have separate jobs and must not be kept in
sync with each other:

- **`.distignore`** decides what ships. It is the only list `wp dist-archive`
  reads, so it is the single source of truth for the release ZIP. Its rules match
  case-insensitively and at any depth unless anchored with a leading slash, which
  is why root-only rules carry one — an unanchored rule reaches inside the
  bundled editor under `dist/static/`. See
  [ADR-0003](docs/architecture/adr/ADR-0003-distignore-single-source-of-truth.md).
- **`.gitattributes`** only shapes the source ZIP GitHub serves at
  `archive/refs/heads/*.zip`, which `blueprint.json` installs in Playground. Keep
  it short; untracked paths never reach a git archive and need no rule.

## Aider-specific usage

- Always load `AGENTS.md` as conventions file: e.g. `/read AGENTS.md` or via config.
- Do not expect Aider to modify `AGENTS.md` or `README.md` contents.
- Use `/ask` mode to plan large changes, then use `/code` or `/architect` to apply.
- Review every diff Aider produces, especially in architect mode before accepting.
- After planning, say “go ahead” to proceed.
- Avoid adding unnecessary files to the chat—add only those being modified.

