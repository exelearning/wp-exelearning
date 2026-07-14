# Opaque editor-preview snapshot contract

The WordPress integration keeps the trusted eXeLearning editor on its normal
transport. Preview generation uploads one complete ZIP snapshot and loads the
returned capability URL in a sandboxed iframe without `allow-same-origin`.
Official eXeLearning JavaScript and author-provided active content can run in
that opaque document, but cannot access WordPress cookies, Web Storage, admin
JavaScript objects, REST nonces, or editor state.

## REST routes

Authenticated management routes:

```text
POST   /exelearning/v1/preview-session/{attachmentId}
DELETE /exelearning/v1/preview-session/{attachmentId}/{previewId}
```

WordPress cookie authentication validates the `X-WP-Nonce` header. Permission
callbacks also require `upload_files` and `edit_post` for the attachment. The
POST body contains multipart `snapshot` and an optional `previewId` when
replacing an existing capability.

The serving route is intentionally public and never uses login state:

```text
GET /exelearning/v1/preview/{previewId}/{path}
```

The UUIDv4 is a bearer capability. The management response includes the exact
`previewUrl`, so query-based WordPress REST installations work as well as pretty
permalinks. An optional same-origin delete URL template gives the core client an
exact cleanup URL for the same reason.

## Storage and hardening

Snapshots live below the PHP temporary directory, outside WordPress uploads.
They expire after 30 minutes of inactivity and are scoped in private metadata to
the creating user and attachment. Archives must contain `index.html`, are
limited to 5,000 files and 100 MiB uncompressed, reject traversal and symbolic
links, and are staged before an atomic directory rename.

Capability responses include `nosniff`, `no-referrer`, `no-store`, a restrictive
Permissions Policy, and fixed extension-to-MIME validation. HTML, SVG, XML, and
XHTML receive a sandbox CSP. Unknown extensions use
`application/octet-stream`.

There are no fixed/generated layers, revision conflicts, delta manifests,
content-addressed blob uploads, external-media overlays, or Service Worker
fallback. The core preview iframe uses:

```text
sandbox="allow-scripts allow-forms allow-popups allow-downloads allow-presentation"
```

`allow-same-origin` is absent. Existing editor bridge messages remain restricted
to the expected iframe `Window`, exact editor origin, and recognized payloads;
the opaque preview is not part of that protocol.
