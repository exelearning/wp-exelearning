# Opaque editor preview — WordPress adapter

The embedded editor renders its preview **filtered** by default: sanitised, with
no author JavaScript running. When the author opts in to running their own code,
the editor needs somewhere to put the real project bytes that is **not** the
WordPress page — a browser-enforced **opaque origin** the content cannot reach
out of.

This plugin is that somewhere. The editor POSTs the whole project as one ZIP and
gets back an unguessable capability id; the plugin serves that tree from an
authless REST route under a sandbox CSP. There is no `srcdoc` transport and no
Service Worker fallback for authored content: missing or invalid configuration
**fails closed** and the filtered preview stays.

## The two routes

| | Request | Result |
|---|---|---|
| Management | `POST {REST}/exelearning/v1/preview-session/{attachmentId}` | multipart `snapshot=<zip>`, optional `previewId` → `{previewId}` |
| Management | `DELETE {REST}/exelearning/v1/preview-session/{attachmentId}/{previewId}` | drops the snapshot |
| Serving | `GET {REST}/exelearning/v1/preview/{previewId}/{file}` | the snapshot, authless |

Management runs on the WordPress cookie/REST path: `X-WP-Nonce`, the
`upload_files` capability, and ownership of the snapshot (user + attachment).
Serving is authless and cookieless — the unguessable id plus the idle TTL is the
whole credential, which is what makes the origin opaque.

**Pretty permalinks are required in practice.** The editor builds the preview URL
by resolving `{previewId}/index.html` against `servingBaseUrl`, and under plain
`?rest_route=` permalinks that resolution drops the query string and lands on the
site root. `ExeLearning_Editor::maybe_warn_preview_permalinks()` raises an
administration notice; the configuration itself is still emitted.

## Why one whole snapshot

An earlier revision implemented a layered protocol (contract v2): immutable asset
keys uploaded once, incremental document revisions and a fixed-resource manifest,
all to avoid re-uploading unchanged bytes. The editor stopped speaking it — it
sends one whole snapshot per refresh — so the layered store, its controllers and
the `previewHttp` bootstrap block were removed. This document describes what the
plugin does now.

## Storage

Snapshots live outside the web root, under the system temp directory, so no
direct web-server path can bypass the serving route and its sandbox CSP.

Content sits in its own subtree; a write is staged and swapped in, so a reader
sees the previous snapshot or the new one, never a half-written one.

## What an archive must survive before extraction

`ExeLearning_Preview_Zip_Inspector` vets every entry *before* a byte is written,
because extraction is all or nothing and a limit noticed halfway would leave a
partial tree:

- entry count and total **declared uncompressed** size — a zip bomb inflates past
  the second, not the first;
- path traversal, absolute paths and backslashes;
- reserved names the store owns;
- Unix symlinks, stored as a tiny entry whose contents are a path.

Limits default to 1 GB / 10 000 entries, overridable with the
`EXELEARNING_PREVIEW_MAX_BYTES` / `EXELEARNING_PREVIEW_MAX_FILES` constants or the
matching filters. A non-positive value falls back to the default: the guard
cannot be switched off. Over the limit the grant **fails closed** — the filtered
preview stays and the author sees an error, rather than the isolation boundary
being downgraded.

## Response security policy

Every response carries `X-Content-Type-Options: nosniff`, `Referrer-Policy:
no-referrer` and a restrictive `Permissions-Policy`. Every **scriptable** type —
`text/html`, `image/svg+xml`, XML, XHTML — additionally carries the sandbox-first
CSP, so a capability URL stays opaque even when opened directly. Not just HTML: an
author-supplied SVG runs its inline `<script>` when opened top-level, and
`nosniff` does not help — SVG is already a scriptable type.

Everything is `no-store`: the whole tree is replaced on each refresh, so there is
nothing worth revalidating. (The Moodle adapter tiers this with an ETag and Range
because it serves the same snapshot to a longer-lived page.)

## Lifetime

Snapshots expire after 30 idle minutes. Serving one pushes its clock back, so a
preview in use never expires under the author. Every replace also sweeps, so the
store never depends on cron to bound its size.

## Tests

`PreviewSnapshotStoreTest` covers the store and the archive inspector — ownership,
the whole-tree swap, traversal, symlinks, the size and count guards, the TTL, and
that the limits cannot be disabled.
