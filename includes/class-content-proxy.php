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
		$hash = $request->get_param( 'hash' );
		$file = $request->get_param( 'file' );

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
	 * Answer a request for a retired extraction hash with a temporary redirect.
	 *
	 * Runs only after the requested hash already failed with file_not_found,
	 * so successful content delivery and every validation error are
	 * untouched. A redirect is emitted only when the hash is a verified
	 * obsolete alias of exactly one attachment, that attachment's current
	 * hash is well-formed and different from the requested one (loop guard),
	 * and the equivalent file passes the same path validation inside the
	 * current extraction directory — never toward a dead or escaping target
	 * (SDD-0001).
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
		$html = $this->rewrite_relative_urls( $html, $hash, $file_path );

		// Promote whitelisted external embeds to the parent (secure mode only).
		$html = $this->inject_embed_shim( $html );

		// Send headers with the new content length.
		$this->send_headers( $mime_type, strlen( $html ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML content from trusted ELP files.
		echo $html;
	}

	/**
	 * Inject the external-embed shim into the served document (secure mode only).
	 *
	 * In secure mode the content runs opaque, so cross-origin players (YouTube,
	 * Vimeo, ...) render blank. The shim replaces each whitelisted external iframe
	 * with a placeholder and reports its geometry to the parent, which overlays the
	 * real player inline (see assets/js/exe-embed-shim.js + exe-embed-relay.js). No-op
	 * in legacy mode (content is same-origin there, so external players already work
	 * inline).
	 *
	 * @param string $html The served HTML.
	 * @return string Possibly modified HTML.
	 */
	private function inject_embed_shim( $html ) {
		if ( ! ExeLearning_Iframe_Sandbox::is_secure() ) {
			return $html;
		}

		$shim = self::embed_shim_source();
		if ( '' === $shim ) {
			return $html;
		}

		$script  = '<script id="exelearning-embed-shim">';
		$script .= $shim;
		$script .= '</script>';

		if ( false !== stripos( $html, '</body>' ) ) {
			return preg_replace( '/<\/body>/i', $script . '</body>', $html, 1 );
		}
		return $html . $script;
	}

	/**
	 * Read and cache the embed shim JavaScript source.
	 *
	 * @return string Shim source, or '' if the asset is unreadable.
	 */
	private static function embed_shim_source() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$path = EXELEARNING_PLUGIN_DIR . 'assets/js/exe-embed-shim.js';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled plugin asset.
		$source = is_readable( $path ) ? file_get_contents( $path ) : false;
		$cache  = ( false === $source ) ? '' : $source;
		return $cache;
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

		$uploads_url = self::get_uploads_url( $hash );
		$proxy_url   = self::get_proxy_url( $hash, '' );

		// Get the directory of the current CSS file for resolving relative paths.
		$current_dir = '';
		if ( ! empty( $file_path ) ) {
			$current_dir = dirname( $file_path );
			if ( '.' === $current_dir ) {
				$current_dir = '';
			}
		}

		// Rewrite url() references in CSS. SVG must go through the proxy (direct
		// uploads access to .svg is blocked); other assets are served directly.
		$css = preg_replace_callback(
			'/url\s*\(\s*["\']?(?!https?:\/\/|data:|\/\/|#)([^"\')\s]+)["\']?\s*\)/i',
			function ( $matches ) use ( $uploads_url, $proxy_url, $current_dir ) {
				$url = $matches[1];
				if ( empty( $url ) || '/' === $url[0] ) {
					return $matches[0];
				}
				// Resolve the relative URL based on current directory.
				$resolved_path = $this->resolve_relative_path( $current_dir, $url );
				$base_url      = self::is_proxied_path( $resolved_path ) ? $proxy_url : $uploads_url;
				return 'url("' . esc_url( $base_url . $resolved_path ) . '")';
			},
			$css
		);

		// Send headers with the new content length.
		$this->send_headers( $mime_type, strlen( $css ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content from trusted ELP files.
		echo $css;
	}

	/**
	 * Rewrite relative URLs in HTML content to use absolute proxy URLs.
	 * This is needed when pretty permalinks are disabled.
	 *
	 * @param string $html      HTML content.
	 * @param string $hash      Content hash.
	 * @param string $file_path Current file path relative to content root.
	 * @return string Modified HTML with absolute URLs.
	 */
	private function rewrite_relative_urls( $html, $hash, $file_path = '' ) {
		$uploads_url = self::get_uploads_url( $hash );
		$proxy_url   = self::get_proxy_url( $hash, '' );

		// Get the directory of the current file for resolving relative paths.
		$current_dir = '';
		if ( ! empty( $file_path ) ) {
			$current_dir = dirname( $file_path );
			if ( '.' === $current_dir ) {
				$current_dir = '';
			}
		}

		// Patterns for attributes that contain URLs.
		$patterns = array(
			// src attribute (images, scripts, iframes, etc.).
			'/(<[^>]+\s)(src\s*=\s*["\'])(?!https?:\/\/|data:|\/\/|#)([^"\']+)(["\'])/i',
			// href attribute (links, stylesheets).
			'/(<[^>]+\s)(href\s*=\s*["\'])(?!https?:\/\/|data:|\/\/|#|javascript:)([^"\']+)(["\'])/i',
			// poster attribute (video).
			'/(<[^>]+\s)(poster\s*=\s*["\'])(?!https?:\/\/|data:|\/\/|#)([^"\']+)(["\'])/i',
		);

		foreach ( $patterns as $pattern ) {
			$html = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $uploads_url, $proxy_url, $current_dir ) {
					$prefix    = $matches[1];
					$attr      = $matches[2];
					$url       = $matches[3];
					$end_quote = $matches[4];

					// Skip if already absolute or special.
					if ( empty( $url ) || '/' === $url[0] ) {
						return $matches[0];
					}

					// Resolve the relative URL based on current directory.
					$resolved_path = $this->resolve_relative_path( $current_dir, $url );

					// Script-capable documents (HTML/SVG/XML) go through the proxy
					// so they get hardened headers; other assets are served
					// directly from uploads.
					$base_url = self::is_proxied_path( $resolved_path ) ? $proxy_url : $uploads_url;

					return $prefix . $attr . esc_url( $base_url . $resolved_path ) . $end_quote;
				},
				$html
			);
		}

		// Also handle url() in inline styles. SVG referenced from CSS must also
		// go through the proxy (direct uploads access to .svg is blocked).
		$html = preg_replace_callback(
			'/url\s*\(\s*["\']?(?!https?:\/\/|data:|\/\/|#)([^"\')\s]+)["\']?\s*\)/i',
			function ( $matches ) use ( $uploads_url, $proxy_url, $current_dir ) {
				$url = $matches[1];
				if ( empty( $url ) || '/' === $url[0] ) {
					return $matches[0];
				}
				// Resolve the relative URL based on current directory.
				$resolved_path = $this->resolve_relative_path( $current_dir, $url );
				$base_url      = self::is_proxied_path( $resolved_path ) ? $proxy_url : $uploads_url;
				return 'url("' . esc_url( $base_url . $resolved_path ) . '")';
			},
			$html
		);

		return $html;
	}

	/**
	 * Check if a file path points to an HTML file.
	 *
	 * @param string $path File path to check.
	 * @return bool True if the path ends with .html or .htm.
	 */
	private static function is_html_path( $path ) {
		// Strip query string and fragment before checking extension.
		$clean_path = strtok( $path, '?#' );
		$extension  = strtolower( pathinfo( $clean_path, PATHINFO_EXTENSION ) );
		return 'html' === $extension || 'htm' === $extension;
	}

	/**
	 * Whether a path must be served THROUGH the proxy rather than directly from
	 * uploads. Covers script-capable document types — HTML and SVG/XML — which,
	 * if served directly from /uploads as a top-level document, would run in the
	 * WordPress origin with no CSP. The proxy serves them with hardened headers
	 * (and the .htaccess in the uploads dir blocks direct access to these types).
	 *
	 * @param string $path File path to check.
	 * @return bool
	 */
	private static function is_proxied_path( $path ) {
		$clean_path = strtok( $path, '?#' );
		$extension  = strtolower( pathinfo( $clean_path, PATHINFO_EXTENSION ) );

		// Script-capable documents are always proxied for hardened headers.
		if ( in_array( $extension, array( 'html', 'htm', 'svg', 'xml' ), true ) ) {
			return true;
		}

		// Optional asset-proxy mode (issue #53): when enabled, route every
		// package asset through the proxy so WordPress emits explicit
		// Content-Type headers, working around servers that return the wrong
		// MIME type (e.g. JavaScript served as text/plain with nosniff).
		if ( '' !== $extension && self::is_asset_proxy_enabled() ) {
			return true;
		}

		return false;
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
	 * Resolve a relative path against a base directory.
	 *
	 * @param string $base_dir Base directory path.
	 * @param string $relative_path Relative path to resolve (may contain ../).
	 * @return string Resolved path.
	 */
	private function resolve_relative_path( $base_dir, $relative_path ) {
		// If no base directory, just return the relative path.
		if ( empty( $base_dir ) ) {
			// Still need to handle ../ at the start which would be invalid.
			$relative_path = ltrim( $relative_path, './' );
			return $relative_path;
		}

		// Combine base directory with relative path.
		$combined = $base_dir . '/' . $relative_path;

		// Normalize the path by resolving . and .. components.
		$parts  = explode( '/', $combined );
		$result = array();

		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				// Go up one directory level.
				array_pop( $result );
			} else {
				$result[] = $part;
			}
		}

		return implode( '/', $result );
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
		header( 'Referrer-Policy: no-referrer' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()' );

		// CSP. HTML is the eXeLearning package's own (interactive) document, so it
		// keeps a functional policy. SVG/XML are served as images/data and must
		// NEVER execute script: a malicious uploaded .elpx could otherwise carry
		// an <svg><script> that runs in the WordPress origin when opened as a
		// top-level document. They get a locked-down, script-free policy.
		if ( false !== strpos( $mime_type, 'svg' ) || false !== strpos( $mime_type, 'xml' ) ) {
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; script-src 'none'; sandbox" );
		} elseif ( false !== strpos( $mime_type, 'text/html' ) ) {
			header( 'Content-Security-Policy: ' . $this->build_html_csp( $frame_ancestors, ExeLearning_Iframe_Sandbox::is_secure() ) );
		}

		// Cache headers - short cache for HTML, longer for assets.
		if ( false !== strpos( $mime_type, 'text/html' ) ) {
			header( 'Cache-Control: no-cache, must-revalidate' );
		} else {
			header( 'Cache-Control: public, max-age=3600' );
		}
	}

	/**
	 * Build the Content-Security-Policy for served HTML.
	 *
	 * In secure mode a `sandbox` directive is appended so the served document keeps an
	 * opaque origin even when loaded OUTSIDE the embedding iframe (opened in a new tab,
	 * or by navigating to the raw content URL). Without it, that top-level document would
	 * run author JS as the WordPress origin. The tokens mirror the secure iframe sandbox
	 * (scripts + popups, no same-origin).
	 *
	 * The strict (default) profile drops bare https: from script/img/media-src and limits
	 * frame-src to the maintained providers, so the served document cannot exfiltrate the
	 * content URL. The compatible profile re-opens img/media/script to https: for external
	 * author assets (documented weaker); see ExeLearning_Iframe_Sandbox::csp_profile().
	 *
	 * @param string $frame_ancestors The frame-ancestors source list.
	 * @param bool   $secure          Whether secure (opaque-origin) mode is active.
	 * @return string The CSP header value.
	 */
	private function build_html_csp( $frame_ancestors, $secure ) {
		$compatible = ExeLearning_Iframe_Sandbox::CSP_COMPATIBLE === ExeLearning_Iframe_Sandbox::csp_profile();
		if ( $compatible ) {
			$script_src = "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:";
			$img_src    = "img-src 'self' data: blob: https:";
			$media_src  = "media-src 'self' data: blob: https:";
			$frame_src  = "frame-src 'self' https:";
		} else {
			$providers  = 'https://www.youtube-nocookie.com https://player.vimeo.com '
				. 'https://www.dailymotion.com https://mediateca.educa.madrid.org';
			$script_src = "script-src 'self' 'unsafe-inline' 'unsafe-eval'";
			$img_src    = "img-src 'self' data: blob:";
			$media_src  = "media-src 'self' data: blob:";
			$frame_src  = "frame-src 'self' " . $providers;
		}
		$directives = array(
			"default-src 'self'",
			$script_src,
			"style-src 'self' 'unsafe-inline'",
			$img_src,
			$media_src,
			"font-src 'self' data:",
			"connect-src 'self'",
			$frame_src,
			'frame-ancestors ' . $frame_ancestors,
			"form-action 'self'",
			"base-uri 'self'",
		);
		if ( $secure ) {
			// Mirror the secure iframe sandbox tokens (ExeLearning_Iframe_Sandbox::TOKENS_SECURE).
			// allow-forms lets the form-based eXeLearning iDevices submit inside the opaque
			// sandbox; a CSP sandbox without it would block submission even though the iframe
			// attribute permits it (the effective sandbox is the intersection of both).
			$directives[] = 'sandbox allow-scripts allow-popups allow-forms';
		}
		return implode( '; ', $directives );
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
