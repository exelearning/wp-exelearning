<?php
/**
 * Whole-snapshot opaque editor-preview routes.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers authenticated management and public capability routes.
 */
class ExeLearning_Preview_Proxy {

	/** REST namespace. */
	const NAMESPACE = 'exelearning/v1';

	/** UUIDv4 route pattern. */
	const PREVIEW_ID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

	/** Scriptable document types. */
	const SCRIPTABLE_TYPES = array( 'text/html', 'image/svg+xml', 'application/xml', 'application/xhtml+xml' );

	/** Sandbox CSP for scriptable resources. */
	const SANDBOX_CSP = "sandbox allow-scripts allow-popups allow-forms allow-downloads allow-presentation; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

	/**
	 * Snapshot store.
	 *
	 * @var ExeLearning_Preview_Snapshot_Store
	 */
	private $store;

	/**
	 * Create the preview route provider.
	 *
	 * @param ExeLearning_Preview_Snapshot_Store|null $store Store override for tests.
	 */
	public function __construct( $store = null ) {
		$this->store = $store ? $store : new ExeLearning_Preview_Snapshot_Store();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** Register the minimal whole-snapshot contract. */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<attachmentId>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'replace_preview' ),
					'permission_callback' => array( $this, 'can_manage_preview' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/preview-session/(?P<attachmentId>\d+)/(?P<previewId>' . self::PREVIEW_ID_PATTERN . ')',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_preview' ),
					'permission_callback' => array( $this, 'can_manage_preview' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<previewId>' . self::PREVIEW_ID_PATTERN . ')(?:/(?P<file>.*))?',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'serve_preview' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'file' => array( 'default' => 'index.html' ) ),
			)
		);
	}

	/**
	 * Check edit permission for the attachment in a management request.
	 *
	 * WordPress cookie authentication validates `X-WP-Nonce` before this callback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function can_manage_preview( $request ) {
		return current_user_can( 'upload_files' )
			&& current_user_can( 'edit_post', absint( $request->get_param( 'attachmentId' ) ) );
	}

	/**
	 * Store a complete uploaded snapshot.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function replace_preview( $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['snapshot'] ) ? $files['snapshot'] : null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE )
			|| empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'missing_snapshot', 'Missing preview snapshot.', array( 'status' => 400 ) );
		}
		$attachment_id = absint( $request->get_param( 'attachmentId' ) );
		$preview_id    = sanitize_text_field( (string) $request->get_param( 'previewId' ) );
		$result        = $this->store->replace(
			get_current_user_id(),
			$attachment_id,
			$file['tmp_name'],
			$preview_id ? $preview_id : null
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 'preview_forbidden' === $result->get_error_code() ? 403 : 400 ) );
			return $result;
		}
		return new WP_REST_Response(
			array(
				'previewId'  => $result,
				'previewUrl' => rest_url( self::NAMESPACE . '/preview/' . $result . '/index.html' ),
			),
			200
		);
	}

	/**
	 * Delete an owner-scoped snapshot.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_preview( $request ) {
		$result = $this->store->delete(
			get_current_user_id(),
			absint( $request->get_param( 'attachmentId' ) ),
			(string) $request->get_param( 'previewId' )
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}
		return new WP_REST_Response( null, $result ? 204 : 404 );
	}

	/**
	 * Stream a public capability file with hardening headers.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return never
	 */
	public function serve_preview( $request ) {
		$file = $this->store->get(
			(string) $request->get_param( 'previewId' ),
			(string) $request->get_param( 'file' )
		);
		if ( null === $file ) {
			$this->not_found();
		}

		// A scriptable document is rewritten on every opaque refresh, so it is
		// never cached and always sent whole.
		$type = strtolower( trim( strtok( $file['mime'], ';' ) ) );
		if ( in_array( $type, self::SCRIPTABLE_TYPES, true ) ) {
			$this->send_headers( $file['mime'], $file['size'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct capability streaming.
			readfile( $file['path'] );
			exit;
		}

		$this->serve_asset( $file );
	}

	/**
	 * Serve a non-scriptable file: revalidating tier with an ETag, plus Range,
	 * which is what lets a video or audio track inside the snapshot seek instead
	 * of re-downloading from the start.
	 *
	 * Nothing is read until it is known what will be sent: a 304 and a 416 send
	 * no body at all, and a 206 streams only its window.
	 *
	 * @param array $file Store descriptor with path, mime, size and etag.
	 * @return never
	 */
	private function serve_asset( $file ) {
		$etag  = (string) $file['etag'];
		$total = (int) $file['size'];

		if ( self::if_none_match_matches( self::request_header( 'HTTP_IF_NONE_MATCH' ), $etag ) ) {
			status_header( 304 );
			$this->send_asset_headers( $file['mime'], null, $etag );
			exit;
		}

		$range = self::parse_range( self::request_header( 'HTTP_RANGE' ), $total );
		if ( 'unsatisfiable' === $range ) {
			status_header( 416 );
			$this->send_asset_headers( $file['mime'], null, $etag );
			header( 'Content-Range: bytes */' . $total );
			exit;
		}
		if ( is_array( $range ) ) {
			$length = $range['end'] - $range['start'] + 1;
			status_header( 206 );
			$this->send_asset_headers( $file['mime'], $length, $etag );
			header( 'Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $total );
			$this->stream_slice( $file['path'], $range['start'], $length );
			exit;
		}

		$this->send_asset_headers( $file['mime'], $total, $etag );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct capability streaming.
		readfile( $file['path'] );
		exit;
	}

	/**
	 * Stream a byte window without loading the rest of the file.
	 *
	 * @param string $path   Absolute file path.
	 * @param int    $start  First byte to send.
	 * @param int    $length Number of bytes to send.
	 */
	private function stream_slice( $path, $start, $length ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return;
		}
		if ( 0 !== fseek( $handle, $start ) ) {
			fclose( $handle );
			return;
		}
		$remaining = $length;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 131072, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$remaining -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary capability payload.
		}
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread
	}

	/**
	 * Read a request header from the server superglobal.
	 *
	 * @param string $key Superglobal key, e.g. HTTP_RANGE.
	 * @return string|null
	 */
	private static function request_header( $key ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared literally, never echoed.
		$value = isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : null;
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Loose If-None-Match evaluation: any listed entity tag (or `*`) matches.
	 *
	 * @param string|null $header Raw If-None-Match value.
	 * @param string      $etag   Entity tag without quotes.
	 * @return bool
	 */
	public static function if_none_match_matches( $header, $etag ) {
		if ( ! is_string( $header ) || '' === $header ) {
			return false;
		}
		foreach ( explode( ',', $header ) as $candidate ) {
			$cleaned = trim( $candidate );
			$cleaned = preg_replace( '/^W\//i', '', $cleaned );
			$cleaned = trim( (string) $cleaned, '"' );
			if ( '*' === $cleaned || $cleaned === $etag ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a single HTTP byte range against a known size.
	 *
	 * Three outcomes, matching the serving contract:
	 *  - null: no Range, or one this server does not honour as a partial request
	 *    (a non-bytes unit, a multi-range list, garbage, or a syntactically
	 *    INVALID spec such as `bytes=5-2`). All are ignored and answered with a
	 *    normal 200, never a 416.
	 *  - 'unsatisfiable': a VALID single range that cannot be met, i.e. a
	 *    first-byte-pos at or past EOF or a zero-length suffix. Only this is 416.
	 *  - array{start:int,end:int}: an inclusive, satisfiable window (206).
	 *
	 * @param string|null $header Raw Range header value.
	 * @param int         $total  Entity size in bytes.
	 * @return array|string|null
	 */
	public static function parse_range( $header, $total ) {
		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $header ), $matches ) ) {
			return null;
		}
		$raw_start = $matches[1];
		$raw_end   = $matches[2];
		if ( '' === $raw_start && '' === $raw_end ) {
			return null;
		}
		return '' === $raw_start
			? self::suffix_range( (int) $raw_end, $total )
			: self::offset_range( (int) $raw_start, $raw_end, $total );
	}

	/**
	 * Resolve a suffix range (`bytes=-N`): the last N bytes.
	 *
	 * @param int $suffix Requested suffix length.
	 * @param int $total  Entity size in bytes.
	 * @return array|string
	 */
	private static function suffix_range( $suffix, $total ) {
		if ( 0 === $suffix || 0 === $total ) {
			return 'unsatisfiable';
		}
		return array(
			'start' => max( 0, $total - $suffix ),
			'end'   => $total - 1,
		);
	}

	/**
	 * Resolve a range anchored at a first-byte-pos (`bytes=N-` or `bytes=N-M`).
	 *
	 * @param int    $start   First byte requested.
	 * @param string $raw_end Raw last-byte-pos, empty when open ended.
	 * @param int    $total   Entity size in bytes.
	 * @return array|string|null
	 */
	private static function offset_range( $start, $raw_end, $total ) {
		if ( '' !== $raw_end && (int) $raw_end < $start ) {
			// last-byte-pos below first-byte-pos is an invalid spec, so the header
			// is ignored and a full 200 is served rather than a 416.
			return null;
		}
		if ( $start >= $total ) {
			return 'unsatisfiable';
		}
		return array(
			'start' => $start,
			'end'   => '' === $raw_end ? $total - 1 : min( (int) $raw_end, $total - 1 ),
		);
	}

	/**
	 * Send a hardened not-found response.
	 *
	 * @return never
	 */
	private function not_found() {
		status_header( 404 );
		$this->send_headers( 'text/plain; charset=utf-8', 9 );
		echo 'Not found'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant plain text.
		exit;
	}

	/**
	 * Send hardening headers for the revalidating tier.
	 *
	 * Content-Length is omitted for a 304 and a 416, which carry no body.
	 *
	 * @param string   $mime MIME type.
	 * @param int|null $size Body length, or null when there is no body.
	 * @param string   $etag Entity tag without quotes.
	 */
	private function send_asset_headers( $mime, $size, $etag ) {
		header( 'Content-Type: ' . $mime );
		if ( null !== $size ) {
			header( 'Content-Length: ' . (int) $size );
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Cache-Control: no-cache' );
		header( 'ETag: "' . $etag . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
		header( 'Access-Control-Allow-Origin: *' );
	}

	/**
	 * Send response hardening headers.
	 *
	 * @param string $mime MIME type.
	 * @param int    $size Content length.
	 */
	private function send_headers( $mime, $size ) {
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (int) $size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Cache-Control: no-store' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
		header( 'Access-Control-Allow-Origin: *' );
		$type = strtolower( trim( strtok( $mime, ';' ) ) );
		if ( in_array( $type, self::SCRIPTABLE_TYPES, true ) ) {
			header( 'Content-Security-Policy: ' . self::SANDBOX_CSP );
		}
	}
}
