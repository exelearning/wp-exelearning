<?php
/**
 * Authless serving controller for the opaque HTTP preview (serving contract v2).
 *
 * Serves the eXeLearning *editor preview* of untrusted author content over HTTP
 * in an opaque origin. The capability is the unguessable previewId; an opaque
 * iframe sends no cookies, so there is deliberately no WordPress auth on this
 * surface (permission_callback __return_true, wired by ExeLearning_Preview_Proxy).
 *
 * Three-layer resolution (documents -> assetRefs->assets -> fixedRefs->manifest),
 * tiered Cache-Control, ETag/conditional/Range on assets, the verbatim sandbox
 * CSP on every scriptable type from any layer (via ExeLearning_Preview_Http_Headers),
 * a bare-root 302 to index.html, and the hardened 404.
 *
 * MIRRORS eXe core: src/services/preview-serving.ts.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Serving_Controller.
 *
 * The read side of the serving contract. `build_serve_response()` is the pure,
 * unit-tested surface the conformance vectors drive; `serve_preview()` is the
 * thin byte emitter around it.
 */
class ExeLearning_Preview_Serving_Controller {

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
	 * Shared header / MIME / CSP helper.
	 *
	 * @var ExeLearning_Preview_Http_Headers
	 */
	private $headers;

	/**
	 * Constructor.
	 *
	 * @param ExeLearning_Preview_Session_Store|null   $store   Injectable store.
	 * @param ExeLearning_Preview_Fixed_Resources|null $fixed   Injectable resolver.
	 * @param ExeLearning_Preview_Http_Headers|null    $headers Injectable helper.
	 */
	public function __construct( $store = null, $fixed = null, $headers = null ) {
		$this->store   = $store;
		$this->fixed   = $fixed;
		$this->headers = null !== $headers ? $headers : new ExeLearning_Preview_Http_Headers();
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
	 * Serve a file from a preview session, or 404 / 302.
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
	 * Compute the serving response for a capability URL: bare-root redirect,
	 * three-layer resolution, tiered Cache-Control, sandbox CSP on scriptable
	 * types (from ANY layer), and ETag / conditional (304) / single-range
	 * (206/416) handling on assets.
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
		// Bare capability URL ({previewId} or {previewId}/): never serve
		// index.html bytes here — redirect so a served page's relative
		// subresource URLs resolve against the .../index.html base.
		if ( '' === $file ) {
			return $this->redirect_to_index( $preview_id );
		}

		$lookup = $this->store()->serve_lookup( $preview_id, $file, $this->fixed() );
		if ( null === $lookup ) {
			return $this->not_found_response();
		}

		$mime    = $this->headers->content_type_for( $lookup['rel'] );
		$path    = $lookup['path'];
		$size    = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing size falls back to 0.
		$headers = $this->headers->base_headers( $mime );

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
	 * The 302 redirect from a bare capability URL to its index.html. Carries the
	 * base hardening headers (contract: headers on EVERY response) and an
	 * absolute Location so it resolves regardless of a trailing slash.
	 *
	 * @param string $preview_id Capability id.
	 * @return array{status:int,headers:array<string,string>,body:array}
	 */
	private function redirect_to_index( $preview_id ) {
		$headers                  = $this->headers->base_headers( 'text/plain; charset=utf-8' );
		$headers['Cache-Control'] = 'no-store';
		$headers['Location']      = rest_url( ExeLearning_Preview_Proxy::NAMESPACE . '/preview/' . $preview_id . '/index.html' );
		return $this->serve_response( 302, $headers, $this->none_body() );
	}

	/**
	 * The hardened 404 response (its header set matches a served response so the
	 * contract's "headers on EVERY response, incl. 404" holds).
	 *
	 * @return array{status:int,headers:array<string,string>,body:array}
	 */
	private function not_found_response() {
		$headers                  = $this->headers->base_headers( 'text/plain; charset=utf-8' );
		$headers['Cache-Control'] = 'no-store';
		return $this->serve_response( 404, $headers, $this->none_body() );
	}

	/**
	 * Assemble a serving response tuple.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<string,string> $headers Response headers.
	 * @param array                $body    Body descriptor.
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
	 * An empty body descriptor (302 / 304 / 416 / 404).
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming a byte range without buffering the whole file.
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
	 * Returns null when the range must be IGNORED and the full body served with
	 * 200 (no Range header, a malformed spec, a non-`bytes` unit, or a
	 * multi-range request), an inclusive `{ start, end }` window when a single
	 * range is satisfiable, and the string 'unsatisfiable' (-> 416) only for a
	 * syntactically valid single range that cannot be met.
	 *
	 * @param string|null $value Header value.
	 * @param int         $total Body size.
	 * @return array{start:int,end:int}|string|null
	 */
	private function parse_range( $value, $total ) {
		if ( empty( $value ) ) {
			return null;
		}
		// Malformed / multi-range / non-bytes unit: ignore -> 200 full body.
		if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $value ), $m ) ) {
			return null;
		}
		$raw_start = $m[1];
		$raw_end   = $m[2];
		if ( '' === $raw_start && '' === $raw_end ) {
			return null;
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
			// last-byte-pos < first-byte-pos is an INVALID spec: ignore -> 200.
			return null;
		}
		return array(
			'start' => $start,
			'end'   => min( $end, $total - 1 ),
		);
	}
}
