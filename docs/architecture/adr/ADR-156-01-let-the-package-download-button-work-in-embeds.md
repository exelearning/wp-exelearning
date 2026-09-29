---
id: ADR-156-01
title: "Let the package's own .elpx download button work in embeds"
status: Proposed
date: 2026-09-29
tracking_issue: 156
deciders:
  - "@erseco"
  - "claude-code"
related:
  prs: [156]
  changes: []
  adrs: []
external_refs:
  - "https://github.com/exelearning/exelearning/issues/2488"
  - "https://github.com/exelearning/exelearning/pull/2489"
  - "https://github.com/exelearning/exelearning/pull/2196"
  - "https://github.com/exelearning/omeka-s-exelearning/pull/63"
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5-5"
---

# ADR-156-01: Let the package's own .elpx download button work in embeds

## Context

eXeLearning packages can contain a *download-source-file* iDevice: a "Download
.elpx" button inside the content. Its inline `onclick` calls the package's global
`downloadElpx()` (`libs/exe_elpx_download/exe_elpx_download.js`). That function
refetches every file listed in `libs/elpx-manifest.js`, zips the files in the
browser with fflate, and clicks an `<a download>` inside the content document.

In an embed, that button never saved a file. It failed in two independent places:

1. **CSP.** `ExeLearning_Content_Proxy` served HTML with `script-src 'self'
   'unsafe-inline' 'unsafe-eval'` and no `worker-src`. The async `fflate.zip()`
   starts `blob:` workers and listens only for their messages. The workers are
   blocked, the callback never fires, and the button stays at "Processing...
   100%" (exelearning/exelearning#2488).
2. **Sandbox.** The block, shortcode, Media Library and Gutenberg preview iframes
   use `sandbox="allow-scripts allow-same-origin allow-popups"`. Without
   `allow-downloads`, Chrome drops any download the frame starts.

Rebuilding is also a poor substitute for the original upload. It refetches the
whole package and holds it in memory two to three times over. Packages exported
before exelearning/exelearning#2196 rebuild without `content.xml`, so the result
cannot be re-imported.

## Problem

How should the package's own "Download .elpx" button behave in a WordPress
embed? The answer must cover packages that are already published, whose script
cannot be changed.

## Decision drivers

- Fix content that is already published, without re-uploading it.
- Give users the same file the toolbar already offers, byte for byte.
- Respect embeds that do not offer the `.elpx` (`show_download` off, the
  default).
- Do not widen what package JavaScript can do in the site origin.
- Keep working under a cross-origin `exelearning_content_origin`.

## Alternatives considered

### Option 1: Wait for the upstream script fix

exelearning/exelearning#2489 adds a `zipSync` fallback when workers are blocked.
It helps only packages exported after it ships, and the sandbox still drops the
download.

### Option 2: Relax the CSP and the sandbox only

Add `worker-src 'self' blob:` and `allow-downloads`. The rebuild then completes,
but it still refetches the whole package and may omit `content.xml`.

### Option 3: Serve the original from the parent, and relax the CSP and the sandbox as a fallback

While an embed's toolbar offers an enabled `.elpx` item, `wp-exe-download.js`
replaces the package's `downloadElpx()` with a function that downloads the
original attachment through the toolbar's own `downloadFormat()`. Everywhere else
the package's own rebuild runs, and Option 2's relaxations let it finish.

## Evidence

- The same CSP and sandbox reproduce on the Omeka S module: 34 `worker-src
  blob:` violations and a button stuck at 100 %. With the fixed script, Chrome
  logged "Download is disallowed … 'allow-downloads' is not set"
  (exelearning/omeka-s-exelearning#63).
- With the routing in place on that site, the in-content button downloaded the
  original, with the same SHA-256 as the upload.
- fflate 0.8.3 `zip()` starts a worker for each compressible file of 160 kB or
  more, and its browser worker wrapper sets only `onmessage`.
- `includes/class-content-proxy.php`, `includes/class-elp-upload-block.php`,
  `public/class-shortcodes.php`, `includes/integrations/class-media-library.php`
  and `assets/js/elp-upload.js` at `7ec2e82`.

## Decision

We will take Option 3:

- `assets/js/wp-exe-download.js` routes `downloadElpx()` per embed, only while
  that embed offers an enabled `.elpx` item. A capturing `load` listener
  reapplies it on each page the frame navigates to. A cross-origin frame raises
  an access error, which is caught.
- HTML content gets `worker-src 'self' blob:`.
- The embed iframes get `allow-downloads`.

Neither relaxation grants new capability. Package scripts already run with
`'unsafe-inline'` and `'unsafe-eval'` in the site origin and can start downloads
through the same-origin parent.

## Consequences

### Positive

- The in-content button works for every existing package: it gives the
  original when the toolbar offers it, and a completed rebuild otherwise.
- There is no full refetch or in-memory rebuild in the common case.

### Negative

- The routing reaches into package globals. If a future eXeLearning renames
  `downloadElpx`, the routing stops, and the package's own download still works.

### Neutral

- No change to the script-free SVG/XML policy, to extraction, or to the
  `.htaccess` rules.

## Risks

- A package could redefine `downloadElpx` after `load`. Its own download would
  then run instead of the original. Low severity.
- Moving content to an opaque or cross-origin frame in the future must keep
  `allow-downloads` and a `worker-src blob:` allowance, or the fallback breaks.

## Validation

- `tests/js/wp_exe_download.test.js` covers the routing, including scoping,
  disabled or missing `.elpx`, navigation, a cross-origin frame and several
  embeds on one page.
- PHPUnit shortcode, block and Media Library tests pin `allow-downloads`.
- Manual check with a package that contains the download-source-file iDevice,
  with `show_download` on and off.

## Follow-up work

- None required. Remove the routing if eXeLearning adds an explicit
  host-download protocol.

## References

- exelearning/exelearning#2488, #2489 and #2196
- exelearning/omeka-s-exelearning#63 (same fix, with a live reproduction)
