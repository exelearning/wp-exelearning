---
id: SDD-0001
title: "Stale content URL redirects to the latest ELPX extraction"
status: Accepted
date: 2026-07-10
related:
  issues:
    - https://github.com/exelearning/exelearning/issues/2150
  prs: []
  adrs:
    - ADR-0001
  sdds: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-fable-5"
---

# SDD-0001: Stale content URL redirects to the latest ELPX extraction

## Status

Accepted

## Summary

Extracted eXeLearning content is served through hash-based REST URLs
(`/wp-json/exelearning/v1/content/{hash}/{file}`). Saving an edited `.elpx`
attachment generates a new extraction hash and deletes the previous extraction
directory, so every previously shared or embedded URL becomes a permanent
`file_not_found` dead link.

This design records each retired extraction hash as attachment post meta and
teaches the content proxy to answer requests for a known obsolete hash with a
temporary (`302 Found`) redirect to the equivalent file path under the
attachment's **current** extraction hash, preserving the requested relative
path and query parameters. Unknown hashes, malformed data, traversal attempts
and deleted attachments keep failing exactly as they do today.

## Context

The plugin stores `.elpx` files as WordPress attachments and extracts them to
`wp-content/uploads/exelearning/{hash}/`
(`includes/class-elp-reprocessor.php` @ `50f0ed5`). The extraction hash is
**random per extraction** — `sha1( $file_path . microtime( true ) . wp_rand() )`
(`includes/class-elp-reprocessor.php:304` @ `50f0ed5`) — and is stored in the
`_exelearning_extracted` attachment post meta. Historical extractions (before
the unique-hash change) used a content-derived sha1, so two attachments with
identical content **may** share one hash in legacy data.

The content proxy (`includes/class-content-proxy.php` @ `50f0ed5`) serves
`GET exelearning/v1/content/(?P<hash>[a-f0-9]{40})(?:/(?P<file>.*))?`
(route registered in `includes/class-exelearning-rest-api.php:44-66`) with a
public `permission_callback` and only ever resolves the requested hash
directory on disk.

## Problem statement

Content authors publish or embed proxy URLs (LMS embeds, links shared with
learners). After the author edits the package in the embedded editor and
saves, all those URLs break with `file_not_found`. A URL that once identified
an eXeLearning resource must keep working after the resource is edited.

## Goals

- Preserve previously published content URLs after an ELPX save: a request for
  a retired hash returns a temporary redirect instead of `file_not_found`.
- Redirect an obsolete hash to the attachment's **latest** extraction, never to
  an intermediate hash (no redirect chains).
- Preserve the requested relative file path in the redirect target.
- Preserve the request's query parameters (e.g. `?exe-teacher=1`).
- Use `302 Found` with `Cache-Control: no-cache, must-revalidate` so the
  mutable destination is never cached permanently.
- Preserve all existing proxy validation, error codes and security headers for
  every non-redirect outcome.
- Never create aliases during failed or partial saves; never delete the old
  extraction when alias persistence fails (fail *open* for availability, not
  for security).
- Handle permanent attachment deletion safely: aliases disappear with the
  attachment and stale requests return the normal safe 404.
- Never redirect a hash that is still the current extraction of any attachment
  (legacy shared hashes).

## Non-goals

- Replacing the existing hash-based URL format or introducing a new public
  attachment-ID content route.
- Retaining historical extraction directories (disk usage stays bounded to one
  extraction per attachment).
- Redirecting arbitrary or unknown hashes: only hashes recorded during a
  successful save of a known attachment redirect.
- Changing the `delete_attachment` extraction cleanup
  (`includes/class-elp-upload-handler.php:234` @ `50f0ed5`) or any other
  media-library / editor behavior.
- Solving the legacy shared-hash ambiguity beyond safe refusal (see
  "Shared-hash ambiguity").

## Current behavior

Save flow (`ExeLearning_REST_API::save_elp_file_locked()`,
`includes/class-exelearning-rest-api.php:375-495` @ `50f0ed5`), executed under
a per-attachment lock (`acquire_save_lock()`, lines 524-537):

1. Read the old hash from `_exelearning_extracted` (line 377).
2. Fire `exelearning_before_elpx_save` (line 392).
3. Route the upload through `wp_handle_upload()` (line 412).
4. Validate + extract the new project to a fresh directory
   (`ExeLearning_Reprocessor::extract_to_new_dir()`, line 427). Any failure
   returns before anything else is touched.
5. Copy over the original `.elpx` and verify the copied size (lines 437-457);
   failure deletes the *new* extraction and returns.
6. Commit metadata — `apply_elp_metadata()` sets `_exelearning_extracted` to
   the new hash (line 463).
7. **Delete the old extraction directory** when `$old_hash` differs from the
   new hash (lines 466-468) — this is what kills published URLs.
8. Update the attachment modified dates (line 471).
9. Fire `exelearning_after_elpx_save` (line 492) and return (line 494).

`ExeLearning_Reprocessor::reprocess()` (lines 52-109 @ `50f0ed5`) performs the
same commit-then-delete sequence for the reprocess flow, so it creates the
same dead links.

The proxy (`ExeLearning_Content_Proxy::serve_content()`,
`includes/class-content-proxy.php:93-112` @ `50f0ed5`) validates the hash
format, sanitizes the path (traversal-safe), then returns
`file_not_found` (404) when the file does not exist under the requested hash.

## Proposed design

Three cooperating pieces, all under `includes/`:

### 1. `ExeLearning_Content_Hash_Aliases` (new class,
`includes/class-content-hash-aliases.php`)

A small repository for obsolete-hash → attachment relationships stored as
**multi-value attachment post meta** under the key
`_exelearning_obsolete_hash` (one meta row per retired hash).

- `register( $attachment_id, $old_hash ): bool` — validates the attachment
  (exists, `post_type === 'attachment'`) and the hash format
  (`/^[a-f0-9]{40}$/i`); refuses self-aliases (hash equals the attachment's
  current `_exelearning_extracted`); refuses hashes that are the **current**
  hash of any attachment; refuses hashes already registered as an alias of
  a *different* attachment (ambiguity guard); is idempotent for re-registration
  on the same attachment; persists with `add_post_meta()` and **verifies** the
  row by reading it back before returning `true`.
- `resolve( $hash ): int` — returns the owning attachment ID only when the
  hash (a) is format-valid, (b) is **not** the current hash of any attachment
  (current content always wins), and (c) is registered as an alias of exactly
  one attachment. Returns `0` otherwise.
- `is_current_hash( $hash ): bool` — whether any attachment's
  `_exelearning_extracted` equals the hash.

Lookups use bounded meta queries on the `attachment` post type
(`fields => ids`, `posts_per_page => 2`, `no_found_rows => true`). They run
**only** on the save path and on the proxy's `file_not_found` fallback path —
never on a successful content request.

### 2. Retirement primitive in `ExeLearning_Reprocessor`

`retire_extraction( $attachment_id, $old_hash, $new_hash ): void`, called
after metadata commit by both the REST save and `reprocess()` (replacing their
duplicated "cleanup old hash" blocks):

1. Return immediately when `$old_hash` is empty or equals `$new_hash`
   (unchanged hash ⇒ no alias, no deletion, no self-reference).
2. Return **without deleting and without registering** when the old hash is
   still the current hash of any attachment (legacy shared hash — the other
   attachment keeps serving it).
3. `register()` the alias. If registration fails or is refused, **keep the old
   extraction directory** — the old URL keeps serving the previous content
   rather than becoming a dead link.
4. Only after the alias is persisted and verified, delete the old extraction
   directory (`cleanup_by_hash()`).

### 3. Redirect fallback in `ExeLearning_Content_Proxy`

`serve_content()` keeps its exact current ordering — hash format validation,
then path sanitation/containment — and only when the result is the
`file_not_found` error (never `invalid_hash`, `invalid_path` or
`access_denied`) consults the alias repository:

1. Re-sanitize the requested path (defense in depth).
2. `resolve()` the hash to an attachment; bail to the original 404 on `0`.
3. Read the attachment's `_exelearning_extracted`; bail unless it is a valid
   40-hex hash **different** from the requested hash (loop guard).
4. Validate the destination with the existing `validate_file_path()` against
   the current hash — the target file must exist and be contained in the
   current extraction directory; bail to the original 404 otherwise (no
   redirects to known-dead targets).
5. Build the `Location` from `ExeLearning_Content_Proxy::get_proxy_url()`
   (same generator as every other proxy URL, including the optional isolated
   content origin) and append the request's query parameters encoded with
   `http_build_query( …, PHP_QUERY_RFC3986 )`, dropping the plain-permalink
   routing argument `rest_route`.
6. Return a `WP_REST_Response` with status `302`, the `Location` header, and
   `Cache-Control: no-cache, must-revalidate`.

Because step 4 guarantees the destination file exists under the *current*
hash, the redirect target is always served directly — a chain
`hash-a → hash-b → hash-c` is impossible by construction, and repeated saves
leave every retired hash resolving through the attachment to its single
current hash.

## Affected areas

- [x] PHP plugin classes (`includes/`)
- [x] REST endpoints (`includes/class-exelearning-rest-api.php`)
- [x] Content proxy (`includes/class-content-proxy.php`)
- [x] ELPX upload/extraction (`includes/class-elp-reprocessor.php`)

## Data model or storage impact

New attachment post meta key, `_exelearning_obsolete_hash`:

- **Multi-value**: one row per retired hash, added only on a successful save
  that changed the hash.
- **Not autoloaded**: post meta is loaded with the post's meta cache on
  demand, never on every page load (unlike autoloaded options).
- **Lifecycle**: rows are removed automatically when the attachment is
  permanently deleted (`wp_delete_attachment()` deletes all post meta —
  WordPress core behavior), so no orphaned alias can outlive its attachment.
- **Growth**: bounded by the number of successful saves per attachment; each
  row stores a 40-byte hash. No global map, no options-table growth.

No change to the `wp-content/uploads/exelearning/{hash}/` layout. Existing
extractions and metadata keep working unchanged; attachments saved before
this change simply have no aliases (their already-dead URLs stay 404, which is
the current behavior).

### Storage alternatives evaluated

| Criterion | **1. Post meta per obsolete hash (chosen)** | 2. Non-autoloaded option per hash | 3. Old→new hash chains | 4. Keep all old directories | 5. Custom table |
|---|---|---|---|---|---|
| Lookup cost | One bounded meta query, only on the 404 path | O(1) by option name | O(chain length), multiple reads | None (no redirect at all) | One indexed query |
| Cleanup on attachment deletion | **Automatic** (core deletes post meta) | Manual reverse lookup over options | Manual chain walking | Manual directory sweep | Manual `DELETE` + uninstall handling |
| Multiple sequential saves | Each retired hash resolves through the attachment to the single current hash | Same, if the option stores the attachment ID | Produces `a → b → c` chains | N/A | Same as meta |
| Redirect-loop prevention | Structural: resolution reads current meta, self/current refusals | Same, with extra bookkeeping | Requires cycle detection | N/A | Same as meta |
| WordPress conventions | Matches the plugin's "everything in attachment meta" model (`CLAUDE.md`, Data Storage) | Options are for site-wide state, not per-attachment data | No precedent | N/A | Overkill for a 40-byte relation; plugin-review friction |
| DB growth | `wp_postmeta`, bounded per attachment | `wp_options` growth; risk of accidental autoload on rewrite | Same as options | Unbounded **disk** growth | New schema to migrate/maintain |
| Autoload impact | None | None if flags never regress; each row must stay `autoload=no` | None | None | None |
| Shared extraction hashes | Refusals implementable in `register()`/`resolve()` with the same meta queries | Needs a value format carrying the attachment + collision policy | Ambiguity unresolvable (hash → hash loses the attachment) | N/A | Same as meta |
| Testability | Trivial with `WP_UnitTestCase` attachment factories | Requires option-key discipline in tests | Chain fixtures awkward | N/A | Requires schema setup in tests |

A single unbounded autoloaded option holding every alias was ruled out up
front (memory on every request, race-prone read-modify-write). Option 2 is the
closest runner-up but loses on deletion cleanup and uninstall complexity;
option 3 is rejected because chains recreate the loop/chain problems this
design must prevent; option 4 trades a bug for unbounded disk usage; option 5
adds schema maintenance the data volume does not justify.

The preferred property from the issue — obsolete hashes resolve **through the
attachment identity** to its current `_exelearning_extracted` value — is
exactly what post meta provides, and it is correct for the current data model
because `_exelearning_extracted` is the single source of truth for an
attachment's live extraction (`includes/class-elp-reprocessor.php:347`).

## Shared-hash ambiguity

The public URL carries only an extraction hash, not an attachment ID. Newly
generated hashes are unique per extraction
(`includes/class-elp-reprocessor.php:301-305` @ `50f0ed5`), but legacy
extractions used content-derived hashes, so two attachments **may** both point
at one hash/directory.

Defined behavior when attachment A (sharing hash `H` with attachment B) is
saved:

- `retire_extraction()` detects that `H` is still the current hash of B and
  **neither deletes the `H` directory nor registers an alias**. B's URLs keep
  serving; A's old URLs under `H` keep serving B-identical content from the
  still-present directory. (Today's code would delete `H` and break B — the
  new reference check also fixes that latent data-loss bug for saves.)
- `resolve()` refuses any hash that is the current hash of *any* attachment,
  so alias data can never hijack a live hash.
- `resolve()` refuses a hash registered as an alias of more than one
  attachment (possible only through manual meta manipulation), returning the
  safe 404 rather than silently choosing an attachment.
- `register()` refuses to double-register a hash owned by another attachment.

**Documented limitation**: once A and B genuinely shared `H` and both are
later edited, requests for `H` cannot be attributed to either attachment
without changing the public URL contract (embedding an attachment ID). Such
requests return the current safe 404. The `delete_attachment` directory
cleanup (`includes/class-elp-upload-handler.php:234`) can still remove a
legacy shared directory when one owner is deleted; changing that flow is out
of scope here and would belong to a follow-up.

## Save ordering

Precise ordering inside `save_elp_file_locked()` (unchanged steps cited from
`includes/class-exelearning-rest-api.php` @ `50f0ed5`):

1. Validate and extract the new ELPX to a fresh directory (line 427).
2. Replace the source `.elpx`, verify the copied size (lines 437-457).
3. Commit `_exelearning_extracted` to the new hash via `apply_elp_metadata()`
   (line 463).
4. **New:** `retire_extraction( $attachment_id, $old_hash, $new_hash )`:
   1. Skip everything when the old hash is empty or unchanged.
   2. Skip deletion *and* aliasing when the old hash is still current for any
      attachment (shared hash).
   3. Persist the obsolete-hash relationship (`register()`), which verifies
      the stored row by reading it back.
   4. Delete the old extraction **only after** the verified registration;
      on registration failure the directory is retained, so the old URL keeps
      serving the previous content instead of recreating the dead-link bug.
5. Update the attachment modified dates (line 471).
6. Fire `exelearning_after_elpx_save` (line 492).
7. Return the REST response (line 494).

Failure in steps 1–2 returns before the commit: no metadata change, no alias,
no deletion — identical to today's transactional behavior. The per-attachment
save lock (lines 348-361) already serializes concurrent saves around the whole
sequence and is not modified.

`ExeLearning_Reprocessor::reprocess()` adopts the same `retire_extraction()`
call in place of its duplicated cleanup block, keeping every entry point
consistent (the class's stated purpose, lines 5-11).

## WordPress hooks/filters impact

None. No new hooks; `exelearning_before_elpx_save` /
`exelearning_after_elpx_save` keep their exact timing and signatures
(`docs/HOOKS.md` unchanged).

## REST API impact

No new routes. The existing public
`GET exelearning/v1/content/{hash}/{file}` endpoint gains one new outcome:

- **Redirect** — `302 Found` with `Location` and
  `Cache-Control: no-cache, must-revalidate`, only when the requested hash is
  a verified obsolete alias, the owning attachment exists, its current hash is
  valid and different, and the equivalent file exists inside the current
  extraction directory.
- **`Location` construction** — `ExeLearning_Content_Proxy::get_proxy_url()`
  (the same generator used for every proxy URL, including the
  `exelearning_content_origin` isolated-origin rewrite,
  `includes/class-content-proxy.php:597-641`) plus the request's remaining
  query parameters encoded with `http_build_query(…, PHP_QUERY_RFC3986)`.
  No component of the URL originates from user input except the validated
  40-hex hash, the sanitized relative path, and RFC3986-encoded query values —
  the redirect can never leave the site (or configured content) origin.
- **Query-string preservation** — all request query parameters are carried
  over except `rest_route` (the plain-permalink routing argument, which the
  freshly generated target URL already carries when needed); works with both
  pretty and plain permalinks because `rest_url()` handles both.
- **Unchanged outcomes** — invalid hash (`invalid_hash`), traversal
  (`invalid_path` / `access_denied`), unknown hash, malformed or ambiguous
  alias data, deleted attachment, missing destination file: all return exactly
  the current safe errors. Successful direct serving is untouched — the
  fallback runs only after `file_not_found`.
- **Loop prevention** — a redirect is only emitted toward a hash whose file is
  verified present, and never toward the requested hash itself; the
  destination therefore serves directly (no chains, no loops).

## Shortcode/block impact

Not applicable — shortcode and block embed by attachment ID and always render
the current hash.

## Security considerations

- **Ordering**: the redirect decision happens strictly *after* the same hash
  and path validation as a normal request, and reuses
  `validate_file_path()` (realpath containment + traversal-safe
  `sanitize_path()`) against the destination hash. Traversal attempts keep
  returning `invalid_path`/`access_denied` and are never converted into
  redirects.
- **Validated inputs**: old hash format (route regex + `validate_hash()`),
  current hash format (explicit 40-hex re-check on the stored meta),
  attachment existence and post type (in `resolve()`), alias↔attachment
  relationship (meta row read-back), requested relative path
  (`sanitize_path()`), destination existence and containment
  (`validate_file_path()`), and same-origin URL construction
  (`get_proxy_url()` + RFC3986-encoded query args only).
- **No open redirect**: no user-controlled origin, host, or scheme ever enters
  the `Location` value; query values are percent-encoded, and WordPress's
  `WP_REST_Server` header handling plus PHP's `header()` reject CR/LF
  injection as a second layer.
- **No permission change**: the endpoint stays public exactly as today; the
  redirect discloses only the current hash of content that the same public
  endpoint already serves.
- **No new SQL on the hot path**: successful content requests execute zero
  additional queries; alias lookups run only after a request already failed
  with `file_not_found`.
- **Save transaction**: the existing lock and validate-extract-commit order
  are untouched; alias persistence failures degrade to "keep the old
  directory", never to weakened validation.

## Privacy considerations

Not applicable — the meta stores only random extraction hashes the plugin
itself generated; no personal data. `uninstall.php` currently removes no
plugin data (repo `uninstall.php` @ `50f0ed5`), so no uninstall change is
introduced here.

## Accessibility considerations

Not applicable — HTTP-level redirect with no UI surface; browsers and
assistive technologies follow `302` transparently.

## Internationalization considerations

No new user-facing strings: every failure path reuses the existing translated
`WP_Error` messages, and the redirect response has no body. `.pot`/`.po`
files stay untouched (`make check-untranslated` must remain green).

## Backward compatibility

- Existing valid URLs serve exactly as before (fallback only runs on
  `file_not_found`).
- URLs already dead before this change stay 404 (no retroactive alias data
  exists) — no migration is attempted.
- Stored meta, shortcodes, blocks, hooks: unchanged.
- Legacy shared-hash extractions gain a strictly safer behavior: a save no
  longer deletes a directory another attachment still references.

## Migration/rollout

Single PR, no data migration, no feature flag. Aliases accumulate organically
from the first post-deploy save. Rollback (reverting the PR) leaves only inert
`_exelearning_obsolete_hash` meta rows behind; the proxy simply stops
consulting them and behavior returns to today's.

## Testing strategy

TDD: the tests below are committed and demonstrably failing before any
production code changes.

- **`tests/unit/ContentHashAliasesTest.php`** (new) — direct repository
  coverage: registration + verification, resolution, duplicate registration
  (same and different attachments), multiple aliases per attachment, invalid
  hash/attachment values, self-alias refusal, current-hash refusal, ambiguity
  refusal, automatic cleanup on permanent attachment deletion, and that no
  autoloaded option is created.
- **`tests/unit/StaleContentRedirectTest.php`** (new) — proxy behavior through
  `serve_content()` with `WP_REST_Request` fixtures and WordPress attachment
  factories: basic `index.html` redirect (status, `Location`, cache header),
  nested paths, query-parameter preservation (`?exe-teacher=1`), multiple
  sequential saves redirecting every retired hash directly to the latest one,
  unknown hash → `file_not_found`, invalid hash → `invalid_hash`, raw and
  encoded traversal → `invalid_path` (never a redirect, even with an alias
  registered), missing destination file → safe 404, malformed stored aliases
  (bad attachment, bad current hash, self-reference, non-attachment post) → no
  redirect, deleted attachment → 404 with meta removed, and shared-hash
  refusals.
- **`tests/unit/ReprocessorTest.php`** (extended) — `retire_extraction()`
  ordering: alias persisted before deletion, unchanged hash creates neither
  alias nor deletion, shared current hash blocks deletion and aliasing,
  failed reprocess leaves the old extraction, meta and alias state untouched,
  sequential `reprocess()` runs leave all retired hashes redirecting to the
  latest extraction.
- **`tests/unit/RestApiTest.php`** (extended) — failed REST saves create no
  alias and keep the old extraction directory.
- Known harness limitation (pre-existing): successful file serving calls
  `serve_file()` + `exit`
  (`includes/class-content-proxy.php:110-111` @ `50f0ed5`) and therefore
  cannot be exercised inside PHPUnit — current-content precedence is enforced
  structurally (the fallback only runs on the `file_not_found` error) and
  covered by the `resolve()`-refuses-current-hash unit tests.
- Quality gates: `composer phpcbf`, `composer phpcs`, `make test`,
  `make phpmd`, `make check-untranslated`, `git diff --check`.

## Acceptance criteria

- [ ] Requesting a retired hash returns `302 Found` to the same relative path
      under the attachment's current hash, with query parameters preserved and
      `Cache-Control: no-cache, must-revalidate`.
- [ ] After N sequential saves every retired hash redirects directly to the
      latest hash (no chains).
- [ ] Invalid hashes, unknown hashes, traversal attempts, malformed aliases,
      deleted attachments and missing destination files return the same safe
      errors as before, never a redirect.
- [ ] A failed save creates no alias and deletes nothing.
- [ ] A hash still current for another attachment is neither deleted nor
      redirected when a sharing attachment is saved.
- [ ] Permanently deleting an attachment removes its aliases and stops its
      redirects without PHP warnings.
- [ ] All quality gates pass.

## Open questions

None blocking. The `delete_attachment` shared-directory cleanup (see
"Shared-hash ambiguity") is noted as possible follow-up work.

## ADRs required or referenced

| Decision | ADR | Status |
|----------|-----|--------|
| Obsolete extraction hashes are stored as attachment post meta and resolved through the attachment's current extraction; the public content endpoint answers verified obsolete hashes with a temporary same-origin redirect | ADR-0001 | Accepted |

An ADR is required by repository policy: this change alters the persistent
storage layout (a new attachment meta key) and the public REST contract of the
content proxy — both listed as durable-decision territory in
`AGENTS.md` ("Architecture decisions and design documents") and
`docs/architecture/adr/README.md`.

## Evidence

- Save transaction and cleanup: `includes/class-exelearning-rest-api.php:375-495` @ `50f0ed5`.
- Random unique extraction hash: `includes/class-elp-reprocessor.php:301-305` @ `50f0ed5`.
- Reprocess flow with the same cleanup: `includes/class-elp-reprocessor.php:52-109` @ `50f0ed5`.
- Proxy validation and 404: `includes/class-content-proxy.php:93-193` @ `50f0ed5`.
- Route registration and public permission callback: `includes/class-exelearning-rest-api.php:44-66` @ `50f0ed5`.
- Deletion hook removing the current extraction: `includes/class-elp-upload-handler.php:55,234-245` @ `50f0ed5`.
- Attachment meta lifecycle on deletion: WordPress core `wp_delete_attachment()` deletes all post meta (https://developer.wordpress.org/reference/functions/wp_delete_attachment/).
- Isolated content origin URL generation: `includes/class-content-proxy.php:597-658` @ `50f0ed5`.
- Issue: https://github.com/exelearning/exelearning/issues/2150.

## Follow-up tasks

- [ ] Implement per this design (tests first): `ExeLearning_Content_Hash_Aliases`,
      `ExeLearning_Reprocessor::retire_extraction()`, proxy fallback.
- [ ] Optional follow-up (separate issue): reference-check the
      `delete_attachment` directory cleanup for legacy shared hashes.

## References

- https://github.com/exelearning/exelearning/issues/2150
- [ADR-0001](../adr/ADR-0001-obsolete-hash-alias-storage.md)
- `docs/architecture/sdd/README.md`, `docs/architecture/adr/README.md`
- https://developer.wordpress.org/reference/functions/wp_delete_attachment/
- https://developer.wordpress.org/rest-api/
