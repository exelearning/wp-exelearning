# Shortcode reference

The eXeLearning plugin exposes a single shortcode, `[exelearning]`, to embed an
uploaded `.elpx` package anywhere classic-editor content is rendered (posts,
pages, widgets, or any context that runs `do_shortcode()`).

The shortcode works directly with **WordPress attachments**: you reference an
uploaded package by its Media Library attachment ID. No custom post type is
involved.

```text
[exelearning id="123"]
```

Replace `123` with the attachment ID of your `.elpx` file (visible in the Media
Library, e.g. in the URL `…/upload.php?item=123`).

## How it renders

- If the attachment has been extracted and contains a previewable `index.html`,
  the shortcode renders a sandboxed, same-origin `<iframe>` served through the
  plugin's secure content proxy.
- If the attachment is a source file without a preview, the shortcode renders a
  download notice instead.
- An invalid or missing ID renders an inline error message.

## Attributes

| Attribute | Type | Default | Allowed values | Description |
|-----------|------|---------|----------------|-------------|
| `id` | int | `0` | Any attachment ID | **Required.** Media Library attachment ID of the `.elpx` package. Nothing renders without a valid ID. |
| `height` | int | `600` | Pixels | Height of the preview iframe (and of the screenshot poster) in pixels. |
| `teacher_mode` | bool | `0` | `0`/`1`, `false`/`true`, `no`/`yes` | When enabled, the embedded content loads with **teacher mode active**, so teacher-only content is shown from the start. |
| `teacher_mode_visible` | bool | `1` | `0`/`1`, `false`/`true`, `no`/`yes` | Controls whether the teacher-mode **toggle button** is visible inside the embed. Set to `0` to hide it. |
| `show_download` | bool | `0` | `0`/`1`, `false`/`true`, `no`/`yes` | When enabled, renders a multi-format download button in the toolbar. |
| `download_formats` | string | *(empty → all)* | Comma-separated list of `elpx`, `html5`, `scorm12`, `ims`, `epub3` | Restricts the download button to the listed formats. Empty means all available formats. Only applies when `show_download` is enabled. |
| `screenshot` | string | `no` | `no`, `poster`, `only` | Controls whether the package screenshot is shown. See [Screenshots](#screenshots). |

### Teacher mode

eXeLearning packages can mark content as *teacher-only*. The embedded viewer
ships a toggle that switches teacher mode on and off; the two attributes above
control it independently:

- `teacher_mode` decides the **initial state** (off by default).
- `teacher_mode_visible` decides whether the **toggle button** is shown.

They compose freely. For example, to always present teacher content and hide the
toggle so visitors cannot switch it off:

```text
[exelearning id="123" teacher_mode="1" teacher_mode_visible="0"]
```

### Screenshots

Packages built with **eXeLearning ≥ 4.0.1** ship a `screenshot.png` at the root
of the package. The `screenshot` attribute uses it as follows:

| Value | Behavior |
|-------|----------|
| `no` *(default)* | Embed the interactive content directly in an iframe. |
| `poster` | Show `screenshot.png` as a clickable poster; the iframe is loaded only when the visitor clicks it. Ideal for pages with several embeds (lazy loading). |
| `only` | Render just the screenshot as a static image — no iframe. |

When a package has **no** `screenshot.png` (e.g. it predates 4.0.1), both
`poster` and `only` gracefully fall back to the normal iframe embed, so existing
content keeps working.

## Examples

Basic embed:

```text
[exelearning id="123"]
```

Custom height:

```text
[exelearning id="123" height="800"]
```

Activate teacher mode on load:

```text
[exelearning id="123" teacher_mode="1"]
```

Hide the teacher-mode toggle:

```text
[exelearning id="123" teacher_mode_visible="0"]
```

Show a download button limited to specific formats:

```text
[exelearning id="123" show_download="1" download_formats="elpx,scorm12,epub3"]
```

Lazy-load the embed behind its screenshot:

```text
[exelearning id="123" screenshot="poster"]
```

Show only the screenshot image:

```text
[exelearning id="123" screenshot="only"]
```

## Related developer hooks

The shortcode output can be customized through several filters, documented in
full in [`docs/HOOKS.md`](HOOKS.md):

- `exelearning_shortcode_atts` — adjust the attributes after defaults are merged.
- `exelearning_preview_url` — wrap or adjust the proxy preview URL.
- `exelearning_shortcode_output` — wrap or modify the final rendered HTML.

These filters are limited to presentation: they cannot bypass the attachment and
permission checks, change which file is rendered, or weaken the content-proxy
security model.
