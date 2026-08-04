# eXeLearning WordPress Plugin

![CI](https://img.shields.io/github/actions/workflow/status/exelearning/wp-exelearning/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/exelearning/wp-exelearning/graph/badge.svg)](https://codecov.io/gh/exelearning/wp-exelearning)
![WordPress Version](https://img.shields.io/badge/WordPress-6.1-blue)
![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.0-8892bf)
![License: AGPL v3](https://img.shields.io/badge/License-AGPLv3-blue.svg)
![Downloads](https://img.shields.io/github/downloads/exelearning/wp-exelearning/total)
![Last Commit](https://img.shields.io/github/last-commit/exelearning/wp-exelearning)

WordPress plugin for eXeLearning content management. Upload, manage and embed eXeLearning `.elpx` files directly in your WordPress site.

## Demo

Try eXeLearning instantly in your browser using WordPress Playground! Note that all changes will be lost when you close the browser window, as everything runs locally in your browser.

<a href="https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/exelearning/wp-exelearning/refs/heads/main/blueprint.json"><img src=".github/playground-preview-button.svg" alt="Preview in WordPress Playground" width="224"></a>

> ℹ️ The embedded eXeLearning editor is fetched from the shared eXeLearning release and unpacked into the plugin automatically when the Playground boots, so the editor works out of the box. No manual installation is needed; all other plugin features (ELPX upload, shortcode, Gutenberg block, preview) also work normally.

## Features

- **ELPX File Support**: Upload and manage eXeLearning `.elpx` files through the WordPress Media Library
- **Automatic Extraction**: ELPX files are automatically extracted and ready to display
- **Embedded Editor**: Edit eXeLearning content directly from WordPress without leaving the browser
- **Gutenberg Block**: Embed eXeLearning content using the native block editor
- **Shortcode Support**: Use `[exelearning id="123"]` to embed content in classic editor
- **Media Library Integration**: View ELPX metadata directly in the media library

## Installation

### From Releases (Recommended)

1. **Download the latest release** from the [GitHub Releases page](https://github.com/exelearning/wp-exelearning/releases).
2. Upload the downloaded ZIP file via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin.

### From Source (Development)

If you clone the repository directly, you must build the eXeLearning editor:

```bash
git clone https://github.com/exelearning/wp-exelearning.git
cd wp-exelearning
make build-editor
```

By default, `make build-editor` fetches `https://github.com/exelearning/exelearning` from `main` using a shallow checkout. You can override source/ref at runtime:

```bash
EXELEARNING_EDITOR_REF=vX.Y.Z EXELEARNING_EDITOR_REF_TYPE=tag make build-editor
# or
EXELEARNING_EDITOR_REF=my-feature EXELEARNING_EDITOR_REF_TYPE=branch make build-editor
```

> **Important:** For production use, always install an official release from [Releases](https://github.com/exelearning/wp-exelearning/releases): release packages include the embedded editor pre-built under `dist/static/`, and that bundle is the only editor the plugin ever uses. The plugin never downloads editor code at runtime, and administrators cannot update the editor independently of the plugin — updating the editor means updating the plugin (a new plugin release is published automatically for every editor release). Source checkouts do not contain `dist/static/`; build it with `make build-editor` as shown above. See [ADR-0002](docs/architecture/adr/ADR-0002-bundle-editor-exclusively-in-release-packages.md).

### Server configuration (nginx)

The live editor **preview** requires **pretty permalinks** (Settings → Permalinks; any structure other than *Plain*) — under plain permalinks the plugin fails closed and shows an admin notice.

On **nginx**, also block direct web access to the ephemeral preview session store: the plugin serves those bytes through an opaque-origin capability URL with a sandbox CSP, and unlike Apache (`.htaccess`) nginx will otherwise serve the materialized author HTML same-origin without that CSP. Include the shipped snippet from your `server { … }` block:

```nginx
include /path/to/wp-content/plugins/exelearning/nginx-exelearning-preview.conf;
```

See [`nginx-exelearning-preview.conf`](nginx-exelearning-preview.conf) and [`docs/preview-serving-contract.md`](docs/preview-serving-contract.md) for details.

## Usage

### Uploading ELPX Files

1. Go to **Media > Add New** in your WordPress admin
2. Upload your `.elpx` file
3. The file will be automatically validated and extracted

### Embedding Content

**Using Gutenberg Block:**
1. Add a new block in the editor
2. Search for "eXeLearning"
3. Select an ELPX file from your media library

**Using Shortcode:**
```
[exelearning id="123"]
```
Replace `123` with the attachment ID of your ELPX file. The shortcode also
accepts options to set the height, activate teacher mode, show a download button,
or display the package screenshot:

```
[exelearning id="123" height="800" teacher_mode="1" screenshot="poster"]
```

See [`docs/SHORTCODES.md`](docs/SHORTCODES.md) for the full shortcode reference,
all attributes, and examples.

### Viewing ELPX Files

- Go to **Media > Library** to see all uploaded files
- ELPX files display metadata including license, language, and resource type
- Click on an ELPX file to preview its content

### Managing styles

Administrators can upload eXeLearning style packages and control which styles the embedded editor exposes from **Settings → eXeLearning → Styles**.

- Upload one or more `.zip` style packages. A valid package contains a `config.xml` that declares at least a `<name>`, plus a `style.css` and any supporting assets.
- Uploaded styles extract to `wp-content/uploads/exelearning-styles/<slug>/` and are never written inside `dist/static/`, so reinstalling the embedded editor does not destroy them.
- Each built-in style can be hidden individually. Hidden built-ins disappear from the editor's style selector but remain on disk.
- The editor refuses to install styles from imported content or other unapproved sources while the admin-managed registry is active.
- Projects that reference a disabled or deleted style fall back to the editor's default style instead of failing to open.

Uploaded ZIPs are validated against path traversal, absolute paths, oversize archives (default 20 MB, filterable via `exelearning_styles_max_zip_size`), and a strict file-extension allow-list.

## External embeds in secure mode

In secure mode the `.elpx` content runs in a sandboxed, opaque-origin iframe. That
opaque origin propagates to any nested iframe, so cross-origin video players and PDF
viewers render blank. To keep them working, whitelisted video embeds (YouTube and
Vimeo hosts), any cross-origin `https` `.pdf`, and the package's own local PDFs are
*promoted* to the trusted parent page and rendered inline on top of the content.

Two cooperating scripts make this work:

- `assets/js/exe-embed-shim.js` runs inside the content iframe, replaces each
  promotable iframe with a same-size placeholder, and `postMessage`s its geometry
  and URL to the parent.
- `assets/js/exe-embed-relay.js` runs on the host page, validates each reported URL
  against the whitelist, rebuilds the canonical player URL, and overlays the real
  player exactly over the placeholder.

A static Firefox end-to-end test exercises the real shim and relay against a
self-contained harness (no WordPress runtime needed):

```bash
npm run test:e2e:embed
```

## Developer hooks

The plugin exposes a set of WordPress actions and filters (all prefixed with
`exelearning_`) at its main lifecycle boundaries, so you can observe events and
enrich presentation or metadata without modifying the plugin:

- **ELPX extraction** — `exelearning_before_elpx_extract`, `exelearning_after_elpx_extract`
- **Metadata** — `exelearning_elpx_metadata` (filter), `exelearning_after_elpx_metadata_saved`
- **REST save** — `exelearning_before_elpx_save`, `exelearning_after_elpx_save`
- **Shortcode rendering** — `exelearning_shortcode_atts`, `exelearning_preview_url`, `exelearning_shortcode_output` (filters)
- **Styles** — `exelearning_after_style_installed`, `exelearning_after_style_deleted`, `exelearning_after_style_enabled_changed`, `exelearning_style_registry_entry` (filter)

These hooks are limited to observation and presentation/metadata enrichment: they
cannot bypass validation, capability/nonce checks, path-traversal protection,
checksum verification, or the content-proxy security model.

See [`docs/HOOKS.md`](docs/HOOKS.md) for the full reference, parameters, return
values, and usage examples.

## Development

For development, you can bring up a local WordPress environment with the plugin pre-installed:

```bash
make up
```

This will start a Dockerized WordPress instance at [http://localhost:8888](http://localhost:8888) with credentials:
- Username: `admin`
- Password: `password`

### Available Commands

```bash
make up                 # Start development environment
make down               # Stop containers
make test               # Run PHPUnit tests
make lint               # Check code style
make fix                # Auto-fix code style
make translations       # Regenerate .pot/.po/.mo/.json translation files
make check-translations # Validate translation files are complete and up to date
```

### Translations

`languages/*.po` files are the editable translation sources; the `.mo` and
hashed `.json` files are generated runtime artifacts and must not be hand-edited
or renamed. Run `make translations` to regenerate them and `make
check-translations` to validate them. See
[`docs/TRANSLATIONS.md`](docs/TRANSLATIONS.md) for the full workflow, including
how to add a locale or a new translatable JavaScript source.

### Architecture documentation

Durable architecture decisions and significant designs are recorded under
[`docs/architecture/`](docs/architecture/README.md): Architecture Decision
Records ([ADRs](docs/architecture/adr/README.md)) and Software Design Documents
([SDDs](docs/architecture/sdd/README.md)). Review these before proposing a
significant architectural or design change.

### Working with AI coding agents

Coding conventions live in [`AGENTS.md`](AGENTS.md); [`CLAUDE.md`](CLAUDE.md)
describes how the plugin is put together.

Reusable procedures ship as agent skills in `.agents/skills/` (symlinked from
`.claude/skills/`):

| Skill | Read it before |
|-------|----------------|
| `wp-plugin-development` | Hooks, activation/uninstall, Settings API, options, cron, packaging |
| `wp-rest-api` | The `/exelearning/v1/` routes: `register_rest_route`, `permission_callback`, schema |
| `wp-plugin-directory-guidelines` | `readme.txt`, license headers, naming — what `make check-plugin` enforces |
| `blueprint` | `blueprint.json` and the Playground preview |
| `security-audit` | Vulnerability hunting and finding validation |

The first four come from [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills)
(GPL-2.0-or-later), `security-audit` from
[`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill).
All are vendored verbatim — do not reformat or patch them locally. To add one,
drop it in `.agents/skills/<name>/` and
`ln -s ../../.agents/skills/<name> .claude/skills/<name>`.

None of it reaches the release ZIP.

## Requirements

- WordPress 6.1 or higher
- PHP 8.0 or higher

## Issues and Support

Issue tracking for this plugin is centralized in the main
[`exelearning/exelearning`](https://github.com/exelearning/exelearning) repository.
Please [open new issues there](https://github.com/exelearning/exelearning/issues/new),
and browse [existing `wordpress`-labeled issues](https://github.com/exelearning/exelearning/issues?q=is%3Aissue+label%3Awordpress)
before reporting a bug or requesting a feature.

## License

This plugin is licensed under the AGPL v3 or later.
