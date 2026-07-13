# Secure OPFS sandbox experiment: WordPress baseline analysis

## Scope and evidence

This document records the pre-implementation baseline for the OPFS + Service Worker experiment. It does not claim that OPFS is a security sandbox and does not activate or remove any transport.

| Item | Value |
| --- | --- |
| Experimental branch | `feature/secure-opfs-sandbox` |
| Local `main` SHA | `faebab692e522d111f9c2db36654bb5109029430` |
| Experimental branch initial SHA | `faebab692e522d111f9c2db36654bb5109029430` |
| Reference PR | exelearning/wp-exelearning#56 |
| Reference branch inspected | `origin/feature/secure-iframe-sandbox` |
| Reference SHA | `3697029120bee28cd64ca63b2f2b9be0b5f877ca` |
| Stale local reference branch | `feature/secure-iframe-sandbox` at `30800a8479e45b3238d40fb60dfc0a204cf0d6e7` |

The reference was analyzed with `git log main..reference`, `git diff --stat main...reference`, `git diff --name-status main...reference`, and the complete diff. The remote-tracking branch is used because it contains the PR tip; the similarly named local branch is older.

## Invariant and experiment priority

OPFS determines where bytes are stored. A Service Worker converts those bytes into virtual HTTP responses. The iframe origin and sandbox determine what authored JavaScript can attack. These properties are independent.

The primary target is the static/PWA editor preview, whose generated documents and project asset bytes are already in the browser. The plugin experiment is a secondary adapter. If an OPFS viewer cannot satisfy the security or compatibility gates in WordPress, the opaque HTTP capability transport remains the supported fallback. Because no dedicated viewer origin is available for the current deployment, a same-origin OPFS result can only be classified as `same-origin-trusted`; it cannot replace the opaque boundary for untrusted authored content.

## Current and reference designs

### `main`

WordPress embeds a static editor and currently carries path adaptations for its Service Worker viewer. Published ELPX content is extracted and served by the plugin's existing content proxy. Editor preview, published rendering, authenticated save/load, and media attachment storage are distinct concerns and must remain distinct.

### Reference opaque HTTP preview

PR #56 introduces Preview Serving Contract v2. The editor receives a `previewHttp` configuration with separate management and serving base URLs. Authenticated, nonce-protected management routes create a session, upload immutable assets, atomically publish document revisions, and delete the session. A cookieless capability route serves the active tree. Authored documents run in an iframe without `allow-same-origin` and scriptable responses receive a sandbox CSP.

The private file-backed store defaults outside `wp-content/uploads`, is site-scoped, enforces owner/session/global budgets, retains revisions for in-flight reads, and is reclaimed through request-time expiry and WP-Cron. Fixed build resources are resolved rather than uploaded. The serving controller implements explicit MIME types, `nosniff`, ETag, single-range responses, relative bare-root redirects, and traversal-safe exact-key lookup.

The adapter intentionally requires pretty permalinks because the capability subtree must preserve relative resource navigation. Its released editor artifact predates the HTTP provider, so endpoint tests alone do not prove end-to-end activation.

## Relevant files in the reference branch

### Editor integration and configuration

- `admin/views/editor-bootstrap.php`
- `includes/class-exelearning-editor.php`
- `includes/class-exelearning.php`
- `exelearning.php`
- `blueprint.json`

### HTTP preview management, storage, and serving

- `includes/class-preview-management-controller.php`
- `includes/class-preview-serving-controller.php`
- `includes/class-preview-session-store.php`
- `includes/class-preview-proxy.php`
- `includes/class-preview-fixed-resources.php`
- `includes/class-preview-http-headers.php`
- `includes/class-activator.php`
- `includes/class-deactivator.php`
- `nginx-exelearning-preview.conf`
- `docs/preview-serving-contract.md`

### Published-content isolation and bridges

- `includes/class-content-proxy.php`
- `includes/class-iframe-sandbox.php`
- `public/class-shortcodes.php`
- `assets/js/exe-embed-relay.js`
- `assets/js/exe-embed-shim.js`
- `assets/js/exe-media-host.js`
- `assets/js/exe-media-policy.js`

### Evidence and conformance

- `tests/fixtures/preview-contract/vectors.json`
- `tests/unit/PreviewContractVectorsTest.php`
- `tests/unit/PreviewManagementControllerTest.php`
- `tests/unit/PreviewServingControllerTest.php`
- `tests/unit/PreviewSessionStoreTest.php`
- `tests/unit/PreviewFixedResourcesTest.php`
- `tests/unit/PreviewHttpHeadersTest.php`
- `tests/e2e/editor-preview-http.spec.js`
- `tests/e2e/embed.spec.cjs`
- `tests/js/exe_embed.test.js`
- `tests/js/exe_media_host.test.js`

## Classification of reference changes

| Category | WordPress reference implementation | OPFS experiment disposition |
| --- | --- | --- |
| 1. HTTP editor preview | `previewHttp` bootstrap and REST adapter | Candidate to bypass only for editor preview; keep as fallback |
| 2. Temporary preview storage | Private site-scoped file store | Candidate to replace with browser-local OPFS for preview only |
| 3. Capability serving URL | Cookieless `/preview/{previewId}/{path}` subtree | Not needed for a functional same-origin OPFS preview; still required by opaque HTTP fallback |
| 4. Authenticated session management | REST nonce, capability check, ownership | Preview-only routes may disappear; parent authentication and save/load remain |
| 5. Published-content CSP and headers | Content proxy and iframe response hardening | Must remain; OPFS preview does not replace publication policy |
| 6. Opaque published-content iframe | `IframeSandbox`, omitted `allow-same-origin`, sandbox CSP | Must remain for untrusted content unless equivalent evidence exists |
| 7. SCORM/xAPI bridge | Not the main focus of this WordPress PR | Preserve host-side credentials/tracking where present |
| 8. External-media bridge | Relay, shim, media host, provider policy | Reuse; do not remove without browser evidence |
| 9. Interactive-video bridge | Media relay lifecycle and geometry behavior | Reuse and test independently |
| 10. PDF and downloads | Media host, sandboxed players, Range serving | Reuse policy/tests; OPFS SW must independently prove Range/PDF behavior |
| 11. Fixed resources | Manifest-backed fixed-resource resolver | Reuse the layered model and canonical identifiers |
| 12. Incremental revisions | Immutable assets plus atomic document deltas | Reuse protocol semantics and conformance vectors |
| 13. Security tests | Nonce, ownership, CSP, traversal, iframe and media tests | Reuse as baseline; add OPFS/storage and management-channel attacks |
| 14. Integration tests | HTTP preview and embed Playwright flows | Keep HTTP baseline and add static/PWA-first OPFS A/B tests |
| 15. Shared reusable code | Vectors, MIME/CSP rules, path/range semantics, bridges | Prefer canonical core implementations and vendored conformance data |
| 16. Code potentially removable with OPFS | Preview REST routes, store, WP-Cron cleanup, private-store nginx rule, preview quotas, permalink dependency | Remove only in a separate, measured commit after gates pass; fallback requirement currently prevents removal |
| 17. Code still required with OPFS | Editor bootstrap, authentication, attachment access, save/load, publication proxy, iframe policy, bridges, tests | Preserve and keep security boundaries explicit |

## Reusable components

- The three-layer input model: generated documents, immutable project assets, and build-fixed resources.
- Exact path normalization, MIME classification, ETag, query-string independence, and single-range semantics.
- Atomic revision behavior: stage, verify, activate a small pointer, retain the previous revision.
- Canonical contract vectors and negative cases rather than framework-specific protocol invention.
- External media/PDF relay lifecycle, exact origin checks, and id-only authored messages.
- The split between credentialed management and credential-free serving. Even if management becomes a `MessageChannel`, this is a useful privilege-separation model.
- Tests proving owner separation, failed-write behavior, CSP byte identity, traversal rejection, and cleanup.

## Preview-only candidates to disappear

If and only if the browser-local experiment passes, the editor preview could avoid `class-preview-management-controller.php`, `class-preview-serving-controller.php`, `class-preview-session-store.php`, `class-preview-proxy.php`, preview activation/deactivation hooks, preview-specific WP-Cron cleanup, backend preview quotas, the private-store nginx rule, multipart upload handling, and the pretty-permalink requirement. It would also send zero generated documents and zero project asset bytes to a preview backend.

These are not candidates for immediate deletion because the user-selected deployment has no dedicated viewer origin and explicitly retains HTTP as the plugin fallback.

## Components that remain necessary

- WordPress authentication, REST nonces, capability checks, and attachment authorization outside preview management.
- Existing save/load and ELPX upload/download flows.
- Published-content proxying, CSP, iframe sandboxing, access control, and URL handling.
- SCORM/xAPI or equivalent host-side tracking validation when applicable.
- External-media, interactive-video, fullscreen, resize, and PDF behavior until replacement tests pass.
- The trusted viewer shell and exact parent-origin validation for any OPFS experiment.
- Recovery UX when OPFS is unavailable, evicted, private-mode-limited, or quota-exhausted.

## Risks of substitution

1. A same-origin Service Worker response has a normal WordPress origin unless an effective response sandbox makes it opaque. `OPFS + SW` alone would expose the WordPress origin and is a security regression.
2. A CSP `sandbox` response may make the authored document opaque, but it may also prevent the document from acting as a controlled Service Worker client or loading viewer subresources. This must be tested in Chromium, Firefox, and WebKit; it cannot be inferred.
3. Authored code with the viewer origin may access that origin's OPFS, IndexedDB, CacheStorage, and Service Worker registrations. Random directory names are not isolation when enumeration is possible.
4. A WordPress-scoped worker must not control general administration, REST, media, or public pages. Scope and `Service-Worker-Allowed` behavior are hard gates.
5. Private browsing, storage partitioning, quota eviction, and third-party iframe policy may disable or reduce OPFS/SW persistence.
6. PDF native viewers and large media can behave differently for Service Worker responses; Range, reload, seek, cancellation, and files over 100 MiB require browser tests.
7. Removing the HTTP fallback would trade multi-replica backend complexity for browser protocol, persistence, lifecycle, and compatibility complexity without necessarily reducing total complexity.

## Cross-repository dependencies

- eXeLearning core is the canonical source for provider selection, OPFS protocol version, storage layout, viewer shell, Service Worker, CSP policy, and conformance vectors.
- WordPress must consume one reproducible static-editor artifact and record its core commit and SHA-256; it must not fork the protocol.
- Moodle, Omeka S, Nextcloud, and Procomun must use the same vectors so host deviations are explicit.
- The security paper must distinguish byte materialization from JavaScript execution origin and report same-origin OPFS as compatibility mode, not opaque isolation.
- Published-content integration remains host-specific because initial OPFS is empty and authorized package delivery still crosses the network.

## Initial impact estimate

The reference PR changes 75 files, with 16,767 insertions and 6,248 deletions including generated translations and lockfile churn. Excluding `languages/` and `package-lock.json`, it changes 52 files with approximately 9,136 insertions and 124 deletions. It adds six preview backend classes, at least seven preview-focused PHPUnit files, and a dedicated editor-preview E2E flow.

| Area | Opaque HTTP reference | OPFS experiment target | Expected difference |
| --- | ---: | ---: | ---: |
| Preview backend routes | 5 operations | 0 for browser-local preview | -5 |
| Preview backend classes | 6 | 0 preview-session classes | -6 |
| Backend preview store | 1 file-backed store | 0 | -1 |
| Cleanup mechanisms | WP-Cron plus request expiry | Browser TTL/LRU/recovery | Backend -2; browser +1 subsystem |
| Browser transports | HTTP provider in core | OPFS/SW provider plus HTTP fallback | +1 while A/B is retained |
| Protocol operations | Create/assets/revision/delete/serve | MessageChannel session/batches/commit/status/clear | More browser states, fewer HTTP operations |
| Operational requirements | Private disk, TTL, quotas, cron, pretty permalinks | SW/OPFS support, scope, quota, persistence | Shift, not yet proven reduction |
| Security boundary | Opaque sandbox + capability response | Same-origin normal origin unless sandbox response works | Potential regression; hard gate |

## Hypotheses to prove

1. A static/PWA editor can generate documents, write them and project assets to OPFS, and navigate a multipage tree through normal Service Worker URLs without backend preview uploads.
2. The tree survives worker termination because all authoritative state is persisted, not held in a global `Map`.
3. Atomic pointer activation yields revision N or N+1, never mixed files.
4. Unchanged immutable assets are neither rewritten nor retransferred.
5. `Content-Security-Policy: sandbox allow-scripts` either preserves controlled navigation/subresource loading in every target engine or provides reproducible evidence that it does not.
6. Authored content cannot access the WordPress parent DOM, cookies, storage, request nonce, or management channel.
7. The worker's scope cannot cover unrelated WordPress paths and authored documents cannot replace or unregister the trusted worker.
8. PDF, audio, video, attachments, ESM, XML, SVG, fonts, and single-range reads behave correctly.
9. Failure modes (unsupported API, eviction, private mode, quota, interrupted batches) recover explicitly to the opaque HTTP transport rather than silently weakening security.
10. Total implementation and operational complexity is lower after counting the trusted shell, storage abstraction, protocol states, browser tests, and retained fallback.

## Baseline measurements and required experiment outputs

The figures above are source-diff measurements, not performance benchmark results. Core has now supplied functional and security evidence for the transport profiles; WordPress-specific runtime and performance cells remain `NOT EXECUTED`. A future compatible WordPress experiment would record JSON, CSV, and Markdown results for five cold and ten warm runs where practical: first render, text update, image replacement, page navigation, worker restart recovery, bytes uploaded to preview endpoints, OPFS bytes written, request count, memory estimate, cleanup time, and package mount cost for published content.

Network interception must fail the editor-preview test if generated HTML/CSS/JavaScript or project images, PDF, audio, video, or attachments are uploaded to WordPress preview routes. Reading an asset that the editor does not yet possess is measured separately from re-uploading it to a preview server.

## Empirical core results applied to WordPress

The canonical core experiment produced the following results. `PASS` means the functional behavior under test worked; it does not imply that the profile passed the separate host-isolation requirement.

| Profile / engine | Multipage OPFS/SW | Host isolation | Result for WordPress |
| --- | --- | --- | --- |
| Same-origin trusted / Chromium | `PASS` | `FAIL` | Functional only; unsafe for untrusted authored content on the WordPress origin |
| Same-origin trusted / Firefox | `PASS` | `FAIL` | Functional only; unsafe for untrusted authored content on the WordPress origin |
| Opaque CSP / Chromium and Firefox | `FAIL` | Opaque boundary requested | Multipage OPFS-backed navigation/subresources are incompatible with the required opaque profile |
| WebKit | `NOT SUPPORTED` | `NOT SUPPORTED` | Cannot satisfy the cross-engine acceptance criterion |
| Dedicated viewer origin | `NOT EXECUTED` | `NOT EXECUTED` | Not available in the target deployment and therefore not a deployable mitigation |

The experiment confirms the distinction in this document's invariant: same-origin OPFS successfully stores and serves bytes, but it does not isolate authored JavaScript from WordPress. Applying the opaque CSP needed to restore that boundary breaks the multipage OPFS design. The only potentially viable normal-origin security profile, a dedicated sacrificial origin, is unavailable and untested here.

## Host decision and retained code

| Question | Decision | Code or boundary retained |
| --- | --- | --- |
| Add `opfsViewer` configuration to the WordPress bootstrap | No | Keep `previewHttp` configuration and its fail-closed checks |
| Add OPFS management or serving endpoints | No | Do not add insecure same-origin surfaces; retain the existing HTTP management and capability routes from the reference design |
| Replace private preview storage | No | Retain the private site-scoped preview session store, quotas, atomic revisions, expiry, and WP-Cron cleanup |
| Remove capability URLs or pretty-permalink validation | No | Retain cookieless capability serving and the permalink requirement while HTTP preview is used |
| Relax the iframe sandbox with `allow-same-origin` | No | Retain the opaque iframe and sandbox-first CSP for authored content |
| Remove publication proxy or headers | No | Retain `class-content-proxy.php`, publication access control, MIME hardening, and CSP |
| Remove media, PDF, or interactive bridges | No | Retain relay, shim, media host, media policy, Range behavior, fullscreen, geometry, and lifecycle tests |
| Use OPFS in the static/PWA editor | Yes, conditionally | Core may use it only as `same-origin-trusted`, outside the plugin's untrusted host boundary |
| Use OPFS for published WordPress content | No | Retain the existing authenticated/publication delivery path and opaque rendering baseline |

The candidate-removal list above is therefore counterfactual for WordPress. No preview endpoint, controller, private-store component, cleanup mechanism, security header, sandbox rule, or bridge should be removed on the evidence currently available.

## Baseline decision

The plugin decision is **NO-GO** for replacing the opaque HTTP preview with OPFS. This repository will not implement OPFS endpoints or inject same-origin `opfsViewer` configuration because that would knowingly select the profile whose host-isolation test failed.

- static/PWA preview: conditionally viable in trusted-content mode on Chromium and Firefox;
- WordPress untrusted preview: retain the opaque HTTP capability transport;
- same-origin OPFS inside WordPress: rejected for untrusted content because host isolation failed;
- opaque CSP OPFS: rejected because multipage serving failed;
- dedicated-origin OPFS: unavailable and `NOT EXECUTED`;
- published content: retain the current path; no OPFS substitution.
