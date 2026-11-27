<?php
/**
 * Secure content proxy for eXeLearning files.
 *
 * Serves extracted eXeLearning content with security headers to prevent:
 * - XSS attacks via malicious content
 * - Clickjacking
 * - Directory traversal attacks
 * - Data exfiltration
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Content_Proxy.
 *
 * Securely serves extracted eXeLearning content through a REST API endpoint.
 */
class ExeLearning_Content_Proxy {

	/**
	 * Option name for the optional asset-proxy mode (issue #53).
	 *
	 * When truthy, every package asset (CSS, JS, fonts, images, media…) is
	 * routed through this proxy so WordPress can send explicit Content-Type
	 * headers, instead of being linked directly from the uploads directory.
	 *
	 * @var string
	 */
	const OPTION_PROXY_ASSETS = 'exelearning_proxy_assets';

	/**
	 * MIME types for common file extensions.
	 *
	 * @var array
	 */
	private $mime_types = array(
		'html'  => 'text/html',
		'htm'   => 'text/html',
		'css'   => 'text/css',
		'js'    => 'application/javascript',
		'mjs'   => 'application/javascript',
		'json'  => 'application/json',
		'xml'   => 'application/xml',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'svg'   => 'image/svg+xml',
		'webp'  => 'image/webp',
		'ico'   => 'image/x-icon',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'eot'   => 'application/vnd.ms-fontobject',
		'otf'   => 'font/otf',
		'mp3'   => 'audio/mpeg',
		'mp4'   => 'video/mp4',
		'webm'  => 'video/webm',
		'ogg'   => 'audio/ogg',
		'ogv'   => 'video/ogg',
		'wav'   => 'audio/wav',
		'pdf'   => 'application/pdf',
		'zip'   => 'application/zip',
		'txt'   => 'text/plain',
	);

	/**
	 * Base path for extracted eXeLearning content.
	 *
	 * @var string
	 */
	private $base_path;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir      = wp_upload_dir();
		$this->base_path = trailingslashit( $upload_dir['basedir'] ) . 'exelearning';
	}

	/**
	 * Serve content from extracted eXeLearning files.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function serve_content( $request ) {
		$hash = self::route_param( $request, 'hash' );
		$file = self::route_param( $request, 'file' );

		// Validate hash.
		$hash_error = $this->validate_hash( $hash );
		if ( is_wp_error( $hash_error ) ) {
			return $hash_error;
		}

		// Validate and resolve file path.
		$file_result = $this->validate_file_path( $file, $hash );
		if ( is_wp_error( $file_result ) ) {
			// Only a genuinely missing file may belong to a retired
			// extraction: known obsolete hashes redirect to the current one.
			// Invalid paths and traversal attempts keep their errors.
			if ( 'file_not_found' === $file_result->get_error_code() ) {
				$redirect = $this->maybe_redirect_stale_hash( $hash, $file, $request );
				if ( null !== $redirect ) {
					return $redirect;
				}
			}
			return $file_result;
		}

		// Serve the file.
		$this->serve_file( $file_result['full_path'], $file_result['file'], $hash );
		exit;
	}

	/**
	 * Read a route parameter, ignoring a query argument of the same name.
	 *
	 * WP_REST_Request::get_param() prefers the query string over the URL, so
	 * `.../viewer.html?file=x.pdf` would serve x.pdf instead of the page the
	 * query belongs to. On a routed request only the route counts: a missing
	 * file means the package index.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @param string          $name    Parameter name.
	 * @return mixed Route value; the merged parameter for a request built without a route.
	 */
	private static function route_param( $request, $name ) {
		$url_params = $request->get_url_params();

		if ( ! $url_params ) {
			return $request->get_param( $name );
		}

		return isset( $url_params[ $name ] ) && '' !== $url_params[ $name ] ? $url_params[ $name ] : null;
	}

	/**
	 * Answer a request for a retired extraction hash with a temporary redirect.
	 *
	 * Runs only after the requested hash already failed with file_not_found,
	 * so successful content delivery and every validation error are
	 * untouched. A redirect is emitted only when the hash is a verified
	 * obsolete alias of exactly one attachment, that attachment's current
	 * hash is well-formed and different from the requested one (loop guard),
	 * and the equivalent file passes the same path validation inside the
	 * current extraction directory — never toward a dead or escaping target.
	 * See docs/architecture/changes/68-stale-content-url-redirects/.
	 *
	 * @param string          $hash    Requested (retired) extraction hash.
	 * @param string          $file    Requested file path.
	 * @param WP_REST_Request $request Original REST request.
	 * @return WP_REST_Response|null Redirect response, or null to keep the
	 *                               original error.
	 */
	private function maybe_redirect_stale_hash( $hash, $file, $request ) {
		$aliases       = new ExeLearning_Content_Hash_Aliases();
		$attachment_id = $aliases->resolve( $hash );
		if ( ! $attachment_id ) {
			return null;
		}

		$current_hash = get_post_meta( $attachment_id, ExeLearning_Content_Hash_Aliases::CURRENT_META_KEY, true );
		if ( ! ExeLearning_Content_Hash_Aliases::is_valid_hash( $current_hash )
			|| strtolower( $current_hash ) === strtolower( $hash ) ) {
			return null;
		}

		// The destination must pass the same validation as a direct request
		// and actually exist inside the current extraction directory.
		$destination = $this->validate_file_path( $file, $current_hash );
		if ( is_wp_error( $destination ) ) {
			return null;
		}

		$location = self::get_proxy_url( $current_hash, $destination['file'] );
		$location = $this->add_preserved_query_args( $location, $request );

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $location );
		// The destination changes on every save: never cache it permanently.
		$response->header( 'Cache-Control', 'no-cache, must-revalidate' );

		return $response;
	}

	/**
	 * Append the original request's query parameters to a redirect location.
	 *
	 * Parameters are re-encoded (RFC 3986) so no raw user input reaches the
	 * Location header. The plain-permalink routing argument `rest_route` is
	 * dropped: the freshly generated target URL already carries its own
	 * routing when needed.
	 *
	 * @param string          $url     Plugin-generated redirect target.
	 * @param WP_REST_Request $request Original REST request.
	 * @return string Redirect target with preserved query parameters.
	 */
	private function add_preserved_query_args( $url, $request ) {
		$params = $request->get_query_params();
		if ( ! is_array( $params ) ) {
			return $url;
		}
		unset( $params['rest_route'] );

		if ( empty( $params ) ) {
			return $url;
		}

		$extra = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		if ( '' === $extra ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $extra;
	}

	/**
	 * Validate hash format.
	 *
	 * @param string $hash Hash to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_hash( $hash ) {
		if ( ! $hash || ! preg_match( '/^[a-f0-9]{40}$/i', $hash ) ) {
			return new WP_Error(
				'invalid_hash',
				__( 'Invalid content identifier.', 'exelearning' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * Validate and resolve file path.
	 *
	 * @param string $file File path from request.
	 * @param string $hash Content hash.
	 * @return array|WP_Error Array with 'file' and 'full_path' keys, or WP_Error.
	 */
	private function validate_file_path( $file, $hash ) {
		// Default to index.html if no file specified.
		if ( empty( $file ) ) {
			$file = 'index.html';
		}

		// Sanitize and validate file path.
		$file = $this->sanitize_path( $file );
		if ( null === $file ) {
			return new WP_Error(
				'invalid_path',
				__( 'Invalid file path.', 'exelearning' ),
				array( 'status' => 404 )
			);
		}

		// Build full file path.
		$full_path = $this->base_path . '/' . $hash . '/' . $file;

		// Check file exists and is a file.
		if ( ! file_exists( $full_path ) || ! is_file( $full_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'File not found.', 'exelearning' ),
				array( 'status' => 404 )
			);
		}

		// Verify file is within the expected directory (protection against symlink attacks).
		$real_path      = realpath( $full_path );
		$real_base_path = realpath( $this->base_path . '/' . $hash );

		if ( false === $real_path || false === $real_base_path ) {
			// realpath() may fail in virtual filesystems (e.g. WordPress Playground).
			// Fall back to string-based check: verify the path has no traversal.
			if ( false !== strpos( $file, '..' ) ) {
				return new WP_Error(
					'access_denied',
					__( 'Access denied.', 'exelearning' ),
					array( 'status' => 403 )
				);
			}
			// sanitize_path() already rejected '..' components, and file_exists passed above.
		} elseif ( 0 !== strpos( $real_path, $real_base_path ) ) {
			return new WP_Error(
				'access_denied',
				__( 'Access denied.', 'exelearning' ),
				array( 'status' => 403 )
			);
		}

		return array(
			'file'      => $file,
			'full_path' => $full_path,
		);
	}

	/**
	 * Serve the file with appropriate headers and content processing.
	 *
	 * @param string $full_path Full path to the file.
	 * @param string $file      Relative file path.
	 * @param string $hash      Content hash.
	 */
	private function serve_file( $full_path, $file, $hash ) {
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$mime_type = isset( $this->mime_types[ $extension ] ) ? $this->mime_types[ $extension ] : 'application/octet-stream';

		// For HTML files, rewrite relative URLs to absolute proxy URLs.
		if ( 'html' === $extension || 'htm' === $extension ) {
			$this->serve_html_with_base_tag( $full_path, $hash, $mime_type, $file );
			return;
		}

		// For CSS files, rewrite url() references to absolute proxy URLs.
		if ( 'css' === $extension ) {
			$this->serve_css_with_rewritten_urls( $full_path, $hash, $mime_type, $file );
			return;
		}

		// For non-HTML/CSS files, serve directly.
		$file_size = filesize( $full_path );
		$this->send_headers( $mime_type, $file_size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct output needed for streaming file content.
		readfile( $full_path );
	}

	/**
	 * Serve HTML content with a base tag injected for proper relative URL resolution.
	 *
	 * @param string $full_path Full path to the HTML file.
	 * @param string $hash      Content hash for building the base URL.
	 * @param string $mime_type MIME type for the response.
	 * @param string $file_path Relative file path within the content directory.
	 */
	private function serve_html_with_base_tag( $full_path, $hash, $mime_type, $file_path = '' ) {
		// Read HTML content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file for processing.
		$html = file_get_contents( $full_path );

		if ( false === $html ) {
			return;
		}

		// Rewrite relative URLs to absolute proxy URLs.
		// This is more robust than using a <base> tag, which does not work
		// in all environments (e.g. WordPress Playground with Service Workers).
		$html = ( new ExeLearning_Content_Url_Rewriter() )->rewrite_relative_urls( $html, $hash, $file_path );

		// Send headers with the new content length.
		$this->send_headers( $mime_type, strlen( $html ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML content from trusted ELP files.
		echo $html;
	}

	/**
	 * Serve CSS content with url() references rewritten to absolute proxy URLs.
	 * This is needed when pretty permalinks are disabled.
	 *
	 * @param string $full_path Full path to the CSS file.
	 * @param string $hash      Content hash for building the base URL.
	 * @param string $mime_type MIME type for the response.
	 * @param string $file_path Relative file path within the content directory.
	 */
	private function serve_css_with_rewritten_urls( $full_path, $hash, $mime_type, $file_path ) {
		// Read CSS content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file for processing.
		$css = file_get_contents( $full_path );

		if ( false === $css ) {
			return;
		}

		$css = ( new ExeLearning_Content_Url_Rewriter() )->rewrite_css_urls( $css, $hash, $file_path );

		// Send headers with the new content length.
		$this->send_headers( $mime_type, strlen( $css ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content from trusted ELP files.
		echo $css;
	}

	/**
	 * Whether the optional asset-proxy mode is enabled.
	 *
	 * Defaults to disabled, keeping direct uploads URLs for performance. The
	 * stored option can be overridden at runtime with the
	 * `exelearning_proxy_assets` filter, e.g. to force the mode on for a
	 * specific environment.
	 *
	 * @return bool
	 */
	public static function is_asset_proxy_enabled() {
		$enabled = (bool) get_option( self::OPTION_PROXY_ASSETS, false );

		/**
		 * Filter whether package assets are served through the WordPress proxy.
		 *
		 * @param bool $enabled Whether the asset-proxy mode is enabled.
		 */
		return (bool) apply_filters( 'exelearning_proxy_assets', $enabled );
	}

	/**
	 * Remote origins whose scripts package HTML may load.
	 *
	 * These are the video players that eXeLearning's own iDevices pull in at
	 * runtime: the YouTube IFrame API, the Vimeo player API and the EducaMadrid
	 * Mediateca player. Every other script must ship inside the package;
	 * general-purpose CDNs are deliberately left out (WordPress.org does not
	 * allow offloading scripts to them).
	 *
	 * @var string[]
	 */
	const SCRIPT_ORIGINS = array(
		'https://www.youtube.com',
		'https://www.youtube-nocookie.com',
		'https://youtu.be',
		'https://s.ytimg.com',
		'https://player.vimeo.com',
		'https://mediateca.educa.madrid.org',
	);

	/**
	 * Build the Content-Security-Policy for a package HTML document.
	 *
	 * @param string $frame_ancestors Source list for the frame-ancestors directive.
	 * @return string Policy value.
	 */
	private static function html_content_security_policy( $frame_ancestors ) {
		return implode(
			'; ',
			array(
				"default-src 'self'",
				"script-src 'self' 'unsafe-inline' 'unsafe-eval' " . implode( ' ', self::SCRIPT_ORIGINS ),
				// The package's download-source-file button rebuilds the .elpx
				// with fflate, which compresses in blob: workers. Scripts here
				// already run with 'unsafe-inline'/'unsafe-eval', so this adds
				// no capability (exelearning/exelearning#2488).
				"worker-src 'self' blob:",
				"style-src 'self' 'unsafe-inline'",
				"img-src 'self' data: blob: https:",
				"media-src 'self' data: blob: https:",
				"font-src 'self' data:",
				"connect-src 'self'",
				"frame-src 'self' https:",
				'frame-ancestors ' . $frame_ancestors,
				"form-action 'self'",
				"base-uri 'self'",
			)
		);
	}

	/**
	 * Send HTTP headers for the response.
	 *
	 * @param string $mime_type Content MIME type.
	 * @param int    $file_size File size in bytes.
	 */
	private function send_headers( $mime_type, $file_size ) {
		// Content headers.
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . $file_size );

		// When an isolated content origin is configured, the package is framed
		// cross-origin by wp-admin, so SAMEORIGIN framing rules would block it;
		// instead allow the WordPress site origin to frame this content origin.
		$content_origin  = self::content_origin();
		$site_origin     = self::site_origin();
		$frame_ancestors = '' !== $content_origin ? "'self' " . $site_origin : "'self'";

		// Security headers.
		if ( '' === $content_origin ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()' );

		// CSP. HTML is the eXeLearning package's own (interactive) document, so it
		// keeps a functional policy. SVG/XML are served as images/data and must
		// NEVER execute script: a malicious uploaded .elpx could otherwise carry
		// an <svg><script> that runs in the WordPress origin when opened as a
		// top-level document. They get a locked-down, script-free policy.
		if ( false !== strpos( $mime_type, 'svg' ) || false !== strpos( $mime_type, 'xml' ) ) {
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'none'; sandbox" );
		} elseif ( false !== strpos( $mime_type, 'text/html' ) ) {
			$csp = self::html_content_security_policy( $frame_ancestors );
			header( 'Content-Security-Policy: ' . $csp );
		}

		// Cache headers - short cache for HTML, longer for assets.
		if ( false !== strpos( $mime_type, 'text/html' ) ) {
			header( 'Cache-Control: no-cache, must-revalidate' );
		} else {
			header( 'Cache-Control: public, max-age=3600' );
		}
	}

	/**
	 * Sanitize file path to prevent directory traversal.
	 *
	 * @param string $path File path to sanitize.
	 * @return string|null Sanitized path or null if invalid.
	 */
	private function sanitize_path( $path ) {
		// Decode URL encoding.
		$path = rawurldecode( $path );

		// Remove null bytes.
		$path = str_replace( "\0", '', $path );

		// Normalize slashes.
		$path = str_replace( '\\', '/', $path );

		// Split and filter path components.
		$parts      = explode( '/', $path );
		$safe_parts = array();

		foreach ( $parts as $part ) {
			// Skip empty parts and current directory references.
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			// Reject any attempt to go up directories.
			if ( '..' === $part ) {
				return null;
			}
			$safe_parts[] = $part;
		}

		if ( empty( $safe_parts ) ) {
			return 'index.html';
		}

		return implode( '/', $safe_parts );
	}

	/**
	 * Generate a proxy URL for the given hash and file.
	 *
	 * @param string $hash Extraction hash.
	 * @param string $file File path (default: index.html).
	 * @return string|null Proxy URL or null if hash is empty.
	 */
	public static function get_proxy_url( $hash, $file = 'index.html' ) {
		if ( empty( $hash ) ) {
			return null;
		}
		return self::on_content_origin( rest_url( 'exelearning/v1/content/' . $hash . '/' . $file ) );
	}

	/**
	 * Optional isolated origin for serving untrusted package content.
	 *
	 * By default content is served from the WordPress site origin, which means a
	 * malicious .elpx's inline JS (rendered in a `allow-same-origin` iframe) runs
	 * in the WordPress origin. To fully isolate it, point a SEPARATE host
	 * (e.g. a sandbox subdomain) at this same WordPress install and return its
	 * scheme://host from the `exelearning_content_origin` filter: the content
	 * then renders same-origin to that sandbox host but cross-origin to wp-admin,
	 * so it can no longer reach the admin DOM or credentialed same-origin requests.
	 *
	 * @return string Origin like "https://sandbox.example.com", or '' for the default.
	 */
	public static function content_origin() {
		$origin = (string) apply_filters( 'exelearning_content_origin', '' );
		$origin = untrailingslashit( trim( $origin ) );
		// Only accept a bare scheme://host[:port] to avoid path/garbage injection.
		if ( '' === $origin || ! preg_match( '#^https?://[^/]+$#i', $origin ) ) {
			return '';
		}
		return $origin;
	}

	/**
	 * Rewrite a same-site URL onto the configured isolated content origin.
	 *
	 * @param string|null $url URL on the WordPress site origin.
	 * @return string|null URL on the content origin (unchanged when none is set).
	 */
	private static function on_content_origin( $url ) {
		$origin = self::content_origin();
		if ( '' === $origin || empty( $url ) ) {
			return $url;
		}
		$path  = wp_parse_url( $url, PHP_URL_PATH );
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		return $origin . ( $path ? $path : '/' ) . ( $query ? '?' . $query : '' );
	}

	/**
	 * The WordPress site origin (scheme://host[:port]) derived from home_url().
	 *
	 * @return string
	 */
	private static function site_origin() {
		$parts = wp_parse_url( home_url() );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}

	/**
	 * Generate a direct uploads URL for the given hash and file.
	 *
	 * Sub-assets (CSS, JS, images, fonts) are served directly from the uploads
	 * directory to avoid 404s on hosted environments where the web server
	 * intercepts requests with static file extensions.
	 *
	 * @param string $hash Extraction hash.
	 * @param string $file File path (default: empty).
	 * @return string Uploads URL.
	 */
	public static function get_uploads_url( $hash, $file = '' ) {
		$upload_dir = wp_upload_dir();
		$url        = trailingslashit( $upload_dir['baseurl'] ) . 'exelearning/' . $hash . '/' . $file;
		// Keep sub-assets on the same isolated origin as the document that
		// references them, so the whole package tree shares one origin.
		return self::on_content_origin( $url );
	}
}
