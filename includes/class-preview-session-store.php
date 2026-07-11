<?php
/**
 * File-backed preview session store (serving contract v2, layers 2 & 3).
 *
 * MIRRORS eXe core: src/services/preview-session-manager.ts. PHP is
 * request-scoped, so an in-memory map does not survive requests — the store is
 * backed by the uploads directory and every request reads it fresh.
 *
 * Layout under `wp_upload_dir()/exelearning-preview/{previewId}/`:
 *
 *   current               Active-revision pointer (integer text). Absent/0 until
 *                         the first revision publishes. The atomic pointer swap.
 *   access                TTL / LRU clock — its MTIME is the last-access time.
 *   meta.json             { ownerUserId, revision, createdAt, documentBytes,
 *                           assetBytes, fileCount } bookkeeping.
 *   assets/{assetKey}     Session-lifetime asset bytes, immutable per assetKey.
 *   revisions/{n}/documents/...   Immutable document snapshot for revision n.
 *   revisions/{n}/refs.json       { revision, assetRefs, fixedRefs } for n.
 *
 * A revision publishes by staging a fresh `revisions/{n+1}` directory (previous
 * documents carried forward, deletes dropped, writes overlaid), renaming it into
 * place, then atomically swapping the `current` pointer. The authless serving
 * route reads the pointer ONCE per request and resolves strictly under that one
 * revision, so a concurrent GET never observes a mixed revision. All mutating
 * operations serialize on a store-wide `flock`; serving is lock-free.
 *
 * Sessions are ephemeral: an idle TTL sweeper (WP-Cron + request-time) reclaims
 * them, and any 404 from the serving route or the management API makes the
 * client transparently recreate the session on its next refresh.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Session_Store.
 *
 * File-backed store for preview sessions, assets and atomic document revisions.
 *
 * This class is the single cohesive implementation of the serving-contract-v2
 * store (lifecycle, immutable assets, atomic revisions, budgets, idle TTL, and
 * the filesystem primitives they share). Its aggregate complexity is inherent to
 * that protocol; splitting it would only relocate the weighted-method count into
 * a tightly-coupled collaborator and fragment one state machine across files.
 * The meaningful, readability-relevant gate — per-method cyclomatic / NPath
 * complexity — is kept under threshold via focused private helpers.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ExeLearning_Preview_Session_Store {

	/**
	 * The assetKey wire format: `{assetId}@{contentHashPrefix}` — a 36-char UUID-like
	 * id plus 8-64 hex chars of the content hash the project model already
	 * stores. The store validates the shape and treats the key as opaque; it
	 * never hashes the bytes.
	 *
	 * @var string
	 */
	const ASSET_KEY_REGEX = '/^[0-9a-fA-F-]{36}@[0-9a-f]{8,64}$/';

	/**
	 * Canonical previewId shape (RFC-4122-style UUID).
	 *
	 * @var string
	 */
	const PREVIEW_ID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/**
	 * Idle time-to-live for a session, in seconds (reference default: 30 min).
	 *
	 * @var int
	 */
	const TTL_SECONDS = 1800;

	/**
	 * Only rewrite the access clock when it is at least this many seconds stale,
	 * so a page that pulls dozens of subresources does not rewrite it each time.
	 *
	 * @var int
	 */
	const ACCESS_TOUCH_THROTTLE = 30;

	/**
	 * Maximum concurrent sessions per owner (LRU-evicted on create).
	 *
	 * @var int
	 */
	const MAX_SESSIONS_PER_USER = 4;

	/**
	 * Maximum files (documents + asset refs + fixed refs) in one session.
	 *
	 * @var int
	 */
	const MAX_FILES_PER_SESSION = 5000;

	/**
	 * Maximum combined document + asset bytes in one session (200 MiB).
	 *
	 * @var int
	 */
	const MAX_BYTES_PER_SESSION = 209715200;

	/**
	 * Maximum bytes for a single asset (128 MiB).
	 *
	 * @var int
	 */
	const MAX_ASSET_BYTES = 134217728;

	/**
	 * Global byte budget across all sessions, LRU-evicted (2 GiB).
	 *
	 * @var int
	 */
	const GLOBAL_MAX_BYTES = 2147483648;

	/**
	 * Advertised upper bound for one upload request (64 MiB).
	 *
	 * @var int
	 */
	const RECOMMENDED_BATCH_BYTES = 67108864;

	/**
	 * Absolute path to the store root (no trailing slash).
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Open exclusive-lock file handle while a mutation is in flight, else null.
	 *
	 * @var resource|null
	 */
	private $lock_handle = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $base_dir Store root; defaults to
	 *                              `wp_upload_dir()/exelearning-preview`. Tests
	 *                              pass a throwaway directory.
	 */
	public function __construct( $base_dir = null ) {
		if ( null === $base_dir ) {
			$upload_dir = wp_upload_dir();
			$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'exelearning-preview';
		}
		$this->base_dir = untrailingslashit( $base_dir );
	}

	/**
	 * The limits advertised by create-session and enforced everywhere.
	 *
	 * @return array<string,int>
	 */
	public function get_limits() {
		return array(
			'maxFilesPerSession'    => self::MAX_FILES_PER_SESSION,
			'maxBytesPerSession'    => self::MAX_BYTES_PER_SESSION,
			'maxAssetBytes'         => self::MAX_ASSET_BYTES,
			'recommendedBatchBytes' => self::RECOMMENDED_BATCH_BYTES,
		);
	}

	// =========================================================================
	// Path normalization (mirrors src/utils/content-path.util.ts)
	// =========================================================================

	/**
	 * Normalize a requested relative path against the content-map root.
	 *
	 * Returns a safe, root-relative POSIX path, or null when the request escapes
	 * the root (traversal, encoded or literal), carries a NUL byte, or is
	 * malformed. Backslashes are treated as literal filename characters, not
	 * separators (matching the core util).
	 *
	 * @param string $rel_path Requested path.
	 * @return string|null Normalized path or null.
	 */
	public static function normalize_content_path( $rel_path ) {
		$p = (string) $rel_path;
		// Drop any fragment, then any query string, before decoding.
		$hash_pos = strpos( $p, '#' );
		if ( false !== $hash_pos ) {
			$p = substr( $p, 0, $hash_pos );
		}
		$q_pos = strpos( $p, '?' );
		if ( false !== $q_pos ) {
			$p = substr( $p, 0, $q_pos );
		}
		// Single percent-decode pass.
		$p = rawurldecode( $p );
		if ( false !== strpos( $p, "\0" ) ) {
			return null;
		}
		// Drop leading slashes; empty means the default document.
		$p = ltrim( $p, '/' );
		if ( '' === $p ) {
			return 'index.html';
		}
		$norm = self::posix_normalize( $p );
		if ( '..' === $norm || 0 === strpos( $norm, '../' ) || 0 === strpos( $norm, '/' ) ) {
			return null;
		}
		return $norm;
	}

	/**
	 * Collapse `.` and `..` segments in a relative POSIX path (the leading slash
	 * has already been stripped by the caller).
	 *
	 * @param string $p Relative path.
	 * @return string Normalized relative path.
	 */
	private static function posix_normalize( $p ) {
		$parts = explode( '/', $p );
		$stack = array();
		foreach ( $parts as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				if ( ! empty( $stack ) && '..' !== end( $stack ) ) {
					array_pop( $stack );
				} else {
					$stack[] = '..';
				}
				continue;
			}
			$stack[] = $seg;
		}
		return empty( $stack ) ? '.' : implode( '/', $stack );
	}

	// =========================================================================
	// Session lifecycle
	// =========================================================================

	/**
	 * Create a session for an owner, LRU-evicting the owner's oldest session
	 * when the per-user cap is already reached.
	 *
	 * @param int $owner_user_id Owning WordPress user id.
	 * @return array{previewId:string,revision:int} New session descriptor.
	 */
	public function create_session( $owner_user_id ) {
		$this->lock();
		try {
			$this->enforce_per_user_cap( (int) $owner_user_id );

			$preview_id  = wp_generate_uuid4();
			$session_dir = $this->session_dir( $preview_id );
			$this->mkdir_p( $session_dir . '/assets' );
			$this->mkdir_p( $session_dir . '/revisions' );

			$now = time();
			$this->write_meta(
				$preview_id,
				array(
					'ownerUserId'   => (int) $owner_user_id,
					'revision'      => 0,
					'createdAt'     => $now,
					'documentBytes' => 0,
					'assetBytes'    => 0,
					'fileCount'     => 0,
				)
			);
			$this->write_pointer( $preview_id, 0 );
			$this->touch_access( $preview_id, true );

			return array(
				'previewId' => $preview_id,
				'revision'  => 0,
			);
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Resolve a management request to the owner's session.
	 *
	 * @param string $preview_id    Session id.
	 * @param int    $owner_user_id Requesting user id.
	 * @return array{status:int}|array{meta:array} 404 when missing, 403 when not
	 *                                             the owner, else the meta.
	 */
	public function get_owned_session( $preview_id, $owner_user_id ) {
		if ( ! preg_match( self::PREVIEW_ID_REGEX, (string) $preview_id ) ) {
			return array( 'status' => 404 );
		}
		$meta = $this->read_meta( $preview_id );
		if ( null === $meta ) {
			return array( 'status' => 404 );
		}
		if ( (int) $meta['ownerUserId'] !== (int) $owner_user_id ) {
			return array( 'status' => 403 );
		}
		$this->touch_access( $preview_id, true );
		return array( 'meta' => $meta );
	}

	/**
	 * Delete a session and all of its files.
	 *
	 * @param string $preview_id Session id.
	 * @return bool True when a session directory was removed.
	 */
	public function delete_session( $preview_id ) {
		$this->lock();
		try {
			return $this->delete_session_unlocked( $preview_id );
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Reclaim every session whose access clock is older than the idle TTL, plus
	 * any stray staging leftovers from an interrupted publish.
	 *
	 * @return int Number of sessions removed.
	 */
	public function sweep_expired() {
		$this->lock();
		try {
			$removed = 0;
			$now     = time();
			foreach ( $this->list_sessions() as $preview_id ) {
				$access = $this->access_time( $preview_id );
				if ( $now - $access > self::TTL_SECONDS ) {
					if ( $this->delete_session_unlocked( $preview_id ) ) {
						++$removed;
					}
					continue;
				}
				$this->prune_stray_staging( $preview_id );
			}
			return $removed;
		} finally {
			$this->unlock();
		}
	}

	// =========================================================================
	// Assets (layer 2)
	// =========================================================================

	/**
	 * Store uploaded assets. Keys are immutable: an existing key is reported in
	 * `alreadyStored` and its bytes are NOT replaced (a replaced author file
	 * gets a new key because its content hash changed). Per-entry failures land
	 * in `rejected` with a reason.
	 *
	 * @param string $preview_id Session id.
	 * @param array  $entries    List of `{ key, declaredSize, tmp_path }`; the
	 *                           bytes live in the readable `tmp_path` file.
	 * @return array{stored:string[],alreadyStored:string[],rejected:array}|array{status:int}
	 */
	public function store_assets( $preview_id, $entries ) {
		$this->lock();
		try {
			$meta = $this->read_meta( $preview_id );
			if ( null === $meta ) {
				return array( 'status' => 404 );
			}

			$stored         = array();
			$already_stored = array();
			$rejected       = array();

			foreach ( $entries as $entry ) {
				$key     = isset( $entry['key'] ) ? (string) $entry['key'] : '';
				$session = (int) $meta['documentBytes'] + (int) $meta['assetBytes'];
				$outcome = $this->classify_asset_entry( $preview_id, $entry, $session );
				if ( isset( $outcome['already'] ) ) {
					$already_stored[] = $key;
				} elseif ( isset( $outcome['rejected'] ) ) {
					$rejected[] = array(
						'key'    => $key,
						'reason' => $outcome['rejected'],
					);
				} else {
					$this->copy_file( $outcome['tmp_path'], $this->asset_path( $preview_id, $key ) );
					$meta['assetBytes'] = (int) $meta['assetBytes'] + $outcome['store'];
					$stored[]           = $key;
				}
			}

			$this->write_meta( $preview_id, $meta );
			$this->touch_access( $preview_id, true );

			return array(
				'stored'        => $stored,
				'alreadyStored' => $already_stored,
				'rejected'      => $rejected,
			);
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Classify one upload against the immutability/validation/budget rules,
	 * without side effects: returns `['already'=>true]` (key already stored),
	 * `['rejected'=>reason]`, or `['store'=>actualSize,'tmp_path'=>path]` when it
	 * should be stored. Keeps the per-entry decision tree out of the store loop.
	 *
	 * @param string $preview_id    Session id.
	 * @param array  $entry         `{ key, declaredSize, tmp_path }`.
	 * @param int    $session_bytes Current documents + assets byte total.
	 * @return array
	 */
	private function classify_asset_entry( $preview_id, $entry, $session_bytes ) {
		$key = isset( $entry['key'] ) ? (string) $entry['key'] : '';
		if ( ! preg_match( self::ASSET_KEY_REGEX, $key ) ) {
			return array( 'rejected' => 'invalid-key' );
		}
		// Immutable: an existing key keeps its bytes (a replaced file gets a new
		// key), so it is neither re-validated nor re-stored.
		if ( $this->asset_exists( $preview_id, $key ) ) {
			return array( 'already' => true );
		}
		return $this->classify_new_asset( $preview_id, $entry, $session_bytes );
	}

	/**
	 * Size / budget checks for an asset key not yet stored: declared-vs-actual
	 * match, per-asset cap, per-session cap, then global LRU budget. Returns
	 * `['rejected'=>reason]` or `['store'=>actualSize,'tmp_path'=>path]`.
	 *
	 * @param string $preview_id    Session id.
	 * @param array  $entry         `{ declaredSize, tmp_path }`.
	 * @param int    $session_bytes Current documents + assets byte total.
	 * @return array
	 */
	private function classify_new_asset( $preview_id, $entry, $session_bytes ) {
		$tmp_path = isset( $entry['tmp_path'] ) ? (string) $entry['tmp_path'] : '';
		$actual   = is_file( $tmp_path ) ? (int) filesize( $tmp_path ) : -1;
		if ( $actual < 0 ) {
			return array( 'rejected' => 'missing-upload' );
		}
		if ( (int) $entry['declaredSize'] !== $actual ) {
			return array( 'rejected' => 'size-mismatch' );
		}
		if ( $actual > self::MAX_ASSET_BYTES ) {
			return array( 'rejected' => 'asset-too-large' );
		}
		if ( $session_bytes + $actual > self::MAX_BYTES_PER_SESSION ) {
			return array( 'rejected' => 'session-budget-exceeded' );
		}
		if ( ! $this->evict_for_global_budget( $preview_id, $actual ) ) {
			return array( 'rejected' => 'global-budget-exceeded' );
		}
		return array(
			'store'    => $actual,
			'tmp_path' => $tmp_path,
		);
	}

	// =========================================================================
	// Revisions (layer 3 publication)
	// =========================================================================

	/**
	 * Publish a revision. Validation order per the contract: revision check
	 * (409) -> path normalization (400) -> asset existence (422) -> fixed
	 * resource existence (422) -> file-count / byte budgets (413). The new
	 * revision is staged in full and swapped in atomically, so a concurrent
	 * serve observes revision N or N+1, never a mixture.
	 *
	 * @param string                              $preview_id      Session id.
	 * @param array                               $meta_wire       `{ baseRevision,
	 *                                                             nextRevision, writes:
	 *                                                             [{path,tmp_path}], deletes:[],
	 *                                                             assetRefs:{}, fixedRefs:{} }`.
	 * @param ExeLearning_Preview_Fixed_Resources $fixed_resources Manifest gate.
	 * @return array{revision:int,active:bool}|array{status:int}
	 */
	public function apply_revision( $preview_id, $meta_wire, $fixed_resources ) {
		$this->lock();
		try {
			$meta = $this->read_meta( $preview_id );
			if ( null === $meta ) {
				return array( 'status' => 404 );
			}
			$current = (int) $meta['revision'];

			// 1. Revision ordering (409).
			$conflict = $this->validate_revision_order( $meta_wire, $current );
			if ( null !== $conflict ) {
				return $conflict;
			}

			// 2. Normalize every client-supplied path (400 on any unsafe path).
			$paths = $this->normalize_revision_paths( $meta_wire );
			if ( isset( $paths['error'] ) ) {
				return $paths['error'];
			}

			// 3/4. Referenced assets and fixed resources must exist (422).
			$missing = $this->check_asset_refs( $preview_id, $paths['assetRefs'] );
			if ( null !== $missing ) {
				return $missing;
			}
			$unknown = $this->check_fixed_refs( $paths['fixedRefs'], $fixed_resources );
			if ( null !== $unknown ) {
				return $unknown;
			}

			// 5. File-count / byte budgets on the post-delta set (413).
			$budget = $this->compute_revision_budget( $preview_id, $current, $meta, $paths );
			if ( isset( $budget['error'] ) ) {
				return $budget['error'];
			}

			// 6. Stage the full next revision, then swap the pointer atomically.
			$next = (int) $meta_wire['nextRevision'];
			$this->stage_and_publish( $preview_id, $current, $next, $paths['writes'], $paths['deletes'], $paths['assetRefs'], $paths['fixedRefs'] );

			$meta['revision']      = $next;
			$meta['documentBytes'] = $budget['documentBytes'];
			$meta['fileCount']     = $budget['fileCount'];
			$this->write_meta( $preview_id, $meta );
			$this->touch_access( $preview_id, true );
			$this->prune_old_revisions( $preview_id, $next );

			return array(
				'revision' => $next,
				'active'   => true,
			);
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Revision-ordering gate: stale base or non-consecutive next is a conflict.
	 *
	 * @param array $meta_wire Revision meta.
	 * @param int   $current   Active revision.
	 * @return array{status:int}|null 409 result, or null when the order is valid.
	 */
	private function validate_revision_order( $meta_wire, $current ) {
		$base = (int) $meta_wire['baseRevision'];
		$next = (int) $meta_wire['nextRevision'];
		if ( $base !== $current || $next !== $current + 1 ) {
			return array(
				'status'          => 409,
				'reason'          => 'revision-conflict',
				'currentRevision' => $current,
			);
		}
		return null;
	}

	/**
	 * Normalize every client-supplied path in the revision. Returns
	 * `['error'=>400result]` on the first unsafe path, else the normalized maps
	 * `['writes'=>path=>tmp, 'deletes'=>set, 'assetRefs'=>path=>key,
	 * 'fixedRefs'=>path=>id]`.
	 *
	 * @param array $meta_wire Revision meta.
	 * @return array
	 */
	private function normalize_revision_paths( $meta_wire ) {
		$writes = array();
		foreach ( $meta_wire['writes'] as $write ) {
			$norm = self::normalize_content_path( $write['path'] );
			if ( null === $norm ) {
				return array( 'error' => $this->bad_path( 'writes', $write['path'] ) );
			}
			$writes[ $norm ] = (string) $write['tmp_path'];
		}
		$deletes = array();
		foreach ( $meta_wire['deletes'] as $raw ) {
			$norm = self::normalize_content_path( $raw );
			if ( null === $norm ) {
				return array( 'error' => $this->bad_path( 'deletes', $raw ) );
			}
			$deletes[ $norm ] = true;
		}
		$asset_refs = array();
		foreach ( (array) $meta_wire['assetRefs'] as $raw => $ref_key ) {
			$norm = self::normalize_content_path( $raw );
			if ( null === $norm ) {
				return array( 'error' => $this->bad_path( 'assetRefs', $raw ) );
			}
			$asset_refs[ $norm ] = (string) $ref_key;
		}
		$fixed_refs = array();
		foreach ( (array) $meta_wire['fixedRefs'] as $raw => $ref_id ) {
			$norm = self::normalize_content_path( $raw );
			if ( null === $norm ) {
				return array( 'error' => $this->bad_path( 'fixedRefs', $raw ) );
			}
			$fixed_refs[ $norm ] = (string) $ref_id;
		}
		return array(
			'writes'    => $writes,
			'deletes'   => $deletes,
			'assetRefs' => $asset_refs,
			'fixedRefs' => $fixed_refs,
		);
	}

	/**
	 * Every referenced asset must be stored (a malformed key cannot exist, so it
	 * reports as missing too).
	 *
	 * @param string $preview_id Session id.
	 * @param array  $asset_refs served path => assetKey.
	 * @return array{status:int}|null 422 result, or null when all exist.
	 */
	private function check_asset_refs( $preview_id, $asset_refs ) {
		$missing = array();
		foreach ( array_unique( array_values( $asset_refs ) ) as $ref_key ) {
			if ( ! $this->asset_exists( $preview_id, $ref_key ) ) {
				$missing[] = $ref_key;
			}
		}
		if ( ! empty( $missing ) ) {
			return array(
				'status'  => 422,
				'reason'  => 'missing-assets',
				'missing' => array_values( $missing ),
			);
		}
		return null;
	}

	/**
	 * Every referenced fixed resource must be manifest-listed.
	 *
	 * @param array                               $fixed_refs      served path => fixedResourceId.
	 * @param ExeLearning_Preview_Fixed_Resources $fixed_resources Manifest gate.
	 * @return array{status:int}|null 422 result, or null when all are known.
	 */
	private function check_fixed_refs( $fixed_refs, $fixed_resources ) {
		$unknown = array();
		foreach ( array_unique( array_values( $fixed_refs ) ) as $ref_id ) {
			if ( ! $fixed_resources->has_resource( $ref_id ) ) {
				$unknown[] = $ref_id;
			}
		}
		if ( ! empty( $unknown ) ) {
			return array(
				'status'    => 422,
				'reason'    => 'unknown-fixed-resources',
				'resources' => array_values( $unknown ),
			);
		}
		return null;
	}

	/**
	 * Compute the post-delta document set and enforce the file-count / byte
	 * budgets. Returns `['error'=>413result]` on a breach, else
	 * `['documentBytes'=>int, 'fileCount'=>int]` for the caller to persist.
	 *
	 * @param string $preview_id Session id.
	 * @param int    $current    Active revision.
	 * @param array  $meta       Session meta.
	 * @param array  $paths      Normalized revision maps.
	 * @return array
	 */
	private function compute_revision_budget( $preview_id, $current, $meta, $paths ) {
		$prev_docs = ( $current > 0 )
			? $this->dir_files_and_bytes( $this->revision_dir( $preview_id, $current ) . '/documents' )
			: array();
		$new_docs  = $prev_docs;
		foreach ( array_keys( $paths['deletes'] ) as $del ) {
			unset( $new_docs[ $del ] );
		}
		foreach ( $paths['writes'] as $path => $tmp_path ) {
			$size              = is_file( $tmp_path ) ? (int) filesize( $tmp_path ) : 0;
			$new_docs[ $path ] = $size;
		}
		$document_bytes = array_sum( $new_docs );
		$file_count     = count( $new_docs ) + count( $paths['assetRefs'] ) + count( $paths['fixedRefs'] );
		if ( $file_count > self::MAX_FILES_PER_SESSION ) {
			return array( 'error' => $this->too_large( 'Too many files' ) );
		}
		if ( $document_bytes + (int) $meta['assetBytes'] > self::MAX_BYTES_PER_SESSION ) {
			return array( 'error' => $this->too_large( 'Session over byte budget' ) );
		}
		$byte_delta = $document_bytes - (int) $meta['documentBytes'];
		if ( $byte_delta > 0 && ! $this->evict_for_global_budget( $preview_id, $byte_delta ) ) {
			return array( 'error' => $this->too_large( 'Preview storage budget exceeded' ) );
		}
		return array(
			'documentBytes' => $document_bytes,
			'fileCount'     => $file_count,
		);
	}

	// =========================================================================
	// Serving (three-layer resolution) — lock-free
	// =========================================================================

	/**
	 * Resolve a served path against the active revision: documents ->
	 * assetRefs->assets -> fixedRefs->manifest -> null. Touches the idle-TTL
	 * clock. Returns a descriptor the HTTP adapter streams, never bytes.
	 *
	 * @param string                              $preview_id      Session id.
	 * @param string                              $rel_path        Requested path.
	 * @param ExeLearning_Preview_Fixed_Resources $fixed_resources Fixed resolver.
	 * @return array|null `{ kind, rel, path, etag? }` or null (404).
	 */
	public function serve_lookup( $preview_id, $rel_path, $fixed_resources ) {
		if ( ! preg_match( self::PREVIEW_ID_REGEX, (string) $preview_id ) ) {
			return null;
		}
		if ( ! is_dir( $this->session_dir( $preview_id ) ) ) {
			return null;
		}

		// Idle-TTL enforcement at request time (WP-Cron is traffic-dependent).
		if ( time() - $this->access_time( $preview_id ) > self::TTL_SECONDS ) {
			$this->delete_session( $preview_id );
			return null;
		}
		$this->touch_access( $preview_id, false );

		// Read the pointer ONCE; resolve strictly under that revision.
		$revision = $this->read_pointer( $preview_id );
		if ( $revision <= 0 ) {
			return null;
		}
		$path = self::normalize_content_path( $rel_path );
		if ( null === $path ) {
			return null;
		}

		return $this->resolve_served_file( $preview_id, $revision, $path, $fixed_resources );
	}

	/**
	 * Three-layer resolution against one revision: documents -> assetRefs->assets
	 * -> fixedRefs->manifest -> null. The path is already normalized and the
	 * revision already validated by {@see serve_lookup}.
	 *
	 * @param string                              $preview_id      Session id.
	 * @param int                                 $revision        Active revision.
	 * @param string                              $path            Normalized served path.
	 * @param ExeLearning_Preview_Fixed_Resources $fixed_resources Fixed resolver.
	 * @return array|null `{ kind, rel, path, etag? }` or null.
	 */
	private function resolve_served_file( $preview_id, $revision, $path, $fixed_resources ) {
		// 1. Generated document.
		$doc_root = $this->revision_dir( $preview_id, $revision ) . '/documents';
		$doc_file = $doc_root . '/' . $path;
		if ( is_file( $doc_file ) && $this->is_contained( $doc_file, $doc_root ) ) {
			return array(
				'kind' => 'document',
				'rel'  => $path,
				'path' => $doc_file,
			);
		}

		$refs = $this->read_refs( $preview_id, $revision );

		// 2. Session project asset.
		if ( isset( $refs['assetRefs'][ $path ] ) ) {
			$key = (string) $refs['assetRefs'][ $path ];
			if ( preg_match( self::ASSET_KEY_REGEX, $key ) && $this->asset_exists( $preview_id, $key ) ) {
				return array(
					'kind' => 'asset',
					'rel'  => $path,
					'path' => $this->asset_path( $preview_id, $key ),
					'etag' => $key,
				);
			}
		}

		// 3. Fixed installation resource.
		if ( isset( $refs['fixedRefs'][ $path ] ) ) {
			$resolved = $fixed_resources->get_resource_path( (string) $refs['fixedRefs'][ $path ] );
			if ( null !== $resolved ) {
				return array(
					'kind' => 'fixed',
					'rel'  => $path,
					'path' => $resolved,
				);
			}
		}

		return null;
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Build a 400 response for an unsafe path.
	 *
	 * @param string $field Offending field name.
	 * @param string $path  Offending path.
	 * @return array{status:int,message:string}
	 */
	private function bad_path( $field, $path ) {
		return array(
			'status'  => 400,
			'message' => sprintf( 'Unsafe path in %1$s: %2$s', $field, $path ),
		);
	}

	/**
	 * Build a 413 response.
	 *
	 * @param string $message Reason.
	 * @return array{status:int,message:string}
	 */
	private function too_large( $message ) {
		return array(
			'status'  => 413,
			'message' => $message,
		);
	}

	/**
	 * Absolute directory for a session.
	 *
	 * @param string $preview_id Session id.
	 * @return string
	 */
	private function session_dir( $preview_id ) {
		return $this->base_dir . '/' . $preview_id;
	}

	/**
	 * Absolute directory for a revision snapshot.
	 *
	 * @param string $preview_id Session id.
	 * @param int    $n          Revision number.
	 * @return string
	 */
	private function revision_dir( $preview_id, $n ) {
		return $this->session_dir( $preview_id ) . '/revisions/' . (int) $n;
	}

	/**
	 * Absolute path to an asset file.
	 *
	 * @param string $preview_id Session id.
	 * @param string $key        Validated assetKey.
	 * @return string
	 */
	private function asset_path( $preview_id, $key ) {
		return $this->session_dir( $preview_id ) . '/assets/' . $key;
	}

	/**
	 * Whether an asset with the given key is stored.
	 *
	 * @param string $preview_id Session id.
	 * @param string $key        assetKey.
	 * @return bool
	 */
	private function asset_exists( $preview_id, $key ) {
		return is_file( $this->asset_path( $preview_id, $key ) );
	}

	/**
	 * Read and decode a session's meta.json, or null when absent/corrupt.
	 *
	 * @param string $preview_id Session id.
	 * @return array|null
	 */
	private function read_meta( $preview_id ) {
		if ( ! preg_match( self::PREVIEW_ID_REGEX, (string) $preview_id ) ) {
			return null;
		}
		$path = $this->session_dir( $preview_id ) . '/meta.json';
		if ( ! is_file( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a small internal metadata file.
		$raw    = file_get_contents( $path );
		$parsed = ( false !== $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $parsed ) ? $parsed : null;
	}

	/**
	 * Atomically write a session's meta.json.
	 *
	 * @param string $preview_id Session id.
	 * @param array  $meta       Metadata.
	 */
	private function write_meta( $preview_id, $meta ) {
		$this->atomic_write( $this->session_dir( $preview_id ) . '/meta.json', wp_json_encode( $meta ) );
	}

	/**
	 * Read the active-revision pointer (0 when no revision is published).
	 *
	 * @param string $preview_id Session id.
	 * @return int
	 */
	private function read_pointer( $preview_id ) {
		$path = $this->session_dir( $preview_id ) . '/current';
		if ( ! is_file( $path ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the tiny pointer file.
		$raw = file_get_contents( $path );
		return ( false !== $raw ) ? (int) trim( $raw ) : 0;
	}

	/**
	 * Atomically swap the active-revision pointer.
	 *
	 * @param string $preview_id Session id.
	 * @param int    $n          Revision number.
	 */
	private function write_pointer( $preview_id, $n ) {
		$this->atomic_write( $this->session_dir( $preview_id ) . '/current', (string) (int) $n );
	}

	/**
	 * Read a revision's ref maps, defaulting to empty maps.
	 *
	 * @param string $preview_id Session id.
	 * @param int    $n          Revision number.
	 * @return array{assetRefs:array,fixedRefs:array}
	 */
	private function read_refs( $preview_id, $n ) {
		$path = $this->revision_dir( $preview_id, $n ) . '/refs.json';
		$refs = array(
			'assetRefs' => array(),
			'fixedRefs' => array(),
		);
		if ( ! is_file( $path ) ) {
			return $refs;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a small internal metadata file.
		$raw    = file_get_contents( $path );
		$parsed = ( false !== $raw ) ? json_decode( $raw, true ) : null;
		if ( is_array( $parsed ) ) {
			if ( isset( $parsed['assetRefs'] ) && is_array( $parsed['assetRefs'] ) ) {
				$refs['assetRefs'] = $parsed['assetRefs'];
			}
			if ( isset( $parsed['fixedRefs'] ) && is_array( $parsed['fixedRefs'] ) ) {
				$refs['fixedRefs'] = $parsed['fixedRefs'];
			}
		}
		return $refs;
	}

	/**
	 * The last-access time (MTIME of the `access` clock file), or 0.
	 *
	 * @param string $preview_id Session id.
	 * @return int
	 */
	private function access_time( $preview_id ) {
		$path = $this->session_dir( $preview_id ) . '/access';
		if ( ! is_file( $path ) ) {
			return 0;
		}
		$mtime = filemtime( $path );
		return ( false !== $mtime ) ? (int) $mtime : 0;
	}

	/**
	 * Refresh the access clock. Throttled unless `$force`, so a page that pulls
	 * many subresources does not rewrite it on every request.
	 *
	 * @param string $preview_id Session id.
	 * @param bool   $force      Bypass the throttle.
	 */
	private function touch_access( $preview_id, $force ) {
		$path = $this->session_dir( $preview_id ) . '/access';
		if ( ! $force && is_file( $path ) ) {
			$mtime = filemtime( $path );
			if ( false !== $mtime && ( time() - (int) $mtime ) < self::ACCESS_TOUCH_THROTTLE ) {
				return;
			}
		}
		// Best-effort TTL/LRU clock update; a failure only shortens the TTL.
		@touch( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch,WordPress.PHP.NoSilencedErrors.Discouraged -- Cheap clock update.
	}

	/**
	 * Stage the next revision (carry prior documents forward, drop deletes,
	 * overlay writes, write refs.json), rename it into place, and swap the
	 * pointer atomically.
	 *
	 * @param string $preview_id Session id.
	 * @param int    $current    Current revision.
	 * @param int    $next       Next revision.
	 * @param array  $writes     Map normalizedPath => tmp file path.
	 * @param array  $deletes    Set of normalized paths (keys).
	 * @param array  $asset_refs Map normalizedPath => assetKey.
	 * @param array  $fixed_refs Map normalizedPath => fixedResourceId.
	 */
	private function stage_and_publish( $preview_id, $current, $next, $writes, $deletes, $asset_refs, $fixed_refs ) {
		$staging = $this->session_dir( $preview_id ) . '/revisions/.stage-' . uniqid( '', true );
		$this->mkdir_p( $staging . '/documents' );

		// Carry prior documents forward (except deleted or overwritten paths).
		if ( $current > 0 ) {
			$prev_root = $this->revision_dir( $preview_id, $current ) . '/documents';
			foreach ( $this->dir_files_and_bytes( $prev_root ) as $rel => $unused_size ) {
				if ( isset( $deletes[ $rel ] ) || isset( $writes[ $rel ] ) ) {
					continue;
				}
				$this->copy_file( $prev_root . '/' . $rel, $staging . '/documents/' . $rel );
			}
		}
		// Overlay this revision's writes.
		foreach ( $writes as $rel => $tmp_path ) {
			$this->copy_file( $tmp_path, $staging . '/documents/' . $rel );
		}
		// Persist the full ref maps for this revision.
		$this->atomic_write(
			$staging . '/refs.json',
			wp_json_encode(
				array(
					'revision'  => $next,
					'assetRefs' => (object) $asset_refs,
					'fixedRefs' => (object) $fixed_refs,
				)
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic publish of the staged revision directory.
		rename( $staging, $this->revision_dir( $preview_id, $next ) );
		$this->write_pointer( $preview_id, $next );
	}

	/**
	 * Delete revision snapshots older than the two most recent, giving in-flight
	 * serving requests (which read the pointer, then the files) a grace window.
	 *
	 * @param string $preview_id Session id.
	 * @param int    $active     Active revision number.
	 */
	private function prune_old_revisions( $preview_id, $active ) {
		$revisions_dir = $this->session_dir( $preview_id ) . '/revisions';
		foreach ( $this->scandir_entries( $revisions_dir ) as $entry ) {
			if ( ! ctype_digit( $entry ) ) {
				continue;
			}
			if ( (int) $entry <= $active - 3 ) {
				$this->rrmdir( $revisions_dir . '/' . $entry );
			}
		}
	}

	/**
	 * Remove interrupted staging directories older than the idle TTL.
	 *
	 * @param string $preview_id Session id.
	 */
	private function prune_stray_staging( $preview_id ) {
		$revisions_dir = $this->session_dir( $preview_id ) . '/revisions';
		$now           = time();
		foreach ( $this->scandir_entries( $revisions_dir ) as $entry ) {
			if ( 0 !== strpos( $entry, '.stage-' ) ) {
				continue;
			}
			$full  = $revisions_dir . '/' . $entry;
			$mtime = filemtime( $full );
			if ( false !== $mtime && ( $now - (int) $mtime ) > self::TTL_SECONDS ) {
				$this->rrmdir( $full );
			}
		}
	}

	/**
	 * Enforce the per-user session cap, evicting the owner's LRU session.
	 *
	 * @param int $owner_user_id Owning user id.
	 */
	private function enforce_per_user_cap( $owner_user_id ) {
		$owned = array();
		foreach ( $this->list_sessions() as $preview_id ) {
			$meta = $this->read_meta( $preview_id );
			if ( null !== $meta && (int) $meta['ownerUserId'] === $owner_user_id ) {
				$owned[ $preview_id ] = $this->access_time( $preview_id );
			}
		}
		$owned_count = count( $owned );
		while ( $owned_count >= self::MAX_SESSIONS_PER_USER ) {
			$lru = $this->lru_of( $owned );
			if ( null === $lru ) {
				break;
			}
			$this->delete_session_unlocked( $lru );
			unset( $owned[ $lru ] );
			--$owned_count;
		}
	}

	/**
	 * Evict other sessions (LRU first) until `$incoming` bytes fit the global
	 * budget. Returns false when it cannot fit even after evicting them all.
	 *
	 * @param string $current  Session that must be preserved.
	 * @param int    $incoming Incoming byte delta.
	 * @return bool
	 */
	private function evict_for_global_budget( $current, $incoming ) {
		$sizes  = array();
		$access = array();
		$global = 0;
		foreach ( $this->list_sessions() as $preview_id ) {
			$meta = $this->read_meta( $preview_id );
			if ( null === $meta ) {
				continue;
			}
			$sizes[ $preview_id ]  = (int) $meta['documentBytes'] + (int) $meta['assetBytes'];
			$access[ $preview_id ] = $this->access_time( $preview_id );
			$global               += $sizes[ $preview_id ];
		}
		while ( $global + $incoming > self::GLOBAL_MAX_BYTES ) {
			unset( $access[ $current ] );
			$lru = $this->lru_of( $access );
			if ( null === $lru ) {
				return false;
			}
			$this->delete_session_unlocked( $lru );
			$global -= isset( $sizes[ $lru ] ) ? $sizes[ $lru ] : 0;
			unset( $access[ $lru ], $sizes[ $lru ] );
		}
		return true;
	}

	/**
	 * The key with the smallest value (oldest access clock), or null.
	 *
	 * @param array<string,int> $access Map previewId => access time.
	 * @return string|null
	 */
	private function lru_of( $access ) {
		$lru      = null;
		$lru_time = PHP_INT_MAX;
		foreach ( $access as $preview_id => $time ) {
			if ( $time < $lru_time ) {
				$lru      = $preview_id;
				$lru_time = $time;
			}
		}
		return $lru;
	}

	/**
	 * Every previewId directory currently in the store.
	 *
	 * @return string[]
	 */
	private function list_sessions() {
		$sessions = array();
		foreach ( $this->scandir_entries( $this->base_dir ) as $entry ) {
			if ( preg_match( self::PREVIEW_ID_REGEX, $entry ) && is_dir( $this->base_dir . '/' . $entry ) ) {
				$sessions[] = $entry;
			}
		}
		return $sessions;
	}

	/**
	 * Delete a session directory without taking the store lock (the caller holds
	 * it).
	 *
	 * @param string $preview_id Session id.
	 * @return bool
	 */
	private function delete_session_unlocked( $preview_id ) {
		if ( ! preg_match( self::PREVIEW_ID_REGEX, (string) $preview_id ) ) {
			return false;
		}
		$dir = $this->session_dir( $preview_id );
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$this->rrmdir( $dir );
		return true;
	}

	/**
	 * List the immediate files of a directory tree as normalizedRelPath => size.
	 *
	 * @param string $root Directory root.
	 * @return array<string,int>
	 */
	private function dir_files_and_bytes( $root ) {
		$out = array();
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		$prefix_len = strlen( $root ) + 1;
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$rel         = str_replace( '\\', '/', substr( $file->getPathname(), $prefix_len ) );
				$out[ $rel ] = (int) $file->getSize();
			}
		}
		return $out;
	}

	/**
	 * Entries of a directory (excluding `.`/`..`), or an empty array.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private function scandir_entries( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return array();
		}
		return array_values( array_diff( $entries, array( '.', '..' ) ) );
	}

	/**
	 * Create a directory (recursively) if it does not exist.
	 *
	 * @param string $dir Directory.
	 */
	private function mkdir_p( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	/**
	 * Copy a file, creating the destination's parent directory first.
	 *
	 * @param string $src Source path.
	 * @param string $dst Destination path.
	 */
	private function copy_file( $src, $dst ) {
		$this->mkdir_p( dirname( $dst ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Same-filesystem copy of validated preview bytes.
		copy( $src, $dst );
	}

	/**
	 * Atomically write string contents to a file (temp file + rename).
	 *
	 * @param string $path     Destination path.
	 * @param string $contents Contents.
	 */
	private function atomic_write( $path, $contents ) {
		$this->mkdir_p( dirname( $path ) );
		$tmp = $path . '.tmp-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic staged write of an internal file.
		file_put_contents( $tmp, (string) $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename over the destination.
		rename( $tmp, $path );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			if ( is_file( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing an internal preview file.
				unlink( $dir );
			}
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing an internal preview directory.
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing an internal preview file.
				unlink( $item->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the now-empty root.
		rmdir( $dir );
	}

	/**
	 * Whether a resolved file stays within a root directory (symlink guard).
	 *
	 * @param string $full Candidate absolute path.
	 * @param string $root Root directory.
	 * @return bool
	 */
	private function is_contained( $full, $root ) {
		$real_file = realpath( $full );
		$real_root = realpath( $root );
		if ( false === $real_file || false === $real_root ) {
			// Virtual filesystems: the path was already traversal-normalized.
			return false === strpos( $full, '..' );
		}
		return $real_file === $real_root || 0 === strpos( $real_file, $real_root . DIRECTORY_SEPARATOR );
	}

	/**
	 * Create the store base directory and drop a deny guard into it, idempotently.
	 *
	 * The store lives under wp-content/uploads, which the web server serves
	 * directly. Untrusted author HTML written here would otherwise be fetchable
	 * same-origin WITHOUT the sandbox CSP the REST serving route adds, defeating
	 * the opaque-origin isolation. The `.htaccess` denies direct access on Apache
	 * (it cascades to every session subdirectory); the empty `index.php` stops a
	 * directory listing. This mirrors WordPress core's protected-upload-subdir
	 * pattern. nginx and other servers ignore `.htaccess`: there the store must
	 * sit outside the web root or the vhost must deny this location (see
	 * docs/preview-serving-contract.md). The guard is written before any session
	 * bytes, and self-heals if the files are removed.
	 */
	private function ensure_base_dir_guard() {
		if ( ! is_dir( $this->base_dir ) ) {
			wp_mkdir_p( $this->base_dir );
		}
		$htaccess = $this->base_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$this->atomic_write(
				$htaccess,
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
					. "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			);
		}
		$index = $this->base_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			$this->atomic_write( $index, "<?php // Silence is golden.\n" );
		}
	}

	/**
	 * Acquire the store-wide exclusive lock (all mutations serialize on it).
	 */
	private function lock() {
		$this->ensure_base_dir_guard();
		$lock_path = $this->base_dir . '/.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Advisory lock handle, not content I/O.
		$handle = fopen( $lock_path, 'c' );
		if ( false !== $handle ) {
			flock( $handle, LOCK_EX );
			$this->lock_handle = $handle;
		}
	}

	/**
	 * Release the store-wide lock.
	 */
	private function unlock() {
		if ( null !== $this->lock_handle ) {
			flock( $this->lock_handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the advisory lock handle.
			fclose( $this->lock_handle );
			$this->lock_handle = null;
		}
	}
}
