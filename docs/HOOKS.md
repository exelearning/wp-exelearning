# Developer hooks

The eXeLearning plugin exposes a set of WordPress actions and filters at its main
lifecycle boundaries so third-party developers and institutional integrations can
observe events and enrich presentation or metadata.

All hook names are prefixed with `exelearning_`.

## Security model

These hooks are intentionally limited to **observation** and **presentation/metadata
enrichment**. They do **not** let a callback bypass any security control. In
particular, no hook can:

- Skip ZIP, MIME, or path-traversal validation.
- Skip capability or nonce checks.
- Change the trusted storage directories or the extraction hash.
- Disable CSP/security headers or the content-proxy model.
- Introduce any remote editor source: the embedded editor is bundled inside
  the plugin package and is never downloaded at runtime (ADR-0002).
- Alter cleanup behavior in a way that could leave orphaned or unsafe files.

Every filter validates its return value defensively. Required internal keys are
always restored from the plugin's trusted values, so a misbehaving callback can add
data but can never drop or corrupt the data the plugin depends on.

---

## Actions

### `exelearning_before_elpx_extract`

Fires right before an `.elpx` archive is extracted, after it has already passed
open/validation and the zip-bomb count guard. Observation only — it must not be used
to bypass validation or change extraction behavior.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$file` | `string` | Source `.elpx` file path. |
| `$destination` | `string` | Destination directory the archive extracts into. |

```php
add_action(
    'exelearning_before_elpx_extract',
    function ( $file, $destination ) {
        error_log( sprintf( 'About to extract %s into %s', $file, $destination ) );
    },
    10,
    2
);
```

### `exelearning_after_elpx_extract`

Fires after an `.elpx` archive has been **successfully** extracted. It never fires on
a failed or partial extraction.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$file` | `string` | Source `.elpx` file path. |
| `$destination` | `string` | Final extraction directory. |
| `$metadata` | `array` | Metadata parsed from the archive. |

```php
add_action(
    'exelearning_after_elpx_extract',
    function ( $file, $destination, $metadata ) {
        error_log( sprintf( 'ELPX extracted: %s', $file ) );
    },
    10,
    3
);
```

### `exelearning_after_elpx_metadata_saved`

Fires after ELPX metadata has been written to the attachment post meta (both on
upload and on reprocessing).

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attachment_id` | `int` | WordPress attachment ID. |
| `$metadata` | `array` | Final metadata array that was saved. |

```php
add_action(
    'exelearning_after_elpx_metadata_saved',
    function ( $attachment_id, $metadata ) {
        // For example, index the resource in an external catalogue.
        do_my_catalogue_sync( $attachment_id, $metadata );
    },
    10,
    2
);
```

### `exelearning_before_elpx_save`

Fires before an existing `.elpx` file is replaced through the REST editor endpoint,
after the request has already passed capability and nonce checks. Observation only —
it must not change the storage path or replacement/cleanup behavior.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attachment_id` | `int` | Attachment being updated. |
| `$old_file_path` | `string` | Current file path before replacement. |

```php
add_action(
    'exelearning_before_elpx_save',
    function ( $attachment_id, $old_file_path ) {
        error_log( sprintf( 'Saving attachment %d', $attachment_id ) );
    },
    10,
    2
);
```

### `exelearning_after_elpx_save`

Fires after an `.elpx` file has been successfully saved and committed: the new file
is written, the new content is extracted, and metadata is persisted. It never fires
on a failed save, extraction, or metadata persistence.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attachment_id` | `int` | Updated attachment ID. |
| `$new_hash` | `string` | Hash of the new extracted content. |
| `$old_hash` | `string` | Previous extraction hash, or `''` if none. |

```php
add_action(
    'exelearning_after_elpx_save',
    function ( $attachment_id, $new_hash, $old_hash ) {
        error_log( sprintf( 'Attachment %d now at %s', $attachment_id, $new_hash ) );
    },
    10,
    3
);
```

### `exelearning_after_style_installed`

Fires after a style ZIP has been installed and registered.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$slug` | `string` | Style slug. |
| `$entry` | `array` | Final style registry entry that was persisted. |

```php
add_action(
    'exelearning_after_style_installed',
    function ( $slug, $entry ) {
        error_log( sprintf( 'Style installed: %s', $slug ) );
    },
    10,
    2
);
```

### `exelearning_after_style_deleted`

Fires after an uploaded style has been deleted (both the files on disk and the
registry entry).

| Parameter | Type | Description |
|-----------|------|-------------|
| `$slug` | `string` | Style slug that was deleted. |

```php
add_action(
    'exelearning_after_style_deleted',
    function ( $slug ) {
        error_log( sprintf( 'Style deleted: %s', $slug ) );
    }
);
```

### `exelearning_after_style_enabled_changed`

Fires after an uploaded style has been enabled or disabled.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$slug` | `string` | Style slug. |
| `$enabled` | `bool` | New enabled state. |

```php
add_action(
    'exelearning_after_style_enabled_changed',
    function ( $slug, $enabled ) {
        error_log( sprintf( 'Style %s enabled=%s', $slug, $enabled ? 'yes' : 'no' ) );
    },
    10,
    2
);
```

---

## Filters

### `exelearning_elpx_metadata`

Filters the ELPX metadata array before it is saved to attachment meta. Callbacks may
enrich the array with additional keys but **must return an array**. Required internal
keys (`_exelearning_title`, `_exelearning_description`, `_exelearning_license`,
`_exelearning_language`, `_exelearning_resource_type`, `_exelearning_extracted`,
`_exelearning_version`, `_exelearning_has_preview`) are always restored afterwards,
so this filter cannot drop or tamper with the plugin's own metadata or the extraction
hash.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$metadata` | `array` | Metadata array keyed by post meta key. |
| `$file` | `string` | Source `.elpx` file path, or `''` when unavailable. |
| `$elp_service` | `ExeLearning_Elp_File_Service` | The service that parsed the archive. |

**Returns:** `array` — the enriched metadata array.

```php
add_filter(
    'exelearning_elpx_metadata',
    function ( $metadata, $file, $elp_service ) {
        $metadata['_exelearning_department'] = 'Mathematics';
        return $metadata;
    },
    10,
    3
);
```

### `exelearning_shortcode_atts`

Filters the `[exelearning]` shortcode attributes after defaults are merged and before
rendering. The values the renderer relies on are re-sanitized afterwards, so this
filter cannot inject unsafe values or bypass the attachment/permission checks.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$atts` | `array` | Sanitized shortcode attributes. |
| `$file_id` | `int` | Attachment ID parsed from the shortcode. |

**Returns:** `array` — the shortcode attributes.

```php
add_filter(
    'exelearning_shortcode_atts',
    function ( $atts, $file_id ) {
        $atts['height'] = 900;
        return $atts;
    },
    10,
    2
);
```

### `exelearning_preview_url`

Filters the preview URL before it is rendered into the iframe. The value is still
escaped with `esc_url()` at output time. This filter must **not** be used to bypass
the content-proxy security model; pointing the iframe at an unverified external
origin is unsupported.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$preview_url` | `string` | Proxy preview URL. |
| `$file_id` | `int` | Attachment ID. |
| `$extracted_dir` | `string` | Extraction hash/directory for the attachment. |

**Returns:** `string` — the preview URL.

```php
add_filter(
    'exelearning_preview_url',
    function ( $preview_url, $file_id, $extracted_dir ) {
        return add_query_arg( 'lang', 'en', $preview_url );
    },
    10,
    3
);
```

### `exelearning_shortcode_output`

Filters the final shortcode HTML before it is returned. It receives the
already-rendered, escaped HTML and lets themes or integrations wrap or modify it. The
default output is unchanged when no callback is attached. Any HTML added by a callback
is its own responsibility to keep safe.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$html` | `string` | Rendered shortcode HTML. |
| `$file_id` | `int` | Attachment ID. |
| `$atts` | `array` | Shortcode attributes used to render the output. |

**Returns:** `string` — the shortcode HTML.

```php
add_filter(
    'exelearning_shortcode_output',
    function ( $html, $file_id, $atts ) {
        return '<div class="my-wrapper">' . $html . '</div>';
    },
    10,
    3
);
```

### `exelearning_style_registry_entry`

Filters a style registry entry before it is persisted. **Must return an array**; a
non-array return is discarded. Required internal keys (`css_files`, `enabled`,
`installed_at`, `checksum`, `size`) are always restored from the trusted built entry
so this filter cannot inject unsafe paths, strip integrity fields, or bypass ZIP
validation.

| Parameter | Type | Description |
|-----------|------|-------------|
| `$entry` | `array` | Style registry entry built from the validated ZIP. |
| `$slug` | `string` | Allocated style slug. |
| `$config` | `array` | Parsed style configuration. |

**Returns:** `array` — the registry entry.

```php
add_filter(
    'exelearning_style_registry_entry',
    function ( $entry, $slug, $config ) {
        $entry['category'] = 'institutional';
        return $entry;
    },
    10,
    3
);
```

---

## Existing configuration filters

The plugin also exposes a few low-level configuration filters used as safety limits:

| Filter | Default | Purpose |
|--------|---------|---------|
| `exelearning_max_extract_files` | `10000` | Maximum number of files allowed in an ELPX archive. |
| `exelearning_max_extract_bytes` | `1073741824` (1 GB) | Maximum uncompressed extraction size. |
| `exelearning_styles_max_zip_size` | `20 MB` | Maximum uploaded style ZIP size. |
| `exelearning_content_origin` | `''` | Origin URL used when serving extracted content. |
| `exelearning_proxy_assets` | `false` | Whether package assets (CSS, JS, fonts, images, media) are served through the WordPress content proxy with explicit `Content-Type` headers instead of being linked directly from the uploads directory. Overrides the **Content delivery** setting; useful to force the mode on for servers with an incorrect MIME configuration (e.g. JavaScript returned as `text/plain` with `nosniff`). |
