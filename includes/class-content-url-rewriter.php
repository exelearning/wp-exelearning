<?php
/**
 * URL rewriting for eXeLearning package content served by the proxy.
 *
 * Package HTML and CSS reference their files with relative URLs. The content
 * proxy serves script-capable documents itself and everything else from the
 * uploads directory, so those references are rewritten here: which ones become
 * absolute proxy or uploads URLs, and which links between package pages can
 * stay relative so the package JS keeps its mode parameters across navigation.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Content_Url_Rewriter.
 *
 * Rewrites the relative URLs of package HTML and CSS for ExeLearning_Content_Proxy.
 */
class ExeLearning_Content_Url_Rewriter {

	/**
	 * Stand-in for the file path when asking for the shape of a proxy URL.
	 *
	 * @var string
	 */
	const PATH_TOKEN = 'exe-path-token';

	/**
	 * A relative url() reference: opening up to the URL, URL, closing.
	 *
	 * The URL may be quoted with literal quotes or, inside a style attribute,
	 * with the entities a browser serializes them as. The opening is possessive
	 * so a scheme right after the quote rules the reference out.
	 *
	 * @var string
	 */
	const CSS_URL_PATTERN = '/(url\s*\(\s*(?:["\']|&quot;|&apos;|&#0*39;|&#x0*27;)?+)(?![a-z][a-z0-9+.\-]*:|\/\/|#)([^"\')\s]+?)((?:["\']|&quot;|&apos;|&#0*39;|&#x0*27;)?\s*\))/i';

	/**
	 * Rewrite relative URLs in HTML content to absolute proxy or uploads URLs.
	 *
	 * Links between package pages stay relative when the document itself was
	 * requested at its path-based proxy URL (see is_path_request_for()).
	 *
	 * @param string $html      HTML content.
	 * @param string $hash      Content hash.
	 * @param string $file_path Current file path relative to content root.
	 * @return string Modified HTML.
	 */
	public function rewrite_relative_urls( $html, $hash, $file_path = '' ) {
		// dirname() returns "\" for a root file on Windows.
		$current_dir = trim( dirname( '/' . $file_path ), '/\\' );
		$context     = array(
			'uploads_url'     => ExeLearning_Content_Proxy::get_uploads_url( $hash ),
			'proxy_parts'     => self::proxy_url_parts( $hash ),
			'current_dir'     => $current_dir,
			'file_path'       => $file_path,
			// Links between package pages stay relative when the browser asked
			// for this document at its path-based proxy URL: they already resolve
			// to the proxy, and the package JS only carries its mode parameters
			// (exe-teacher, exe-presentation) across relative links. Any other
			// request URL needs them absolute, and so does a document with a
			// <base> tag, which the rewrite below moves.
			'keep_page_links' => self::is_path_request_for( $hash, $file_path ) && ! preg_match( '/<base\s/i', $html ),
			'mode_args'       => self::requested_mode_args(),
			// Levels a kept-relative link may climb before it leaves the package root.
			'depth'           => '' === $current_dir ? 0 : substr_count( $current_dir, '/' ) + 1,
		);

		// Patterns for attributes that contain URLs. Any scheme (https:, data:,
		// mailto:, tel:, javascript:, about:...) marks a URL that is not relative.
		$patterns = array(
			// src attribute (images, scripts, iframes, etc.).
			'/(<[^>]+\s)(src\s*=\s*["\'])(?![a-z][a-z0-9+.\-]*:|\/\/|#)([^"\']+)(["\'])/i',
			// href attribute (links, stylesheets).
			'/(<[^>]+\s)(href\s*=\s*["\'])(?![a-z][a-z0-9+.\-]*:|\/\/|#)([^"\']+)(["\'])/i',
			// poster attribute (video).
			'/(<[^>]+\s)(poster\s*=\s*["\'])(?![a-z][a-z0-9+.\-]*:|\/\/|#)([^"\']+)(["\'])/i',
		);

		foreach ( $patterns as $pattern ) {
			$html = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( $context ) {
					return $this->rewrite_attribute_url( $matches, $context );
				},
				$html
			);
		}

		// Also handle url() in inline styles. SVG referenced from CSS must also
		// go through the proxy (direct uploads access to .svg is blocked).
		return preg_replace_callback(
			self::CSS_URL_PATTERN,
			function ( $matches ) use ( $context ) {
				return $this->rewrite_inline_style_url( $matches, $context );
			},
			$html
		);
	}

	/**
	 * Rewrite relative url() references in a package CSS file.
	 *
	 * SVG must go through the proxy (direct uploads access to .svg is blocked);
	 * other assets are served directly.
	 *
	 * @param string $css       CSS content.
	 * @param string $hash      Content hash.
	 * @param string $file_path CSS file path relative to content root.
	 * @return string Modified CSS.
	 */
	public function rewrite_css_urls( $css, $hash, $file_path ) {
		$context = array(
			'uploads_url' => ExeLearning_Content_Proxy::get_uploads_url( $hash ),
			'proxy_parts' => self::proxy_url_parts( $hash ),
			// dirname() returns "\" for a root file on Windows.
			'current_dir' => trim( dirname( '/' . $file_path ), '/\\' ),
			// A stylesheet is not HTML: its URLs take no entities.
			'css_file'    => true,
		);

		return preg_replace_callback(
			self::CSS_URL_PATTERN,
			function ( $matches ) use ( $context ) {
				return $this->rewrite_inline_style_url( $matches, $context );
			},
			$css
		);
	}

	/**
	 * The proxy URL of a package, split around where the file path goes.
	 *
	 * The path is not always the end of the URL: plain permalinks carry it in
	 * ?rest_route=, and a rest_url filter may append its own query argument.
	 * Asking for the URL of a token shows where a path belongs in each case.
	 *
	 * @param string $hash Content hash.
	 * @return string[] URL before the file path, and after it.
	 */
	private static function proxy_url_parts( $hash ) {
		$parts = explode( self::PATH_TOKEN, (string) ExeLearning_Content_Proxy::get_proxy_url( $hash, self::PATH_TOKEN ), 2 );

		return 2 === count( $parts ) ? $parts : array( (string) ExeLearning_Content_Proxy::get_proxy_url( $hash, '' ), '' );
	}

	/**
	 * Absolute URL for a path resolved against the package root.
	 *
	 * Script-capable documents (HTML/SVG/XML) go through the proxy so they get
	 * hardened headers; other assets are served directly from uploads.
	 *
	 * @param string $resolved_path Path inside the package, with any query or fragment.
	 * @param array  $context       Rewrite context.
	 * @return string URL.
	 */
	private static function target_url( $resolved_path, $context ) {
		// The package folder itself is served by the proxy (its index); the
		// uploads directory blocks direct access.
		$is_root = 0 === strcspn( $resolved_path, '?#' );

		if ( ! $is_root && ! self::is_proxied_path( $resolved_path ) ) {
			return $context['uploads_url'] . $resolved_path;
		}

		return self::proxy_url( $resolved_path, $context );
	}

	/**
	 * Proxy URL of a package path, keeping the path's own query apart.
	 *
	 * @param string $path    Path inside the package, with any query or fragment.
	 * @param array  $context Rewrite context.
	 * @return string URL.
	 */
	private static function proxy_url( $path, $context ) {
		$path_length = strcspn( $path, '?#' );
		$file        = substr( $path, 0, $path_length );
		if ( false !== strpos( $context['proxy_parts'][0], '?' ) ) {
			// Inside ?rest_route= a literal + or & is query syntax, not part of
			// the name. Other character references (&eacute;) are left alone.
			$file = preg_replace( '/&(?:amp;|(?![a-z0-9#]+;))/i', '%26', str_replace( '+', '%2B', $file ) );
		}
		$url  = $context['proxy_parts'][0] . $file . $context['proxy_parts'][1];
		$rest = substr( $path, $path_length );

		return '?' === substr( $rest, 0, 1 ) ? self::append_query( $url, substr( $rest, 1 ) ) : $url . $rest;
	}

	/**
	 * Whether the browser requested a package file at its path-based proxy URL.
	 *
	 * Relative links only resolve to the proxy from `.../{hash}/{file}`. The
	 * route also answers `?rest_route=` URLs (plain permalinks, or links stored
	 * before pretty permalinks were enabled) and non-canonical paths (no file,
	 * a trailing or doubled slash), so the decision comes from the request URL
	 * itself, not from how the server routed it or how new URLs are built.
	 *
	 * @param string $hash      Content hash.
	 * @param string $file_path Decoded file path relative to the content root.
	 * @return bool True when relative links resolve inside the proxy.
	 */
	private static function is_path_request_for( $hash, $file_path ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never stored or output.
		$request = explode( '?', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), 2 );

		// A rest_route argument routes the request whatever its path says.
		if ( false !== strpos( '&' . ( $request[1] ?? '' ), '&rest_route=' ) ) {
			return false;
		}

		// Decoded segment by segment, as the browser reads the path: an encoded
		// slash stays inside its segment instead of passing for a directory.
		$segments = array_map( 'rawurldecode', explode( '/', $request[0] ) );
		$expected = explode( '/', $hash . '/' . $file_path );

		return array_slice( $segments, -count( $expected ) ) === $expected;
	}

	/**
	 * Rewrite one relative src/href/poster attribute matched in package HTML.
	 *
	 * @param string[] $matches Full match, tag prefix, attribute opening, URL, closing quote.
	 * @param array    $context Rewrite context built by rewrite_relative_urls().
	 * @return string Replacement for the full match.
	 */
	private function rewrite_attribute_url( $matches, $context ) {
		list( $full, $prefix, $attr, $url, $end_quote ) = $matches;

		// Skip if already absolute or special.
		if ( '' === $url || '/' === $url[0] ) {
			return $full;
		}

		if ( '?' === $url[0] ) {
			return self::rewrite_query_only_url( $matches, $context );
		}

		// Resolve the relative URL based on current directory.
		$resolved_path = $this->resolve_relative_path( $context['current_dir'], $url );

		if ( self::keeps_page_link_relative( $attr, $url, $resolved_path, $context ) ) {
			return $full;
		}

		$target = self::target_url( $resolved_path, $context );

		// The package JS skips absolute links, so the mode travels in the URL.
		if ( $context['mode_args'] && 0 === stripos( $attr, 'href' ) && self::is_html_path( $resolved_path ) ) {
			// A parameter the link sets itself wins: of a repeated name the
			// browser reads the first copy and PHP the last.
			wp_parse_str( wp_specialchars_decode( (string) wp_parse_url( $resolved_path, PHP_URL_QUERY ) ), $own_args );
			$mode_args = array_diff_key( $context['mode_args'], $own_args );
			if ( $mode_args ) {
				// Explicit separator: arg_separator.output is not always "&".
				$target = self::append_query( $target, http_build_query( $mode_args, '', '&' ) );
			}
		}

		return $prefix . $attr . esc_url( $target ) . $end_quote;
	}

	/**
	 * Append a query string to a URL, before its fragment.
	 *
	 * Unlike add_query_arg(), the existing query (e.g. ?rest_route=/a/b) is
	 * left exactly as it is.
	 *
	 * @param string $url   URL, with or without query and fragment.
	 * @param string $query Encoded query string to append, without `?`.
	 * @return string URL with the query appended.
	 */
	private static function append_query( $url, $query ) {
		$parts = explode( '#', $url, 2 );
		$url   = $parts[0] . ( false === strpos( $parts[0], '?' ) ? '?' : '&' ) . $query;

		return isset( $parts[1] ) ? $url . '#' . $parts[1] : $url;
	}

	/**
	 * Mode parameters of the current request that package pages understand.
	 *
	 * Exported packages read these from the URL (teacher layer, presentation
	 * mode, menu state) and append them to relative links themselves. Links the
	 * proxy must make absolute get them from here instead.
	 *
	 * @return array<string,string> Parameter name => simple value (1, 0, true...).
	 */
	private static function requested_mode_args() {
		$args = array();

		foreach ( array( 'exe-teacher', 'teacher-mode', 'exe-teacher-toggler', 'exe-presentation', 'nav' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only display flags.
			$value = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : null;
			if ( null !== $value && preg_match( '/^[a-z0-9]{1,5}$/i', $value ) ) {
				$args[ $name ] = $value;
			} elseif ( null !== $value && 'exe-presentation' === $name ) {
				// The package offers the control for any value, switched off.
				$args[ $name ] = '0';
			}
		}

		// The menu state belongs to presentation mode: carried without it, a
		// reader who reopened the menu would get it hidden again on every page.
		if ( ! in_array( $args['exe-presentation'] ?? '', array( '1', 'true', 'yes' ), true ) ) {
			unset( $args['nav'] );
		}

		return $args;
	}

	/**
	 * Rewrite a query-only URL, which targets the current document.
	 *
	 * It stays relative where that resolves; otherwise it points at this page
	 * through the proxy so the routing argument survives.
	 *
	 * @param string[] $matches Full match, tag prefix, attribute opening, URL, closing quote.
	 * @param array    $context Rewrite context built by rewrite_relative_urls().
	 * @return string Replacement for the full match.
	 */
	private static function rewrite_query_only_url( $matches, $context ) {
		list( $full, $prefix, $attr, $url, $end_quote ) = $matches;

		if ( $context['keep_page_links'] ) {
			return $full;
		}

		// file_path is the decoded filesystem path: re-encode it so names with
		// &, #, + or % keep addressing this page.
		$page_url = self::proxy_url( implode( '/', array_map( 'rawurlencode', explode( '/', $context['file_path'] ) ) ), $context );

		return $prefix . $attr . esc_url( self::append_query( $page_url, substr( $url, 1 ) ) ) . $end_quote;
	}

	/**
	 * Whether a link to another package page can be left relative.
	 *
	 * @param string $attr          Attribute opening, e.g. `href="`.
	 * @param string $url           URL as written in the document.
	 * @param string $resolved_path URL resolved against the package root.
	 * @param array  $context       Rewrite context built by rewrite_relative_urls().
	 * @return bool True to keep the href untouched.
	 */
	private static function keeps_page_link_relative( $attr, $url, $resolved_path, $context ) {
		return $context['keep_page_links']
			&& 0 === stripos( $attr, 'href' )
			&& self::is_html_path( $resolved_path )
			&& substr_count( '/' . $url, '/..' ) <= $context['depth'];
	}

	/**
	 * Rewrite one relative url() reference matched in an inline style or a CSS file.
	 *
	 * @param string[] $matches Full match, opening up to the URL, URL, closing.
	 * @param array    $context Rewrite context.
	 * @return string Replacement for the full match.
	 */
	private function rewrite_inline_style_url( $matches, $context ) {
		list( $full, $open, $url, $close ) = $matches;
		if ( '' === $url || '/' === $url[0] ) {
			return $full;
		}
		$target = self::target_url( $this->resolve_relative_path( $context['current_dir'], $url ), $context );

		// The author's delimiters are kept: an added double quote would end a
		// style="" attribute.
		return $open . ( empty( $context['css_file'] ) ? esc_url( $target ) : esc_url_raw( $target ) ) . $close;
	}

	/**
	 * Check if a file path points to an HTML file.
	 *
	 * @param string $path File path to check.
	 * @return bool True if the path ends with .html or .htm.
	 */
	private static function is_html_path( $path ) {
		// Strip query string and fragment before checking extension.
		$clean_path = substr( $path, 0, strcspn( $path, '?#' ) );
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
		if ( '' !== $extension && ExeLearning_Content_Proxy::is_asset_proxy_enabled() ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve a relative path against a base directory.
	 *
	 * @param string $base_dir Base directory path.
	 * @param string $relative_path Relative path to resolve (may contain ../).
	 * @return string Resolved path.
	 */
	private function resolve_relative_path( $base_dir, $relative_path ) {
		// Only the path is normalized: a query or fragment (?file=https://...,
		// #/route/) is appended verbatim.
		$path_length = strcspn( $relative_path, '?#' );
		$suffix      = substr( $relative_path, $path_length );
		$path        = substr( $relative_path, 0, $path_length );

		// Combine base directory with relative path. At the root, leading ../
		// segments pop nothing, so they stay clamped to the package root, while
		// dot-prefixed names (.hidden/) are kept.
		$combined = $base_dir . '/' . $path;

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

		$resolved = implode( '/', $result );

		// Keep the trailing slash that marks a directory.
		if ( '' !== $resolved && '/' === substr( $path, -1 ) ) {
			$resolved .= '/';
		}

		return $resolved . $suffix;
	}
}
