<?php
/**
 * Shared header / MIME / CSP helper for the opaque HTTP preview (contract v2).
 *
 * The header layer of the serving contract: the byte-identical sandbox CSP, the
 * scriptable-type set that must carry it, the extension -> MIME map, and the
 * canonical hardening headers. Extracted from the HTTP adapter so the serving
 * controller stays focused on layer resolution and range handling, and so the
 * CSP constant has a single home that can never drift from eXe core.
 *
 * MIRRORS eXe core: src/shared/security/previewSandbox.ts (previewCspHeader,
 * isScriptableDocumentType) and src/utils/mime-types.ts + content-path.util.ts.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Http_Headers.
 *
 * Stateless helper: the CSP/MIME/header knowledge shared by the serving route.
 */
class ExeLearning_Preview_Http_Headers {

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
	const SANDBOX_CSP = "sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";

	/**
	 * Document MIME types that can execute script and therefore MUST carry the
	 * sandbox CSP. Byte-identical to core's isScriptableDocumentType set —
	 * `text/xml` included (a stray text/xml document is as scriptable as
	 * application/xml, so the CSP must cover it too).
	 *
	 * @var string[]
	 */
	const SCRIPTABLE_TYPES = array(
		'text/html',
		'image/svg+xml',
		'application/xml',
		'text/xml',
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
	 * The canonical hardening headers plus the sandbox CSP on scriptable types.
	 * Cache-Control is added by the caller (tiered per layer).
	 *
	 * @param string $mime Real MIME type of the body.
	 * @return array<string,string>
	 */
	public function base_headers( $mime ) {
		$headers = array(
			'Content-Type'                => $mime,
			'X-Content-Type-Options'      => 'nosniff',
			'Referrer-Policy'             => 'no-referrer',
			'Permissions-Policy'          => 'camera=(), microphone=(), geolocation=(), payment=()',
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
	 * Resolve the Content-Type for a served path, appending a UTF-8 charset to
	 * textual types (mirrors src/utils/content-path.util.ts contentTypeFor).
	 *
	 * @param string $rel Relative served path.
	 * @return string
	 */
	public function content_type_for( $rel ) {
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
	public function is_scriptable( $mime ) {
		$base = trim( strtok( (string) $mime, ';' ) );
		return in_array( strtolower( $base ), self::SCRIPTABLE_TYPES, true );
	}
}
