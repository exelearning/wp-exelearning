<?php
/**
 * Authenticated, owner-scoped management controller for the opaque HTTP preview
 * (serving contract v2).
 *
 * The four management endpoints — create a session, upload assets, publish a
 * revision, delete a session — plus the multipart / budget / upload-part
 * validation plumbing and the idle-session sweep. The capability gate
 * (current_user_can('upload_files')) is wired by ExeLearning_Preview_Proxy;
 * per-session ownership is enforced here inside each handler (mirroring the
 * reference getOwnedSession).
 *
 * The heavy lifting (atomic revisions, budgets, TTL) lives in
 * ExeLearning_Preview_Session_Store; the fixed layer in
 * ExeLearning_Preview_Fixed_Resources. This class is the HTTP adapter only.
 *
 * MIRRORS eXe core: src/routes/preview-session.ts.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Management_Controller.
 *
 * The write side of the serving contract.
 */
class ExeLearning_Preview_Management_Controller {

	/**
	 * Contract protocol version advertised by create-session.
	 *
	 * @var int
	 */
	const PROTOCOL_VERSION = 2;

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
	 * Constructor.
	 *
	 * @param ExeLearning_Preview_Session_Store|null   $store Injectable store.
	 * @param ExeLearning_Preview_Fixed_Resources|null $fixed Injectable resolver.
	 */
	public function __construct( $store = null, $fixed = null ) {
		$this->store = $store;
		$this->fixed = $fixed;
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
		$declared  = 0;
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
	 * WP-Cron callback: sweep idle preview sessions.
	 *
	 * @return void
	 */
	public function run_cleanup() {
		$this->store()->sweep_expired();
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
		$value = (float) $arr[ $key ];
		return floor( $value ) === $value;
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
}
