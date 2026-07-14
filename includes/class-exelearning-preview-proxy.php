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
	const SANDBOX_CSP = "sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

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
		$this->send_headers( $file['mime'], filesize( $file['path'] ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct capability streaming.
		readfile( $file['path'] );
		exit;
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
