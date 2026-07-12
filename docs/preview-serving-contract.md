# HTTP editor preview — WordPress adapter

This plugin implements eXeLearning **Preview Serving Contract v2** for the embedded editor. The canonical wire contract, CSP, scriptable MIME set, bridge files, and conformance vectors live in eXeLearning core. This document records only the WordPress mapping and operational requirements.

The authored preview runs in an iframe without `allow-same-origin`. Scriptable responses also receive the sandbox-first CSP, so a capability URL remains opaque when opened directly.

There is no authored-content `srcdoc` transport and no Service Worker fallback on the WordPress origin. Missing or invalid HTTP configuration fails closed.

## Transport configuration

With pretty permalinks enabled, `admin/views/editor-bootstrap.php` injects:

```jsonc
{
  "previewHttp": {
    "protocolVersion": 2,
    "managementBaseUrl": "{rest_url}/exelearning/v1/preview-session",
    "servingBaseUrl": "{rest_url}/exelearning/v1/preview",
    "managementHeaders": {
      "X-WP-Nonce": "..."
    }
  }
}
```

Management requests use `credentials: "same-origin"`. Serving requests use `credentials: "omit"` and never receive the nonce.

Plain `?rest_route=` permalinks cannot provide a stable capability subtree for relative resources. When pretty permalinks are disabled, the plugin omits `previewHttp`, displays an administration notice, and the editor fails closed.

## Endpoint mapping

| Operation | WordPress route | Trust model |
|---|---|---|
| Create session | `POST {managementBaseUrl}` | authenticated, nonce, capability check |
| Upload assets | `POST {managementBaseUrl}/{previewId}/assets` | authenticated, nonce, owner-scoped |
| Publish revision | `POST {managementBaseUrl}/{previewId}/revisions` | authenticated, nonce, owner-scoped |
| Delete session | `DELETE {managementBaseUrl}/{previewId}` | authenticated, nonce, owner-scoped |
| Serve preview | `GET {servingBaseUrl}/{previewId}/{path}` | authless capability URL |

Management requires `current_user_can('upload_files')`, a valid WordPress REST nonce, and ownership of the preview session. Unknown sessions return `404`; sessions owned by another user return `403`.

The serving route accepts only a server-generated UUID capability and is bounded by idle TTL and quotas.

## Protocol layers

1. **Fixed resources** — official editor resources resolved through `bundles/preview-fixed-resources.json`; never uploaded into a session.
2. **Session assets** — author images, audio, video, PDF, and attachments; immutable per `{assetId}@{hash}` key and uploaded once per session.
3. **Generated documents** — page HTML and generated CSS/JS; only changed files are sent as an atomic revision delta.

The store validates asset keys, paths, declared sizes, actual sizes, referenced assets, fixed-resource IDs, file counts, and byte budgets.

## Private storage

The production proxy creates one store shared by the management and serving controllers at:

```text
{system-temp}/exelearning-preview-{site-hash}
```

The site hash is derived from the WordPress installation path and home URL, preventing unrelated WordPress installations that share the same system temporary directory from sharing sessions or budgets.

The default is intentionally outside `wp-content/uploads`. Materialized HTML, SVG, XML, CSS, and JavaScript must never gain a direct web-server URL that bypasses the serving controller and its sandbox CSP.

A deployment may select another **private** location:

```php
add_filter('exelearning_preview_store_dir', function ($default) {
    return '/private/storage/exelearning-preview';
});
```

The configured path must not be publicly served. `.htaccess`, `index.php`, and the shipped nginx example remain defense in depth for legacy or custom layouts; they are not the primary boundary.

## Atomicity and locking

All mutations serialize through the session store lock. A revision is prepared in a staging directory, renamed into place, and activated by a final pointer swap. A serving request reads the pointer once and therefore observes revision N or N+1, never a mixture.

A failed or short asset write is reported as `write-failed` and is never indexed. A failed document copy, metadata write, revision rename, or pointer write aborts publication and leaves the previous revision active.

## Serving behavior

Resolution order for the active revision:

```text
generated document → session asset reference → fixed-resource reference → 404
```

A bare capability URL redirects with a relative `302` target to `{previewId}/index.html`, preserving the correct base for relative resources.

Range behavior for session assets:

- valid satisfiable single range → `206`;
- valid unsatisfiable single range, including `bytes=-0` → `416`;
- malformed, multi-range, non-`bytes`, or inverted range → ignore and return the full `200` response.

Assets support ETag and `If-None-Match`.

## Response security policy

Every response, including errors, includes the contract hardening headers:

```text
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Access-Control-Allow-Origin: *
```

`Access-Control-Allow-Origin: *` is used only by the cookieless serving route and is never paired with credentials.

HTML, SVG, XML, `text/xml`, and XHTML from every layer receive the byte-identical sandbox-first CSP defined by eXeLearning core. The iframe sandbox omits `allow-same-origin`.

Cache policy is layer-specific:

- generated document: `no-store`;
- session asset: `no-cache`, ETag, optional Range;
- fixed resource: `private, max-age=31536000`;
- error: `no-store`.

## Limits and cleanup

Reference defaults:

- idle TTL: 30 minutes;
- sessions per user: 4;
- files per session: 5000;
- bytes per session: 200 MiB;
- bytes per asset: 128 MiB;
- global store budget: 2 GiB.

Cleanup runs through WP-Cron and request-time expiry checks. An expired or deleted session returns `404`; the core provider recreates the session on the next refresh.

## Activation status

The host adapter is implemented and tested, but the released editor currently bundled by the plugin predates `HttpPreviewProvider`. Production activation requires a core editor release containing:

```text
HttpPreviewProvider
StaticServiceWorkerPreviewProvider
bundles/preview-fixed-resources.json
```

Until that editor is installed, the released client ignores `previewHttp`. Endpoint and bootstrap tests do not by themselves prove browser activation.

The final integration gate is a browser test using one reproducible static-editor artifact from the target core commit, demonstrating create → assets → revision → opaque capability iframe → incremental update → cleanup.

## Conformance and tests

The WordPress harness replays an exact vendored copy of the core vectors. Additional tests cover:

- REST nonce and ownership;
- private default store location;
- pretty-permalink fail-closed behavior;
- asset and revision write failures;
- atomic publication;
- CSP on every scriptable MIME;
- traversal and malformed multipart input;
- Range, ETag, bare-root redirect, expiry, and cleanup.

When this document conflicts with the canonical core contract, the core contract wins and the adapter must be updated.