<?php
/**
 * Reverse proxy for remote eXeLearning editor assets.
 *
 * When the plugin has no local dist/static/ build, the editor is loaded from
 * app.exelearning.net. Sub-assets (JS, CSS, fonts, images) would be blocked
 * by CORS since that server doesn't send Access-Control-Allow-Origin headers.
 * This proxy fetches assets server-side and serves them as same-origin requests,
 * with file-based caching to avoid repeated upstream fetches.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Editor_Asset_Proxy.
 *
 * Reverse proxy with disk cache for remote editor assets.
 */
class ExeLearning_Editor_Asset_Proxy {

	/**
	 * Remote editor base URL.
	 *
	 * @var string
	 */
	private $remote_base_url = 'https://app.exelearning.net/';

	/**
	 * Cache TTL in seconds (12 hours).
	 *
	 * @var int
	 */
	private $cache_ttl = 43200;

	/**
	 * MIME types for common file extensions.
	 *
	 * @var array
	 */
	private $mime_types = array(
		'html'        => 'text/html',
		'htm'         => 'text/html',
		'css'         => 'text/css',
		'js'          => 'application/javascript',
		'json'        => 'application/json',
		'xml'         => 'application/xml',
		'png'         => 'image/png',
		'jpg'         => 'image/jpeg',
		'jpeg'        => 'image/jpeg',
		'gif'         => 'image/gif',
		'svg'         => 'image/svg+xml',
		'webp'        => 'image/webp',
		'ico'         => 'image/x-icon',
		'woff'        => 'font/woff',
		'woff2'       => 'font/woff2',
		'ttf'         => 'font/ttf',
		'eot'         => 'application/vnd.ms-fontobject',
		'otf'         => 'font/otf',
		'mp3'         => 'audio/mpeg',
		'mp4'         => 'video/mp4',
		'webm'        => 'video/webm',
		'ogg'         => 'audio/ogg',
		'wav'         => 'audio/wav',
		'map'         => 'application/json',
		'md'          => 'text/plain',
		'txt'         => 'text/plain',
		'webmanifest' => 'application/manifest+json',
	);

	/**
	 * Allowed file extensions.
	 *
	 * @var array
	 */
	private $allowed_extensions = array(
		'html',
		'css',
		'js',
		'json',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'svg',
		'webp',
		'ico',
		'woff',
		'woff2',
		'ttf',
		'eot',
		'otf',
		'mp3',
		'mp4',
		'webm',
		'ogg',
		'wav',
		'map',
		'md',
		'txt',
		'webmanifest',
	);

	/**
	 * Get the cache directory path.
	 *
	 * @return string Cache directory path.
	 */
	private function get_cache_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'exelearning/editor-cache';
	}

	/**
	 * Serve an editor asset (from cache or upstream).
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function serve_asset( $request ) {
		$path = $request->get_param( 'path' );

		// Default to index.html.
		if ( empty( $path ) ) {
			$path = 'index.html';
		}

		// Sanitize the path.
		$path = $this->sanitize_path( $path );
		if ( null === $path ) {
			return new WP_Error(
				'invalid_path',
				__( 'Invalid file path.', 'exelearning' ),
				array( 'status' => 400 )
			);
		}

		// Check extension whitelist.
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, $this->allowed_extensions, true ) ) {
			return new WP_Error(
				'forbidden_extension',
				__( 'File type not allowed.', 'exelearning' ),
				array( 'status' => 403 )
			);
		}

		// Check disk cache.
		$cache_dir  = $this->get_cache_dir();
		$cache_file = $cache_dir . '/' . $path;

		if ( $this->is_cache_valid( $cache_file ) ) {
			$this->serve_cached_file( $cache_file, $extension );
			exit;
		}

		// Fetch from upstream.
		$remote_url = $this->remote_base_url . $path;
		$response   = wp_remote_get(
			$remote_url,
			array(
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'upstream_error',
				__( 'Failed to fetch editor asset.', 'exelearning' ),
				array( 'status' => 502 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'upstream_not_found',
				__( 'Editor asset not found.', 'exelearning' ),
				array( 'status' => $status_code >= 400 ? $status_code : 404 )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		// Write to cache.
		$this->write_cache( $cache_file, $body );

		// Serve the content.
		$this->send_response( $body, $extension );
		exit;
	}

	/**
	 * Sanitize file path to prevent directory traversal.
	 *
	 * @param string $path File path to sanitize.
	 * @return string|null Sanitized path or null if invalid.
	 */
	private function sanitize_path( $path ) {
		// Remove null bytes.
		$path = str_replace( "\0", '', $path );

		// Normalize slashes.
		$path = str_replace( '\\', '/', $path );

		// Decode URL encoding.
		$path = rawurldecode( $path );

		// Split and filter path components.
		$parts      = explode( '/', $path );
		$safe_parts = array();

		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			// Reject directory traversal.
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
	 * Check if a cached file is still valid.
	 *
	 * @param string $cache_file Path to the cached file.
	 * @return bool True if cache is valid.
	 */
	private function is_cache_valid( $cache_file ) {
		if ( ! file_exists( $cache_file ) || ! is_file( $cache_file ) ) {
			return false;
		}

		$file_age = time() - filemtime( $cache_file );
		return $file_age < $this->cache_ttl;
	}

	/**
	 * Serve a file from cache.
	 *
	 * @param string $cache_file Path to the cached file.
	 * @param string $extension  File extension.
	 */
	private function serve_cached_file( $cache_file, $extension ) {
		$mime_type = isset( $this->mime_types[ $extension ] ) ? $this->mime_types[ $extension ] : 'application/octet-stream';
		$file_size = filesize( $cache_file );

		$this->send_headers( $mime_type, $file_size );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct output needed for streaming cached file.
		readfile( $cache_file );
	}

	/**
	 * Write content to cache file, creating directories as needed.
	 *
	 * @param string $cache_file Path to the cache file.
	 * @param string $content    Content to cache.
	 */
	private function write_cache( $cache_file, $content ) {
		$cache_dir = dirname( $cache_file );

		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing cache file to disk.
		file_put_contents( $cache_file, $content );
	}

	/**
	 * Send response content with headers.
	 *
	 * @param string $content   Response body.
	 * @param string $extension File extension.
	 */
	private function send_response( $content, $extension ) {
		$mime_type = isset( $this->mime_types[ $extension ] ) ? $this->mime_types[ $extension ] : 'application/octet-stream';

		$this->send_headers( $mime_type, strlen( $content ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Proxied content from trusted upstream editor.
		echo $content;
	}

	/**
	 * Send HTTP headers for the response.
	 *
	 * @param string $mime_type    Content MIME type.
	 * @param int    $content_size Content size in bytes.
	 */
	private function send_headers( $mime_type, $content_size ) {
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . $content_size );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=86400' );
	}

	/**
	 * Get the proxy base URL for editor assets.
	 *
	 * @return string Proxy base URL.
	 */
	public static function get_proxy_base_url() {
		return rest_url( 'exelearning/v1/editor-assets' );
	}

	/**
	 * Clear the editor asset cache.
	 *
	 * @return bool True if cache was cleared.
	 */
	public static function clear_cache() {
		$upload_dir = wp_upload_dir();
		$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/editor-cache';

		if ( ! is_dir( $cache_dir ) ) {
			return true;
		}

		return self::recursive_delete( $cache_dir );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool True on success.
	 */
	private static function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return true;
		}

		if ( is_file( $dir ) || is_link( $dir ) ) {
			wp_delete_file( $dir );
			return true;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			self::recursive_delete( $dir . DIRECTORY_SEPARATOR . $file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct filesystem access needed for cache cleanup.
		return rmdir( $dir );
	}
}
