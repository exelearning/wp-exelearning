# Host-served opaque HTTP preview (serving contract)

This plugin can serve the **editor preview** of untrusted author content
(the live `.elpx` the author is editing) over HTTP in an **opaque origin**,
as an alternative to the core `preview-sw.js` Service Worker transport.

It implements the eXeLearning core canonical contract, which is the single
source of truth for the wire format and the exact headers:

> eXe core: `doc/development/preview-serving-contract.md`

The two documents must not drift. Where this doc and core disagree, core wins,
and the CSP string below must stay **byte-identical** to core.

## Why a separate transport

The wp-admin editor preview normally renders through the core Service Worker.
Some hosts (and the editor when embedded) prefer the host to serve preview
files directly over HTTP so the untrusted author bundle runs in a real opaque
origin — isolated from the WordPress session, cookies, and DOM — without
relying on an SW-controlled scope. This mirrors, for the *editor preview*, the
isolation the plugin already gives *published* content.

## What this host reuses

This is the same security posture as published-content delivery, applied to a
short-lived preview session store:

- **Serving primitive:** modelled on `ExeLearning_Content_Proxy` (cookieless
  streaming, traversal-safe path resolution, hardened headers, `exit` after
  send). See `includes/class-content-proxy.php`.
- **Sandbox tokens:** the CSP `sandbox` directive uses the same tokens as
  `ExeLearning_Iframe_Sandbox::TOKENS_SECURE`
  (`allow-scripts allow-popups allow-forms`). See `includes/class-iframe-sandbox.php`.
- **Reference endpoint:** `includes/class-preview-proxy.php`
  (`ExeLearning_Preview_Proxy`).

## Serving route (authless capability URL)

```
GET {previewBasePath}/preview/{previewId}/*
```

Registered under the `exelearning/v1` REST namespace, so `previewBasePath` is
`rest_url( 'exelearning/v1' )`. The `previewId` is the capability: knowing it is
sufficient to read that session (no WordPress auth on the serving path). It must
match, else respond `404`:

```
^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$
```

## Required response headers (on EVERY serving response, incl. 404)

| Header | Value |
|--------|-------|
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `no-referrer` |
| `Cache-Control` | `no-store` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` |
| `Access-Control-Allow-Origin` | `*` (**never** with credentials) |
| `Content-Type` | the real MIME of the served file |

## Sandbox-first CSP (scriptable document types only)

On every **scriptable** document type — `text/html`, `image/svg+xml`,
`application/xml`, `application/xhtml+xml` — add this CSP **verbatim**
(byte-identical to eXe core):

```
sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';
```

The leading `sandbox` directive keeps the document opaque even when opened as a
top-level URL (new tab / direct navigation), not only inside the editor iframe.

## Capability-UUID + idle-TTL model

- A preview session is a UUID (`previewId`) plus a **content-addressed** file
  store. Every uploaded blob is **re-hashed server-side**; hash mismatches are
  quarantined, never served. Manifest swaps are atomic.
- Caps and lifetime (core defaults): idle TTL **30 min**, **5000** files and
  **200 MiB** per session, **2 GiB** global. Expired or deleted sessions resolve
  to nothing, so the serving path **fails closed** (404).

## Editor activation

The editor opts into this transport instead of the Service Worker via its
embedding config (read by `RuntimeConfig.fromEnvironment()`):

```js
window.__EXE_EMBEDDING_CONFIG__ = {
    // …existing keys…
    previewTransport: 'http',
    previewBasePath: '<rest_url exelearning/v1>', // → {previewBasePath}/preview/{previewId}/*
};
```

**Never** serve the preview same-origin, and never via a Service Worker on the
WordPress origin — that would defeat the opaque-origin isolation.

## Status / follow-up

This document + `includes/class-preview-proxy.php` land the **serving contract**
(route, validation, headers, verbatim CSP). Still follow-up (tracked separately,
with tests in the same PR per `AGENTS.md`):

- the content-addressed session **store** (re-hash, atomic swap, caps, TTL GC);
- the authenticated, owner-scoped **management API**
  (`POST /preview-session`, `POST /:id/manifest`, `POST /:id/blobs`, `DELETE /:id`);
- unit tests (`tests/unit/PreviewProxyTest.php`) asserting the UUID regex, the
  byte-identical CSP, the header set on 200 **and** 404, and `nosniff`/MIME.