# Host-served opaque HTTP preview — serving contract v2

This plugin serves the eXeLearning **editor preview** of untrusted author
content (the live `.elpx` the author is editing) over HTTP in an **opaque
origin**, as the host-served alternative to the core `srcdoc` transport.

It implements the eXeLearning core canonical contract, which is the single
source of truth for the wire format, the layered model and the exact headers:

> eXe core: `doc/development/preview-serving-contract.md` (**protocol version 2**)

The two documents must not drift. Where this doc and core disagree, core wins,
and the CSP string below must stay **byte-identical** to core
(`previewCspHeader()` in `src/shared/security/previewSandbox.ts`).

## Why a separate transport

The wp-admin editor preview renders author-provided HTML/JS. Run same-origin it
could read the WordPress session, cookies and admin DOM. Served over this
contract it runs in a real **opaque origin** — an unguessable, cookieless
capability URL carrying a response-level `Content-Security-Policy: sandbox …`
(no `allow-same-origin`). This mirrors, for the *editor preview*, the isolation
the plugin already gives *published* content (`ExeLearning_Content_Proxy`).

## The three layers (v2)

A preview session splits into three layers with different lifecycles, so a
refresh costs `O(changed documents + new assets)` instead of re-uploading the
whole project on every keystroke:

| Layer | Contents | Transferred |
|---|---|---|
| **Fixed** (layer 1) | official libraries, base iDevice runtimes, base themes, PDF.js, logo, fonts | **never** — served from the installed static editor, gated by a build manifest |
| **Session assets** (layer 2) | author images/audio/video/PDF | **once per session**, immutable per `assetKey` |
| **Generated documents** (layer 3) | page HTML, generated CSS/JS, user theme/iDevice files | **only changed files**, as an atomic revision delta |

Implementation:

- `ExeLearning_Preview_Fixed_Resources` — manifest-gated layer 1 resolver.
- `ExeLearning_Preview_Session_Store` — file-backed layers 2 & 3: atomic
  revisions, immutable assets, budgets, idle TTL.
- `ExeLearning_Preview_Proxy` — the HTTP adapter (routes, headers, streaming).

## A. Management API (authenticated — the author's session)

REST namespace `exelearning/v1`, `permission_callback =
current_user_can('upload_files')` **plus** per-session ownership (the session
records the creating user id):

| Method & path | Body | Success |
|---|---|---|
| `POST /preview-session` | – | `201` `{ previewId, protocolVersion: 2, revision: 0, limits }` |
| `POST /preview-session/{previewId}/assets` | multipart: `assets` (JSON `[{ key, size }]`) + `files[]` index-aligned | `200` `{ stored, alreadyStored, rejected }` |
| `POST /preview-session/{previewId}/revisions` | multipart: `revision` (JSON meta) + `files[]` index-aligned with `writes` | `200` `{ revision, active: true }` |
| `DELETE /preview-session/{previewId}` | – | `200` `{ success: true }` |

- **Asset keys** match `^[0-9a-fA-F-]{36}@[0-9a-f]{8,64}$` and are **immutable**:
  re-uploading an existing key reports `alreadyStored` and never replaces the
  bytes (a replaced author file gets a new key). The byte budget is enforced
  **twice** — declared sizes before buffering, actual bytes in the store.
- **Revisions** validate in order: session exists (`404`) → `baseRevision` is the
  active revision **and** `nextRevision == baseRevision + 1`, else `409`
  `{ reason: 'revision-conflict', currentRevision }` → every path normalized and
  safe, else `400` → every `assetRefs` value stored, else `422`
  `{ reason: 'missing-assets', missing }` → every `fixedRefs` value in the
  manifest, else `422` `{ reason: 'unknown-fixed-resources', resources }` →
  file-count / byte budgets, else `413`.
- **Atomicity.** A revision is staged in a fresh directory (previous documents
  carried forward, deletes dropped, writes overlaid), renamed into place, then
  the `current` pointer is swapped atomically. The serving route reads the
  pointer **once per request** and resolves strictly under that one revision, so
  a concurrent GET never observes a mixed revision. All mutations serialize on a
  store-wide `flock`; serving is lock-free.

### WordPress upload limits (`post_max_size`)

`files[]` arrive as `$_FILES`. PHP caps the whole multipart body with
`post_max_size` / `upload_max_filesize`; a request over the limit reaches the
handler with **empty** `$_POST`/`$_FILES` (surfacing here as an index-alignment
`400`). The client batches uploads under the advertised
`limits.recommendedBatchBytes` (64 MiB) to stay well inside typical PHP limits;
admins serving very large media should raise `post_max_size` accordingly.

## B. Serving route (authless capability URL)

```
GET {rest}/exelearning/v1/preview/{previewId}/{path}
```

`permission_callback __return_true` — an opaque iframe sends no cookies, so the
capability is the unguessable `previewId` (a server-minted UUID) plus the idle
TTL, mirroring `ExeLearning_Content_Proxy`. `previewId` must match
`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`, else `404`.

**Resolution order** (exact-key lookups against the active revision only):
documents → `assetRefs`→assets → `fixedRefs`→manifest → `404`. Session-asset
responses advertise `Accept-Ranges: bytes`, honor single-range requests
(`206`/`416`) and carry `ETag: "<assetKey>"` with `If-None-Match` → `304`.

### Pretty permalinks are required

Relative subresource URLs inside a served page resolve against the document URL.
With pretty permalinks the serving base is
`{site}/wp-json/exelearning/v1/preview/{id}/…`, so `content/img/a.png` resolves
correctly. With **plain** permalinks (`?rest_route=…`) relative URLs break.
The http preview transport therefore **requires pretty permalinks**; the plugin
does not rewrite subresource URLs or inject a `<base>` tag (an explicit
decision — no rewriting keeps the served bytes byte-identical to what the
client generated and hashed).

## Required response headers (on EVERY serving response, incl. 404)

| Header | Value |
|--------|-------|
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `no-referrer` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Access-Control-Allow-Origin` | `*` (**never** with credentials) |
| `Content-Type` | the real MIME of the served file |

`Cache-Control` is **tiered by layer** (not `no-store` uniformly):

| Response | Cache-Control |
|---|---|
| Generated document (layer 3) | `no-store` |
| Session asset (layer 2) | `no-cache` (+ `ETag`, `304` on `If-None-Match`) |
| Fixed resource (layer 1) | `private, max-age=31536000` |
| 404 / errors | `no-store` |

## Sandbox-first CSP (scriptable document types only)

On every **scriptable** document type — `text/html`, `image/svg+xml`,
`application/xml`, `application/xhtml+xml` — from **any** layer (an author SVG
served from the session *or* the fixed layer), add this CSP **verbatim**
(byte-identical to eXe core; `self::SANDBOX_CSP`):

```
sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';
```

The leading `sandbox` directive keeps the document opaque even when opened as a
top-level URL (new tab / direct navigation), not only inside the editor iframe.
An SVG served without it would run its inline `<script>` in the WordPress origin
when opened top-level — `nosniff` does not help, SVG is already a scriptable
document type.

## The fixed-resource manifest

`ExeLearning_Preview_Fixed_Resources` resolves layer-1 ids through
`bundles/preview-fixed-resources.json` inside the installed static editor
distribution (`ExeLearning_Static_Editor_Installer::get_editor_path()`). Ids
resolve by **exact map lookup** — client input never becomes a path — and the
file is read from `resources[id].path` under the distribution root with
containment checks (rejecting `..`, absolute paths and symlink escapes). A
missing or invalid manifest **disables** the layer (every fixed ref returns a
`422 unknown-fixed-resources`, the client demotes those paths to document
writes); it is never fatal.

## Capability-UUID + idle-TTL model & cleanup

Reference caps (enforced by the store): idle TTL **30 min**, **4** sessions per
user (LRU-evicted), **5000** files and **200 MiB** per session, **128 MiB** per
asset, **2 GiB** global (LRU-evicted). Expired or deleted sessions resolve to
nothing, so the serving route **fails closed** (`404`).

Cleanup runs **two ways**, because WP-Cron is traffic-dependent:

1. **WP-Cron** — a 15-minute recurring sweep (`exelearning_preview_cleanup`),
   scheduled on activation (`ExeLearning_Activator`) and cleared on deactivation
   (`ExeLearning_Deactivator`), removes idle sessions and interrupted staging
   leftovers.
2. **Request-time** — the serving route enforces the idle TTL on every access
   and reclaims an expired session inline before serving it.

## Editor activation

The editor opts into this transport via its embedding config (read by
`RuntimeConfig.fromEnvironment()`):

```js
window.__EXE_EMBEDDING_CONFIG__ = {
    // …existing keys…
    previewTransport: 'http',
    previewBasePath: '<rest_url exelearning/v1>', // → {previewBasePath}/preview/{previewId}/*
};
```

There is **no silent fallback**: if `http` is selected and the endpoint answers
without `protocolVersion: 2`, the editor surfaces an error rather than
downgrading to a same-origin document. Never serve the preview same-origin, and
never via a Service Worker on the WordPress origin — either would defeat the
opaque-origin isolation.

## Conformance

Beyond the byte-identical CSP, this plugin replays the shared contract vectors
(`tests/fixtures/preview-contract/vectors.json`, vendored verbatim from
`test/fixtures/preview-contract/` in eXe core) in
`tests/unit/PreviewContractVectorsTest.php`, so protocol semantics — three-layer
resolution, tiered caching, ETag/Range, the scriptable-SVG hole, traversal
rejection, `409`/`422`, and session deletion — stay aligned with the other
hosts.
