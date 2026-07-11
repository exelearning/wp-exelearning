<?php
/**
 * Host-served opaque HTTP preview — serving contract v2.
 *
 * Serves the eXeLearning *editor preview* of untrusted author content over HTTP
 * in an opaque origin, the same isolation posture the plugin already gives
 * *published* content (ExeLearning_Content_Proxy), applied to a short-lived,
 * capability-addressed preview session.
 *
 * MIRRORS eXe core: doc/development/preview-serving-contract.md — the canonical
 * source of truth, and src/routes/preview-session.ts + src/services/
 * preview-serving.ts. The sandbox-first CSP emitted on scriptable document
 * types (self::SANDBOX_CSP) MUST stay BYTE-IDENTICAL to that document.
 *
 * Two surfaces:
 *
 * - Authless serving route: `GET {rest}/exelearning/v1/preview/{previewId}/{file}`
 *   (permission_callback __return_true). The capability is the unguessable
 *   previewId; an opaque iframe sends no cookies, so there is deliberately no
 *   WordPress auth here. Three-layer resolution (documents -> assetRefs->assets
 *   -> fixedRefs->manifest), tiered Cache-Control, ETag/Range on assets, and the
 *   verbatim sandbox CSP on every scriptable type from any layer.
 * - Authenticated, owner-scoped management API (`current_user_can('upload_files')`
 *   + session ownership): create sessions, upload assets, publish revisions,
 *   delete.
 *
 * The heavy lifting (atomic revisions, budgets, TTL) lives in
 * ExeLearning_Preview_Session_Store; the fixed layer in
 * ExeLearning_Preview_Fixed_Resources. This class is the HTTP adapter only.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Proxy.
 *
 * The HTTP adapter for the full serving contract v2 — the authless serving
 * route, the four authenticated management endpoints, and the shared header /
 * range / multipart plumbing. Its aggregate complexity reflects the size of that
 * protocol surface, not tangled logic; per-method cyclomatic / NPath complexity
 * is kept under threshold with focused private helpers, and the public
 * `build_serve_response()` surface the conformance vectors drive stays on this
 * class. Splitting the management and serving halves apart would fragment the
 * one adapter and its tested entry points without a genuine complexity win.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ExeLearning_Preview_Proxy {

	/**
	 * REST namespace, shared with ExeLearning_REST_API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'exelearning/v1';

	/**
	 * Contract protocol version advertised by create-session.
	 *
	 * @var int
	 */
	const PROTOCOL_VERSION = 2;

	/**
	 * Canonical previewId shape (RFC-4122-style UUID). Anything else is a 404.
	 *
	 * @var string
	 */
	const PREVIEW_ID_REGEX = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

	/**
	 * Loose route-level previewId matcher (exact shape re-validated in the store).
	 *
	 * @var string
	 */
	const PREVIEW_ID_ROUTE = '[0-9a-f-]{36}';

	/**
	 * WP-Cron hook that runs the idle-session sweep.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'exelearning_preview_cleanup';

	/**
	 * Custom WP-Cron recurrence (15 minutes) for the sweep.
	 *
	 * @var string
	 */
	const CRON_SCHEDULE = 'exelearning_preview_15min';

	/**
	 * Sandbox-first CSP for scriptable document types.
	 *
	 * BYTE-IDENTICAL to eXe core doc/development/preview-serving-contract.md
	 * (previewCspHeader() in src/shared/security/previewSandbox.ts). Kept as a
	 * literal constant — NOT built from the published-content CSP builder, which
	 * emits a different, dynamic policy — so it can never drift from core.
	 *
	 * @var string
	 */
	const SANDBOX_CSP = "sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';";

	/**
	 * Document MIME types that can execute script and therefore MUST carry the
	 * sandbox CSP.
	 *
	 * @var string[]
	 */
	const SCRIPTABLE_TYPES = array(
		'text/html',
		'image/svg+xml',
		'application/xml',
		'application/xhtml+xml',
	);

	/**
	 * Extension -> MIME map (mirrors src/utils/mime-types.ts).
	 *
	 * @var array<string,string>
	 */
	private $mime_types = array(
		'html'  => 'text/html',
		'htm'   => 'text/html',
		'xhtml' => 'application/xhtml+xml',
		'xml'   => 'application/xml',
		'svg'   => 'image/svg+xml',
		'css'   => 'text/css',
		'js'    => 'application/javascript',
		'mjs'   => 'application/javascript',
		'json'  => 'application/json',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'ico'   => 'image/x-icon',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
		'eot'   => 'application/vnd.ms-fontobject',
		'mp3'   => 'audio/mpeg',
		'mp4'   => 'video/mp4',
		'webm'  => 'video/webm',
		'ogg'   => 'audio/ogg',
		'wav'   => 'audio/wav',
		'pdf'   => 'application/pdf',
		'txt'   => 'text/plain',
	);

	/**
	 * File-backed session store (lazily created).
	 *
	 * @var ExeLearning_Preview_Session_Store|null
	 */
	private $store;

	/**
	 * Fixed-resource resolver (lazily created).
	 *
	 * @var ExeLearning_Preview_Fixed_Resources|null
	 */
	private $fixed;

	/**
	 * Constructor. Registers routes, the cron schedule and the cleanup hook.
	 *
	 * @param ExeLearning_Preview_Session_Store|null   $store Injectable store.
	 * @param ExeLearning_Preview_Fixed_Resources|null $fixed Injectable resolver.
	 */
	public function __construct( $store = null, $fixed = null ) {
		$this->store = $store;
		$this->fixed = $fixed;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cleanup' ) );
	}

	/**
	 * The session store (constructed on first use).
	 *
	 * @return ExeLearning_Preview_Session_Store
	 */
	private function store() {
		if ( null === $this->store ) {
			$this->store = new ExeLearning_Preview_Session_Store();
		}
		return $this->store;
	}

	/**
	 * The fixed-resource resolver (constructed on first use).
	 *
	 * @return ExeLearning_Preview_Fixed_Resources
	 */
	private function fixed() {
		if ( null === $this->fixed ) {
			$this->fixed = new ExeLearning_Preview_Fixed_Resources();
		}
		return $this->fixed;
	}

	/**
	 * Register the authless serving route plus the authenticated management API.
	 */
	public function register_routes() {
		// Serving: GET /preview/{previewId}/{file}  (authless capability URL).
		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')(?:/(?P<file>.*))?',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve_preview' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'previewId' => array(
						'required' => true,
						'type'     => 'string',
					),
					'file'      => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'index.html',
					),
				),
			)
		);

		// Management: create a session.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		// Management: upload assets to a session.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')/assets',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_assets' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		// Management: publish a revision.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')/revisions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish_revision' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		// Management: delete a session.
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<previewId>' . self::PREVIEW_ID_ROUTE . ')',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_session' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);
	}

	// =========================================================================
	// Management API (authenticated + owner-scoped)
	// =========================================================================

	/**
	 * Capability gate for the management API. Ownership is enforced per session
	 * inside each handler (mirrors the reference getOwnedSession).
	 *
	 * @return bool
	 */
	public function check_manage_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * POST /preview-session — create a session for the current user.
	 *
	 * @param WP_REST_Request $request Request (unused).
	 * @return WP_REST_Response
	 */
	public function create_session( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the REST API.
		$result = $this->store()->create_session( get_current_user_id() );
		return new WP_REST_Response(
			array(
				'previewId'       => $result['previewId'],
				'protocolVersion' => self::PROTOCOL_VERSION,
				'revision'        => 0,
				'limits'          => $this->store()->get_limits(),
			),
			201
		);
	}

	/**
	 * POST /preview-session/{id}/assets — multipart `assets` JSON + `files[]`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function upload_assets( $request ) {
		$preview_id = (string) $request->get_param( 'previewId' );
		$owned      = $this->store()->get_owned_session( $preview_id, get_current_user_id() );
		if ( isset( $owned['status'] ) ) {
			return $this->owned_error( $owned['status'] );
		}

		$entries = $this->decode_json_field( $request->get_param( 'assets' ) );
		$invalid = $this->validate_asset_entries( $entries );
		if ( null !== $invalid ) {
			return $invalid;
		}

		$files = $this->normalize_files( $request );
		if ( count( $files ) !== count( $entries ) ) {
			return $this->error_response( 400, 'assets and files must be index-aligned' );
		}
		$parts_error = $this->check_uploaded_parts( $files );
		if ( null !== $parts_error ) {
			return $parts_error;
		}
		$budget_error = $this->check_declared_asset_budget( $entries, $owned['meta'] );
		if ( null !== $budget_error ) {
			return $budget_error;
		}

		$result = $this->store()->store_assets( $preview_id, $this->build_asset_store_entries( $entries, $files ) );
		if ( isset( $result['status'] ) ) {
			return $this->owned_error( $result['status'] );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Validate the `assets` field is an array of `{ key, size }` entries.
	 *
	 * @param mixed $entries Decoded `assets` field.
	 * @return WP_REST_Response|null Error response, or null when valid.
	 */
	private function validate_asset_entries( $entries ) {
		if ( ! is_array( $entries ) ) {
			return $this->error_response( 400, 'Invalid assets JSON' );
		}
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['key'] ) || ! is_string( $entry['key'] )
				|| ! isset( $entry['size'] ) || ! is_numeric( $entry['size'] ) ) {
				return $this->error_response( 400, 'assets must be an array of { key, size } entries' );
			}
		}
		return null;
	}

	/**
	 * Two-stage byte budget: reject on the DECLARED sizes before the store
	 * touches the bytes, so an under-reported size cannot amplify storage.
	 *
	 * @param array $entries `{ key, size }` entries.
	 * @param array $meta    Session meta.
	 * @return WP_REST_Response|null Error response, or null when within budget.
	 */
	private function check_declared_asset_budget( $entries, $meta ) {
		$remaining = ExeLearning_Preview_Session_Store::MAX_BYTES_PER_SESSION
			- ( (int) $meta['documentBytes'] + (int) $meta['assetBytes'] );
		$declared = 0;
		foreach ( $entries as $entry ) {
			$declared += (int) $entry['size'];
			if ( $declared > $remaining ) {
				return $this->error_response( 413, 'Upload exceeds the preview session byte budget' );
			}
		}
		return null;
	}

	/**
	 * Pair the JSON entries with their index-aligned upload temp paths for the
	 * store.
	 *
	 * @param array $entries `{ key, size }` entries.
	 * @param array $files   Normalized upload parts.
	 * @return array<int,array{key:string,declaredSize:int,tmp_path:string}>
	 */
	private function build_asset_store_entries( $entries, $files ) {
		$store_entries = array();
		foreach ( $entries as $i => $entry ) {
			$store_entries[] = array(
				'key'          => (string) $entry['key'],
				'declaredSize' => (int) $entry['size'],
				'tmp_path'     => $files[ $i ]['tmp_path'],
			);
		}
		return $store_entries;
	}

	/**
	 * POST /preview-session/{id}/revisions — multipart `revision` JSON meta +
	 * `files[]` index-aligned with `writes`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function publish_revision( $request ) {
		$preview_id = (string) $request->get_param( 'previewId' );
		$owned      = $this->store()->get_owned_session( $preview_id, get_current_user_id() );
		if ( isset( $owned['status'] ) ) {
			return $this->owned_error( $owned['status'] );
		}

		$validated = $this->validate_revision_meta( $this->decode_json_field( $request->get_param( 'revision' ) ) );
		if ( isset( $validated['error'] ) ) {
			return $validated['error'];
		}

		$files = $this->normalize_files( $request );
		if ( count( $files ) !== count( $validated['writes'] ) ) {
			return $this->error_response( 400, 'revision writes and files must be index-aligned' );
		}
		$parts_error = $this->check_uploaded_parts( $files );
		if ( null !== $parts_error ) {
			return $parts_error;
		}

		$buffered = $this->buffer_revision_writes( $validated['writes'], $files, (int) $owned['meta']['assetBytes'] );
		if ( isset( $buffered['error'] ) ) {
			return $buffered['error'];
		}

		$result = $this->store()->apply_revision(
			$preview_id,
			array(
				'baseRevision' => $validated['baseRevision'],
				'nextRevision' => $validated['nextRevision'],
				'writes'       => $buffered['writes'],
				'deletes'      => array_values( $validated['deletes'] ),
				'assetRefs'    => $validated['assetRefs'],
				'fixedRefs'    => $validated['fixedRefs'],
			),
			$this->fixed()
		);

		return $this->revision_response( $result );
	}

	/**
	 * Validate and shape the `revision` meta. Returns `['error'=>response]` on
	 * the first malformed field, else the typed struct `['baseRevision',
	 * 'nextRevision', 'writes'(paths), 'deletes', 'assetRefs', 'fixedRefs']`.
	 *
	 * @param mixed $meta Decoded `revision` field.
	 * @return array
	 */
	private function validate_revision_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return array( 'error' => $this->error_response( 400, 'revision must be a JSON object' ) );
		}
		if ( ! $this->is_intish( $meta, 'baseRevision' ) || ! $this->is_intish( $meta, 'nextRevision' ) ) {
			return array( 'error' => $this->error_response( 400, 'baseRevision and nextRevision must be integers' ) );
		}
		$collections = $this->validate_revision_collections( $meta );
		if ( isset( $collections['error'] ) ) {
			return $collections;
		}
		return array(
			'baseRevision' => (int) $meta['baseRevision'],
			'nextRevision' => (int) $meta['nextRevision'],
			'writes'       => $collections['writes'],
			'deletes'      => $collections['deletes'],
			'assetRefs'    => $collections['assetRefs'],
			'fixedRefs'    => $collections['fixedRefs'],
		);
	}

	/**
	 * Validate the array/map fields of the revision meta (writes, deletes,
	 * assetRefs, fixedRefs). Returns `['error'=>response]` on the first bad
	 * field, else the four collections defaulted to empty when absent.
	 *
	 * @param array $meta Revision meta (already an array).
	 * @return array
	 */
	private function validate_revision_collections( $meta ) {
		$writes = isset( $meta['writes'] ) ? $meta['writes'] : array();
		if ( ! $this->is_string_list( $writes ) ) {
			return array( 'error' => $this->error_response( 400, 'writes must be an array of paths' ) );
		}
		$deletes = isset( $meta['deletes'] ) ? $meta['deletes'] : array();
		if ( ! $this->is_string_list( $deletes ) ) {
			return array( 'error' => $this->error_response( 400, 'deletes must be an array of paths' ) );
		}
		$asset_refs = isset( $meta['assetRefs'] ) ? $meta['assetRefs'] : array();
		$fixed_refs = isset( $meta['fixedRefs'] ) ? $meta['fixedRefs'] : array();
		if ( ! $this->is_string_map( $asset_refs ) || ! $this->is_string_map( $fixed_refs ) ) {
			return array( 'error' => $this->error_response( 400, 'assetRefs and fixedRefs must map paths to string ids' ) );
		}
		return array(
			'writes'    => $writes,
			'deletes'   => $deletes,
			'assetRefs' => $asset_refs,
			'fixedRefs' => $fixed_refs,
		);
	}

	/**
	 * Buffer guard: pair each write path with its upload, rejecting before the
	 * store materializes anything if the document payload alone cannot fit the
	 * session budget. Returns `['error'=>response]` or `['writes'=>wire]`.
	 *
	 * @param string[] $writes      Write paths (index-aligned with `$files`).
	 * @param array    $files       Normalized upload parts.
	 * @param int      $asset_bytes Session asset bytes already stored.
	 * @return array
	 */
	private function buffer_revision_writes( $writes, $files, $asset_bytes ) {
		$remaining   = ExeLearning_Preview_Session_Store::MAX_BYTES_PER_SESSION - $asset_bytes;
		$buffered    = 0;
		$writes_wire = array();
		foreach ( $writes as $i => $path ) {
			$buffered += $files[ $i ]['size'];
			if ( $buffered > $remaining ) {
				return array( 'error' => $this->error_response( 413, 'Revision exceeds the preview session byte budget' ) );
			}
			$writes_wire[] = array(
				'path'     => (string) $path,
				'tmp_path' => $files[ $i ]['tmp_path'],
			);
		}
		return array( 'writes' => $writes_wire );
	}

	/**
	 * DELETE /preview-session/{id} — remove the owner's session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_session( $request ) {
		$preview_id = (string) $request->get_param( 'previewId' );
		$owned      = $this->store()->get_owned_session( $preview_id, get_current_user_id() );
		if ( isset( $owned['status'] ) ) {
			return $this->owned_error( $owned['status'] );
		}
		$this->store()->delete_session( $preview_id );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Map an apply_revision result to its REST response.
	 *
	 * @param array $result Store result.
	 * @return WP_REST_Response
	 */
	private function revision_response( $result ) {
		if ( isset( $result['active'] ) ) {
			return new WP_REST_Response(
				array(
					'revision' => $result['revision'],
					'active'   => true,
				),
				200
			);
		}
		$status = (int) $result['status'];
		if ( 404 === $status ) {
			return $this->owned_error( 404 );
		}
		if ( 409 === $status ) {
			return new WP_REST_Response(
				array(
					'reason'          => 'revision-conflict',
					'currentRevision' => $result['currentRevision'],
				),
				409
			);
		}
		if ( 422 === $status ) {
			unset( $result['status'] );
			return new WP_REST_Response( $result, 422 );
		}
		return $this->error_response( $status, isset( $result['message'] ) ? $result['message'] : 'Request failed' );
	}

	/**
	 * Build the 403/404 body for a management request the caller does not own.
	 *
	 * @param int $status 403 or 404.
	 * @return WP_REST_Response
	 */
	private function owned_error( $status ) {
		$message = 403 === (int) $status ? 'Access denied' : 'Preview session not found';
		return $this->error_response( (int) $status, $message );
	}

	/**
	 * A `{ success:false, error }` response with a status.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Error message.
	 * @return WP_REST_Response
	 */
	private function error_response( $status, $message ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'error'   => $message,
			),
			$status
		);
	}

	/**
	 * Decode a multipart JSON field that may arrive as a string or pre-parsed.
	 *
	 * @param mixed $raw Field value.
	 * @return mixed Decoded value (array on success), or null.
	 */
	private function decode_json_field( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		return json_decode( $raw, true );
	}

	/**
	 * Whether `$arr[$key]` is an integer (or an integer-valued string/float).
	 *
	 * @param array  $arr Source array.
	 * @param string $key Key.
	 * @return bool
	 */
	private function is_intish( $arr, $key ) {
		if ( ! isset( $arr[ $key ] ) || ! is_numeric( $arr[ $key ] ) ) {
			return false;
		}
		return (float) $arr[ $key ] === floor( (float) $arr[ $key ] );
	}

	/**
	 * Whether a value is a list of strings.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_string_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is a map whose values are all strings.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_string_map( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize `$_FILES['files']` into an index-aligned list of
	 * `{ name, tmp_path, size, error }`, whether one or many `files[]` parts
	 * arrived.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int,array{name:string,tmp_path:string,size:int,error:int}>
	 */
	private function normalize_files( $request ) {
		$params = $request->get_file_params();
		if ( empty( $params['files'] ) ) {
			return array();
		}
		$files = $params['files'];
		$out   = array();
		if ( is_array( $files['tmp_name'] ) ) {
			$count = count( $files['tmp_name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				$out[] = array(
					'name'     => isset( $files['name'][ $i ] ) ? (string) $files['name'][ $i ] : '',
					'tmp_path' => (string) $files['tmp_name'][ $i ],
					'size'     => (int) $files['size'][ $i ],
					'error'    => (int) $files['error'][ $i ],
				);
			}
		} else {
			$out[] = array(
				'name'     => isset( $files['name'] ) ? (string) $files['name'] : '',
				'tmp_path' => (string) $files['tmp_name'],
				'size'     => (int) $files['size'],
				'error'    => (int) $files['error'],
			);
		}
		return $out;
	}

	/**
	 * Reject the whole batch when any uploaded `files[]` part did not arrive
	 * intact, BEFORE the store runs — so a truncated or oversized document part
	 * can never be substituted with an empty file and published as a 0-byte
	 * document. A part that over-ran the PHP/form size limit is a 413; any other
	 * upload error, or an absent/unreadable temp file, is a 400. The message
	 * names the offending index and filename.
	 *
	 * @param array $files Normalized parts from {@see normalize_files}.
	 * @return WP_REST_Response|null Error response, or null when every part is OK.
	 */
	private function check_uploaded_parts( $files ) {
		foreach ( $files as $i => $file ) {
			$error = $file['error'];
			$name  = '' !== $file['name'] ? $file['name'] : ( 'part #' . $i );
			if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
				return $this->error_response(
					413,
					sprintf( 'Upload part #%1$d (%2$s) exceeds the server upload size limit', $i, $name )
				);
			}
			if ( UPLOAD_ERR_OK !== $error ) {
				return $this->error_response(
					400,
					sprintf( 'Upload part #%1$d (%2$s) failed to upload (error %3$d)', $i, $name, $error )
				);
			}
			if ( '' === $file['tmp_path'] || ! is_file( $file['tmp_path'] ) || ! is_readable( $file['tmp_path'] ) ) {
				return $this->error_response(
					400,
					sprintf( 'Upload part #%1$d (%2$s) is missing or unreadable', $i, $name )
				);
			}
		}
		return null;
	}

	// =========================================================================
	// Serving route (authless capability URL)
	// =========================================================================

	/**
	 * Serve a file from a preview session, or 404.
	 *
	 * Computes the response (status, headers, body descriptor) with
	 * {@see build_serve_response}, then emits raw bytes and exits — never a
	 * WP_REST_Response — so no MIME sniffing or WordPress wrapping occurs. The
	 * pure computation is unit-tested; this emitter is the only untestable part.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return void
	 */
	public function serve_preview( $request ) {
		$response = $this->build_serve_response(
			(string) $request->get_param( 'previewId' ),
			(string) $request->get_param( 'file' ),
			array(
				'if_none_match' => $request->get_header( 'if_none_match' ),
				'range'         => $request->get_header( 'range' ),
			)
		);

		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}

		$body = $response['body'];
		if ( 'file' === $body['kind'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct streaming of preview bytes.
			readfile( $body['path'] );
		} elseif ( 'range' === $body['kind'] ) {
			$this->stream_range( $body['path'], $body['offset'], $body['length'] );
		}
		exit;
	}

	/**
	 * Compute the serving response for a capability URL: three-layer resolution,
	 * tiered Cache-Control, sandbox CSP on scriptable types (from ANY layer),
	 * and ETag / conditional (304) / single-range (206/416) handling on assets.
	 *
	 * Returns `{ status, headers[name=>value], body }`, where body is
	 * `{ kind:'file', path }`, `{ kind:'range', path, offset, length }`, or
	 * `{ kind:'none' }`. Pure and side-effect-light (it only touches the store's
	 * idle-TTL clock), so it is unit-tested directly and drives the conformance
	 * vectors.
	 *
	 * @param string $preview_id  Capability id.
	 * @param string $file        Requested path.
	 * @param array  $headers_in  `{ if_none_match, range }` request headers.
	 * @return array{status:int,headers:array<string,string>,body:array}
	 */
	public function build_serve_response( $preview_id, $file, $headers_in = array() ) {
		$lookup = $this->store()->serve_lookup(
			$preview_id,
			'' === $file ? 'index.html' : $file,
			$this->fixed()
		);
		if ( null === $lookup ) {
			return $this->not_found_response();
		}

		$mime    = $this->content_type_for( $lookup['rel'] );
		$path    = $lookup['path'];
		$size    = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing size falls back to 0.
		$headers = $this->base_headers( $mime );

		if ( 'document' === $lookup['kind'] || 'fixed' === $lookup['kind'] ) {
			$headers['Cache-Control']  = ( 'fixed' === $lookup['kind'] ) ? 'private, max-age=31536000' : 'no-store';
			$headers['Content-Length'] = (string) $size;
			return $this->serve_response( 200, $headers, $this->file_body( $path ) );
		}

		return $this->build_asset_response( $lookup, $headers, $headers_in, $path, $size );
	}

	/**
	 * Serving response for a session asset: the revalidating cache tier with an
	 * ETag, conditional (304), and single-range (206/416) handling. Split from
	 * {@see build_serve_response} to keep each method's branch count low.
	 *
	 * @param array  $lookup     Store descriptor (carries `etag`).
	 * @param array  $headers    Base headers from the caller.
	 * @param array  $headers_in Request headers (`{ if_none_match, range }`).
	 * @param string $path       Absolute asset path.
	 * @param int    $size       Asset size in bytes.
	 * @return array{status:int,headers:array<string,string>,body:array}
	 */
	private function build_asset_response( $lookup, $headers, $headers_in, $path, $size ) {
		$etag                     = $lookup['etag'];
		$headers['Cache-Control'] = 'no-cache';
		$headers['ETag']          = '"' . $etag . '"';
		$headers['Accept-Ranges'] = 'bytes';

		$in_none_match = isset( $headers_in['if_none_match'] ) ? $headers_in['if_none_match'] : null;
		if ( $this->if_none_match( $in_none_match, $etag ) ) {
			return $this->serve_response( 304, $headers, $this->none_body() );
		}

		$range = $this->parse_range( isset( $headers_in['range'] ) ? $headers_in['range'] : null, $size );
		if ( 'unsatisfiable' === $range ) {
			$headers['Content-Range'] = 'bytes */' . $size;
			return $this->serve_response( 416, $headers, $this->none_body() );
		}
		if ( null !== $range ) {
			$length                    = $range['end'] - $range['start'] + 1;
			$headers['Content-Range']  = 'bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size;
			$headers['Content-Length'] = (string) $length;
			return $this->serve_response(
				206,
				$headers,
				array(
					'kind'   => 'range',
					'path'   => $path,
					'offset' => $range['start'],
					'length' => $length,
				)
			);
		}

		$headers['Content-Length'] = (string) $size;
		return $this->serve_response( 200, $headers, $this->file_body( $path ) );
	}

	/**
	 * The hardened 404 response (its header set matches a served response so the
	 * contract's "headers on EVERY response, incl. 404" holds).
	 *
	 * @return array{status:int,headers:array<string,string>,body:array}
	 */
	private function not_found_response() {
		$headers                  = $this->base_headers( 'text/plain; charset=utf-8' );
		$headers['Cache-Control'] = 'no-store';
		return $this->serve_response( 404, $headers, $this->none_body() );
	}

	/**
	 * The canonical hardening headers plus the sandbox CSP on scriptable types.
	 * Cache-Control is added by the caller (tiered per layer).
	 *
	 * @param string $mime Real MIME type of the body.
	 * @return array<string,string>
	 */
	private function base_headers( $mime ) {
		$headers = array(
			'Content-Type'           => $mime,
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'no-referrer',
			'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), payment=()',
			// Opaque, read-only preview: any origin may load it, but NEVER with
			// credentials (no Access-Control-Allow-Credentials is ever sent).
			'Access-Control-Allow-Origin' => '*',
		);
		if ( $this->is_scriptable( $mime ) ) {
			$headers['Content-Security-Policy'] = self::SANDBOX_CSP;
		}
		return $headers;
	}

	/**
	 * Assemble a serving response tuple.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string,string>  $headers Response headers.
	 * @param array                 $body    Body descriptor.
	 * @return array{status:int,headers:array<string,string>,body:array}
	 */
	private function serve_response( $status, $headers, $body ) {
		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * A whole-file body descriptor.
	 *
	 * @param string $path Absolute file path.
	 * @return array{kind:string,path:string}
	 */
	private function file_body( $path ) {
		return array(
			'kind' => 'file',
			'path' => $path,
		);
	}

	/**
	 * An empty body descriptor (304 / 416 / 404).
	 *
	 * @return array{kind:string}
	 */
	private function none_body() {
		return array( 'kind' => 'none' );
	}

	/**
	 * Stream `$length` bytes of `$path` from `$offset` (single-range body).
	 *
	 * @param string $path   Absolute file path.
	 * @param int    $offset Start byte.
	 * @param int    $length Byte count.
	 * @return void
	 */
	private function stream_range( $path, $offset, $length ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a byte range without buffering the whole file.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return;
		}
		fseek( $handle, $offset );
		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 8192, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw preview asset bytes.
			$remaining -= strlen( $chunk );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the streamed handle.
		fclose( $handle );
	}

	/**
	 * Resolve the Content-Type for a served path, appending a UTF-8 charset to
	 * textual types (mirrors src/utils/content-path.util.ts contentTypeFor).
	 *
	 * @param string $rel Relative served path.
	 * @return string
	 */
	private function content_type_for( $rel ) {
		$clean = strtok( $rel, '?#' );
		$ext   = strtolower( pathinfo( $clean, PATHINFO_EXTENSION ) );
		$mime  = isset( $this->mime_types[ $ext ] ) ? $this->mime_types[ $ext ] : 'application/octet-stream';

		$textual = 0 === strpos( $mime, 'text/' )
			|| in_array( $ext, array( 'js', 'mjs', 'json', 'svg', 'xml' ), true );
		if ( $textual && false === strpos( $mime, 'charset' ) ) {
			$mime .= '; charset=utf-8';
		}
		return $mime;
	}

	/**
	 * Whether a MIME type can execute script and therefore needs the sandbox CSP.
	 *
	 * @param string $mime MIME type (may include parameters).
	 * @return bool
	 */
	private function is_scriptable( $mime ) {
		$base = trim( strtok( (string) $mime, ';' ) );
		return in_array( $base, self::SCRIPTABLE_TYPES, true );
	}

	/**
	 * Loose `If-None-Match` evaluation: any listed entity tag (or `*`) matches.
	 *
	 * @param string|null $header Header value.
	 * @param string      $etag   Bare entity tag (assetKey).
	 * @return bool
	 */
	private function if_none_match( $header, $etag ) {
		if ( empty( $header ) ) {
			return false;
		}
		foreach ( explode( ',', $header ) as $candidate ) {
			$cleaned = preg_replace( '/^W\//i', '', trim( $candidate ) );
			$cleaned = trim( $cleaned, '"' );
			if ( '*' === $cleaned || $cleaned === $etag ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a single-range `Range` header against a body of `$total` bytes.
	 *
	 * Returns null when no range was requested, an inclusive `{ start, end }`
	 * window when satisfiable, and the string 'unsatisfiable' otherwise
	 * (multi-range, malformed, out of bounds).
	 *
	 * @param string|null $value Header value.
	 * @param int         $total Body size.
	 * @return array{start:int,end:int}|string|null
	 */
	private function parse_range( $value, $total ) {
		if ( empty( $value ) ) {
			return null;
		}
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $value ), $m ) ) {
			return 'unsatisfiable';
		}
		$raw_start = $m[1];
		$raw_end   = $m[2];
		if ( '' === $raw_start && '' === $raw_end ) {
			return 'unsatisfiable';
		}
		if ( '' === $raw_start ) {
			$suffix = (int) $raw_end;
			if ( 0 === $suffix || 0 === $total ) {
				return 'unsatisfiable';
			}
			return array(
				'start' => max( 0, $total - $suffix ),
				'end'   => $total - 1,
			);
		}
		$start = (int) $raw_start;
		if ( $start >= $total ) {
			return 'unsatisfiable';
		}
		if ( '' === $raw_end ) {
			return array(
				'start' => $start,
				'end'   => $total - 1,
			);
		}
		$end = (int) $raw_end;
		if ( $end < $start ) {
			return 'unsatisfiable';
		}
		return array(
			'start' => $start,
			'end'   => min( $end, $total - 1 ),
		);
	}

	// =========================================================================
	// Cleanup (WP-Cron + request-time TTL in the store)
	// =========================================================================

	/**
	 * Register the custom 15-minute cron recurrence used by the sweep.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (eXeLearning preview cleanup)', 'exelearning' ),
			);
		}
		return $schedules;
	}

	/**
	 * WP-Cron callback: sweep idle preview sessions.
	 *
	 * @return void
	 */
	public function run_cleanup() {
		$this->store()->sweep_expired();
	}
}
