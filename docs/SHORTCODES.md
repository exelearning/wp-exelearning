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
| `width` | string | `100%` | Positive pixels (`800`, `800px`) or percentages (`75%`, `100%`) | Width of the embed box. A bare number gets a `px` suffix. Any other value falls back to `100%`. The box is capped at `max-width: 100%` so a fixed width still shrinks on small screens. See [Sizing](#sizing). |
| `height` | string | `600px` | Positive pixels (`600`, `600px`) or percentages (`75%`, `100%`) | Height of the preview iframe (and of the screenshot poster). A bare number gets a `px` suffix. Any other value — `0`, negatives, `calc()`, `var()`, viewport units, injection attempts — falls back to `600px`. See [Sizing](#sizing). |
| `teacher_mode` | bool | `0` | `0`/`1`, `false`/`true`, `no`/`yes` | When enabled, the **teacher layer selector** is offered in the embed so viewers can reveal teacher-only content. It no longer auto-reveals on load — the viewer turns it on. |
| `teacher_mode_visible` | bool | `0` | `0`/`1`, `false`/`true`, `no`/`yes` | Controls whether the **teacher layer selector** is available inside the embed. Off by default; set to `1` to make it available. |
| `show_download` | bool | `0` | `0`/`1`, `false`/`true`, `no`/`yes` | When enabled, renders a multi-format download button in the toolbar. |
| `download_formats` | string | *(empty → all)* | Comma-separated list of `elpx`, `html5`, `scorm12`, `ims`, `epub3` | Restricts the download button to the listed formats. Empty means all available formats. Only applies when `show_download` is enabled. |
| `screenshot` | string | `no` | `no`, `poster`, `only` | Controls whether the package screenshot is shown. See [Screenshots](#screenshots). |
| `fullscreen` | bool | `0` | `0`/`1`, `false`/`true`, `no`/`yes`, `off`/`on` | Show the **Fullscreen** button in the toolbar. Off by default; set to `1` to show it. Unknown values fall back to the default. See [Fullscreen](#fullscreen). |

### Teacher mode

eXeLearning packages can mark content as *teacher-only*. Exported packages hide
that content by default and expose an in-page **teacher layer selector** to
reveal it through the `?exe-teacher=1` URL parameter. The plugin appends that
parameter to the embed instead of injecting any CSS or JavaScript into the
package:

- `teacher_mode_visible` (default `0`) decides whether the **selector is
  available** in the embed. It is hidden by default; set it to `1` to make it
  available.
- `teacher_mode` (default `0`), when enabled, also makes the selector available.
  It no longer auto-reveals teacher content on load — the selector is shown but
  stays off until the viewer turns it on.

For example, to offer the selector explicitly:

```text
[exelearning id="123" teacher_mode_visible="1"]
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

### Sizing

`width` and `height` both accept positive pixel values and positive percentages:

| Input | Rendered |
|-------|----------|
| `600` | `600px` |
| `600px` | `600px` |
| `75%` | `75%` |
| `100%` | `100%` |

`width` applies to the embed box (default `100%`) and is capped with
`max-width: 100%`, so a fixed pixel width still shrinks to fit small screens.
`height` applies to the preview iframe (and the screenshot poster); a percentage
height resolves against the embed's container, so a page that uses `height="75%"`
must give the surrounding element a height for the value to take visible effect.
Anything outside the two supported forms (`0`, negative numbers, `calc()`,
`var()`, viewport units such as `100vh`, or attempted injections) is rejected and
falls back to the default (`100%` for width, `600px` for height).

### Fullscreen

The **Fullscreen** button is off by default (opt-in, like the download button).
Enable it with `fullscreen="1"` (or `true`/`yes`/`on`); `fullscreen="0"` (or
`false`/`no`/`off`) keeps it hidden. Unknown values fall back to the default:

```text
[exelearning id="123" fullscreen="1"]
```

The Download and Fullscreen controls share the toolbar button styling, expose
accessible names (`aria-label`), and mark their icons as decorative
(`aria-hidden`).

## Examples

Basic embed:

```text
[exelearning id="123"]
```

Custom height:

```text
[exelearning id="123" height="800"]
```

Percentage size:

```text
[exelearning id="123" width="100%" height="75%"]
```

Show the fullscreen button (off by default):

```text
[exelearning id="123" fullscreen="1"]
```

Offer the teacher layer selector (hidden by default):

```text
[exelearning id="123" teacher_mode_visible="1"]
```

The selector is hidden unless you enable it, so a bare embed keeps teacher-only
content hidden:

```text
[exelearning id="123"]
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
