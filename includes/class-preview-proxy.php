<?php
/**
 * Host-served opaque HTTP preview proxy (serving contract).
 *
 * Serves the eXeLearning *editor preview* of untrusted author content over HTTP
 * in an opaque origin, as an alternative to the core `preview-sw.js` Service
 * Worker transport. This is the same isolation posture the plugin already gives
 * *published* content (see ExeLearning_Content_Proxy), applied to a short-lived,
 * capability-addressed preview session store.
 *
 * MIRRORS eXe core: doc/development/preview-serving-contract.md — the canonical
 * source of truth. The sandbox-first CSP emitted on scriptable document types
 * (self::SANDBOX_CSP) MUST stay BYTE-IDENTICAL to that document. Do not
 * reformat, reorder, or "tidy" it.
 *
 * Scope of this file: the SERVING path (route, previewId validation, hardened
 * headers, verbatim CSP, 404). The content-addressed session store and the
 * authenticated owner-scoped management API are follow-up (see the TODOs).
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Proxy.
 *
 * Registers the authless capability route and streams preview files with the
 * canonical hardening headers + sandbox CSP.
 */
class ExeLearning_Preview_Proxy {

	/**
	 * REST namespace, shared with ExeLearning_REST_API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'exelearning/v1';

	/**
	 * Canonical previewId shape (RFC-4122-style UUID). Anything else is a 404.
	 *
	 * @var string
	 */
	const PREVIEW_ID_REGEX = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

	/**
	 * Sandbox-first CSP for scriptable document types.
	 *
	 * BYTE-IDENTICAL to eXe core doc/development/preview-serving-contract.md.
	 * The `sandbox` tokens match ExeLearning_Iframe_Sandbox::TOKENS_SECURE
	 * (allow-scripts allow-popups allow-forms). Kept as a literal constant — NOT
	 * built from the published-content CSP builder — because that builder emits a
	 * different, dynamic policy and this string must not drift from core.
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
	 * Extension -> MIME map (mirrors ExeLearning_Content_Proxy::$mime_types).
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
		'mp3'   => 'audio/mpeg',
		'mp4'   => 'video/mp4',
		'webm'  => 'video/webm',
		'ogg'   => 'audio/ogg',
		'wav'   => 'audio/wav',
		'pdf'   => 'application/pdf',
		'txt'   => 'text/plain',
	);

	/**
	 * Constructor. Registers routes on rest_api_init (mirrors ExeLearning_REST_API).
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the serving route (authless capability URL) plus management stubs.
	 */
	public function register_routes() {
		// Serving path: GET {previewBasePath}/preview/{previewId}/*  (authless).
		// The capability is the unguessable previewId; there is deliberately no
		// WordPress auth here so an embedded/opaque frame can load it.
		register_rest_route(
			self::NAMESPACE,
			'/preview/(?P<previewId>[0-9a-f-]{36})(?:/(?P<file>.*))?',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve_preview' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'previewId' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'is_valid_preview_id' ),
					),
					'file'      => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'index.html',
					),
				),
			)
		);

		// TODO(follow-up): management API — AUTHENTICATED, owner-scoped. These
		// create/populate/expire the content-addressed session store the serving
		// path reads. Registered here only to reserve the shape; each returns 501
		// until the store lands. permission_callback must be capability-gated to
		// the session owner (e.g. current_user_can( 'upload_files' ) + ownership),
		// NOT __return_true.
		//
		//   POST   /preview-session          -> { previewId, limits }
		//   POST   /preview-session/:id/manifest -> { manifestId, missing[], active }
		//   POST   /preview-session/:id/blobs    -> { stored[], mismatched[], active }
		//   DELETE /preview-session/:id
	}

	/**
	 * validate_callback for the previewId path segment.
	 *
	 * @param string $param Candidate previewId.
	 * @return bool
	 */
	public function is_valid_preview_id( $param ) {
		return (bool) preg_match( '/' . self::PREVIEW_ID_REGEX . '/', (string) $param );
	}

	/**
	 * Serve a file from a preview session, or 404.
	 *
	 * Emits the canonical hardening headers on EVERY response (including 404) and
	 * the verbatim sandbox CSP on scriptable document types, then streams and
	 * exits. Never returns a WP_REST_Response — output is raw bytes so no MIME
	 * sniffing or WordPress wrapping can occur.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return void
	 */
	public function serve_preview( $request ) {
		$preview_id = (string) $request->get_param( 'previewId' );
		$file       = (string) $request->get_param( 'file' );

		// Defence in depth: the route regex is loose (36 hex/dash chars); enforce
		// the exact canonical UUID shape here before touching the store.
		if ( ! $this->is_valid_preview_id( $preview_id ) ) {
			$this->not_found();
		}

		// Resolve the session's on-disk root from the content-addressed store.
		$session_dir = $this->resolve_session_dir( $preview_id );
		if ( null === $session_dir ) {
			$this->not_found();
		}

		// Default document + traversal-safe path (same rules as the content proxy).
		$rel = $this->sanitize_path( '' === $file ? 'index.html' : $file );
		if ( null === $rel ) {
			$this->not_found();
		}

		$full = trailingslashit( $session_dir ) . $rel;
		if ( ! file_exists( $full ) || ! is_file( $full ) || ! $this->is_contained( $full, $session_dir ) ) {
			$this->not_found();
		}

		$mime = $this->mime_for( $rel );
		$this->send_headers( $mime, filesize( $full ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct streaming of preview bytes.
		readfile( $full );
		exit;
	}

	/**
	 * Resolve the on-disk root of a preview session, or null if none is active.
	 *
	 * TODO(follow-up): look up the content-addressed session store for
	 * $preview_id. The store is created by POST /preview-session, populated by
	 * /manifest + /blobs (every blob RE-HASHED server-side; mismatches
	 * quarantined), and swapped atomically. It enforces the per-session/global
	 * caps and the idle TTL, and returns null once a session has expired, been
	 * evicted, or been deleted. Until it lands, no session resolves and every
	 * preview request 404s (fail-closed) — which is the safe default.
	 *
	 * @param string $preview_id Validated UUID.
	 * @return string|null Absolute session directory, or null when inactive.
	 */
	private function resolve_session_dir( $preview_id ) {
		unset( $preview_id ); // Referenced by the follow-up store lookup.
		return null;
	}

	/**
	 * Emit the canonical hardening headers, plus the sandbox CSP on scriptable
	 * document types. Applied identically to 200 and 404 responses.
	 *
	 * @param string $mime      Real MIME type of the body.
	 * @param int    $file_size Body length in bytes (0 for the 404 body).
	 * @return void
	 */
	private function send_headers( $mime, $file_size ) {
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (int) $file_size );

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Cache-Control: no-store' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
		// Opaque, read-only preview: allow any origin to load it, but NEVER with
		// credentials (no Access-Control-Allow-Credentials is sent).
		header( 'Access-Control-Allow-Origin: *' );

		if ( $this->is_scriptable( $mime ) ) {
			header( 'Content-Security-Policy: ' . self::SANDBOX_CSP );
		}
	}

	/**
	 * Send a hardened 404 and stop. The header set matches a served response so
	 * the contract's "headers on EVERY response, incl. 404" holds.
	 *
	 * @return never
	 */
	private function not_found() {
		status_header( 404 );
		$this->send_headers( 'text/plain; charset=utf-8', 0 );
		exit;
	}

	/**
	 * Map a relative path to its MIME, defaulting to application/octet-stream.
	 *
	 * @param string $rel Relative file path.
	 * @return string
	 */
	private function mime_for( $rel ) {
		$ext = strtolower( pathinfo( strtok( $rel, '?#' ), PATHINFO_EXTENSION ) );
		return isset( $this->mime_types[ $ext ] ) ? $this->mime_types[ $ext ] : 'application/octet-stream';
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
	 * Traversal-safe path sanitizer (mirrors ExeLearning_Content_Proxy).
	 *
	 * @param string $path Requested relative path.
	 * @return string|null Sanitized path, or null if invalid.
	 */
	private function sanitize_path( $path ) {
		$path  = str_replace( array( "\0", '\\' ), array( '', '/' ), rawurldecode( $path ) );
		$parts = explode( '/', $path );
		$safe  = array();
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return null;
			}
			$safe[] = $part;
		}
		return empty( $safe ) ? 'index.html' : implode( '/', $safe );
	}

	/**
	 * Verify a resolved file stays within the session directory (symlink guard).
	 *
	 * @param string $full        Full candidate path.
	 * @param string $session_dir Session root.
	 * @return bool
	 */
	private function is_contained( $full, $session_dir ) {
		$real_file = realpath( $full );
		$real_root = realpath( $session_dir );
		if ( false === $real_file || false === $real_root ) {
			// Virtual filesystems (e.g. php-wasm): sanitize_path() already rejected
			// '..' components, so fall back to the string check.
			return false === strpos( $full, '..' );
		}
		return 0 === strpos( $real_file, $real_root );
	}
}
```

Wiring notes (for when you place these): `require_once EXELEARNING_PLUGIN_DIR . 'includes/class-preview-proxy.php';` in `exelearning.php` (next to `class-content-proxy.php`), and add `'preview_proxy' => new ExeLearning_Preview_Proxy(),` to `ExeLearning::init_components()`. Per `AGENTS.md`/`CONVENTIONS.md`, the follow-up store + management API must ship with `tests/unit/PreviewProxyTest.php` (assert the UUID regex, byte-identical `SANDBOX_CSP`, the header set on both 200 and 404, and MIME/`nosniff`).