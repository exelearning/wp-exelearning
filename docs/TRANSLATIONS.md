# Translations (internationalization)

This plugin ships translations for its PHP strings and for the translatable
strings used by its block editor JavaScript. This document explains which files
are editable sources, which are generated runtime artifacts, and how to
regenerate and validate them.

## File types

All translation files live in [`languages/`](../languages).

| File | Role | Committed? | Shipped in the release? |
|------|------|------------|--------------------------|
| `exelearning.pot` | Template of all extractable strings | Yes | No (`export-ignore`) |
| `exelearning-<locale>.po` | **Editable source** — the human translations | Yes | No (`export-ignore`) |
| `exelearning-<locale>.mo` | Generated PHP runtime translations | Yes | Yes |
| `exelearning-<locale>-<md5>.json` | Generated JavaScript runtime translations | Yes | Yes |

* **`.po` files are the source of truth.** Translators edit these.
* **`.mo` and hashed `.json` files are generated.** Never hand-edit them.

### The hashed JSON filenames

WordPress serves JavaScript translations from files named:

```
{text-domain}-{locale}-{md5-of-relative-script-path}.json
```

For this plugin the only script with translatable strings is
`assets/js/elp-upload.js`, so the hash is:

```
md5("assets/js/elp-upload.js") = 4cf813b039eac98ce62f176afa8fc8fe
```

and the generated files are `exelearning-<locale>-4cf813b039eac98ce62f176afa8fc8fe.json`.

**These names are required by WordPress and must not be renamed or
consolidated.** At runtime `wp_set_script_translations( 'exelearning-elp-block',
'exelearning', …/languages )` makes WordPress look up exactly this filename
(from the md5 of the script's registered relative path). A "friendly" name or a
single combined file would never be found, and the strings would silently fall
back to English.

## Regenerating translations

```bash
make translations
```

This runs the full, deterministic pipeline:

1. `make-pot` — regenerate `exelearning.pot` (fixed `--slug=exelearning`).
2. remove the non-deterministic `POT-Creation-Date` header.
3. `update-po` — merge new strings into every `.po` (preserving each
   `PO-Revision-Date`, which `wp i18n update-po` would otherwise rewrite to the
   current time and make the output non-reproducible).
4. fail if any required string is untranslated.
5. `make-mo` — regenerate the `.mo` files.
6. delete stale generated JSON, then regenerate it with `wp i18n make-json
   --pretty-print`.

Running `make translations` twice must leave the working tree unchanged.

Individual steps are also available: `make pot`, `make po`, `make mo`,
`make json`.

## Validating translations

```bash
make check-translations
```

This fails when:

* a `.po` file has untranslated strings;
* a required JSON file is missing, invalid, or not in the expected Jed
  structure;
* a JSON filename hash is not the md5 of its declared `"source"`, or that source
  does not exist;
* the locale in a JSON filename does not match the locale inside the file;
* an orphaned or otherwise unexpected JSON file exists;
* any generated `.mo`/`.json`/`.pot` file is stale (tracked **or** untracked)
  or the generation is not deterministic.

Run `make i18n-audit` to run the WordPress i18n audit over the codebase.

## When to commit generated files

Commit the regenerated `languages/` files whenever you change:

* a translatable string (PHP or JavaScript), or
* the translations in a `.po` file, or
* a source file in a way that shifts the line references recorded in the
  `.po`/`.pot`.

CI runs `make check-translations` and fails if the committed `.mo`/`.json`/`.pot`
files do not match a fresh generation, so regenerate and commit them in the same
change. To fix a CI failure locally: `make translations` and commit the diff
under `languages/`.

## How to add a new locale

1. Create the PO file from the template, e.g. for French:

   ```bash
   cp languages/exelearning.pot languages/exelearning-fr_FR.po
   ```

   (or use `msginit --locale=fr_FR`). Fill in the `Language:` header and
   translate the strings.
2. Run `make translations` to generate the matching `.mo` and hashed `.json`.
3. Commit the new `.po`, `.mo` and `.json` files.

The validator discovers locales from the `.po` filenames, so a new locale
automatically becomes part of the contract (it must have one JSON per
translatable JavaScript source).

## How to add a new JavaScript source with translations

1. Use **literal** i18n calls so WP-CLI can extract them, and import from
   `wp.i18n`:

   ```js
   var __ = wp.i18n.__;
   var label = __( 'My label', 'exelearning' );   // literal string + literal domain
   ```

   Dynamic strings such as `__( variable, 'exelearning' )` cannot be extracted.
2. Register the script and attach translations **after** registration:

   ```php
   wp_register_script( 'my-handle', plugins_url( 'assets/js/my-script.js', EXELEARNING_PLUGIN_FILE ), …, true );
   wp_set_script_translations( 'my-handle', 'exelearning', EXELEARNING_PLUGIN_DIR . 'languages' );
   ```

   Keep the URL normalized (based on `EXELEARNING_PLUGIN_FILE`) so it resolves to
   the clean relative path — the md5 of that path is the JSON filename.
3. Run `make translations`. WordPress generates one
   `exelearning-<locale>-<md5-of-your-script-path>.json` per locale.
4. Commit the new/updated `languages/` files.

## When `--use-map` is needed

`wp i18n make-json` computes the JSON hash from the source path recorded in the
`.po` file, which must match the **relative path of the script that WordPress
registers**. In this plugin the enqueued path (`assets/js/elp-upload.js`) is the
same as the path scanned by `make-pot`, so no mapping is required.

`--use-map` (a source-to-build path map) is only necessary when the shipped
JavaScript is bundled/transpiled and its enqueued path differs from the original
source path recorded in the `.po` (for example enqueuing `build/index.js` while
the strings were extracted from `src/index.js`). If that ever becomes the case,
add a `--use-map=<map>` argument to the `make-json` step so the generated JSON is
named after the **enqueued** path's md5.

## References

* [Plugin internationalization](https://developer.wordpress.org/plugins/internationalization/)
* [Internationalization of JavaScript](https://developer.wordpress.org/block-editor/how-to-guides/internationalization/)
* [`wp_set_script_translations()`](https://developer.wordpress.org/reference/functions/wp_set_script_translations/)
* [`wp i18n make-json`](https://developer.wordpress.org/cli/commands/i18n/make-json/)
* [`load_plugin_textdomain()`](https://developer.wordpress.org/reference/functions/load_plugin_textdomain/)
