<?php
/**
 * Tests for ExeLearning_Preview_Http_Headers (shared CSP / MIME / header layer).
 *
 * @package Exelearning
 */

/**
 * Class PreviewHttpHeadersTest.
 *
 * @covers ExeLearning_Preview_Http_Headers
 */
class PreviewHttpHeadersTest extends WP_UnitTestCase {

	/**
	 * Byte-identical CSP from doc/development/preview-serving-contract.md.
	 *
	 * @var string
	 */
	const EXPECTED_CSP = "sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';";

	/**
	 * @var ExeLearning_Preview_Http_Headers
	 */
	private $headers;

	public function set_up() {
		parent::set_up();
		$this->headers = new ExeLearning_Preview_Http_Headers();
	}

	public function test_sandbox_csp_is_byte_identical_to_core() {
		$this->assertSame( self::EXPECTED_CSP, ExeLearning_Preview_Http_Headers::SANDBOX_CSP );
		$this->assertStringStartsWith( 'sandbox allow-scripts allow-popups allow-forms', ExeLearning_Preview_Http_Headers::SANDBOX_CSP );
	}

	public function test_scriptable_types_cover_all_document_kinds_including_text_xml() {
		// Byte-identical to core's isScriptableDocumentType set. text/xml is a
		// scriptable document type too, so it MUST be present (CSP coverage).
		$this->assertContains( 'text/xml', ExeLearning_Preview_Http_Headers::SCRIPTABLE_TYPES );

		$this->assertTrue( $this->headers->is_scriptable( 'text/html; charset=utf-8' ) );
		$this->assertTrue( $this->headers->is_scriptable( 'image/svg+xml' ) );
		$this->assertTrue( $this->headers->is_scriptable( 'application/xml' ) );
		$this->assertTrue( $this->headers->is_scriptable( 'text/xml' ) );
		$this->assertTrue( $this->headers->is_scriptable( 'application/xhtml+xml' ) );
		// Case-insensitive base match (mirrors core's toLowerCase()).
		$this->assertTrue( $this->headers->is_scriptable( 'TEXT/XML; charset=utf-8' ) );
		$this->assertFalse( $this->headers->is_scriptable( 'image/png' ) );
		$this->assertFalse( $this->headers->is_scriptable( 'text/css' ) );
	}

	public function test_base_headers_add_csp_only_on_scriptable_types() {
		$html = $this->headers->base_headers( 'text/html; charset=utf-8' );
		$this->assertSame( self::EXPECTED_CSP, $html['Content-Security-Policy'] );
		$this->assertSame( 'nosniff', $html['X-Content-Type-Options'] );
		$this->assertSame( 'no-referrer', $html['Referrer-Policy'] );
		$this->assertSame( '*', $html['Access-Control-Allow-Origin'] );

		$xml = $this->headers->base_headers( 'text/xml; charset=utf-8' );
		$this->assertSame( self::EXPECTED_CSP, $xml['Content-Security-Policy'] );

		$png = $this->headers->base_headers( 'image/png' );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $png );
	}

	public function test_content_type_appends_charset_to_textual_types() {
		$this->assertSame( 'text/html; charset=utf-8', $this->headers->content_type_for( 'index.html' ) );
		$this->assertSame( 'image/svg+xml; charset=utf-8', $this->headers->content_type_for( 'a.svg' ) );
		$this->assertSame( 'application/xml; charset=utf-8', $this->headers->content_type_for( 'a.xml' ) );
		$this->assertSame( 'application/javascript; charset=utf-8', $this->headers->content_type_for( 'a.js' ) );
		$this->assertSame( 'image/png', $this->headers->content_type_for( 'a.png' ) );
		$this->assertSame( 'application/octet-stream', $this->headers->content_type_for( 'a.bin' ) );
		// Query/fragment stripped before the extension is read.
		$this->assertSame( 'text/css; charset=utf-8', $this->headers->content_type_for( 'style.css?v=2' ) );
	}
}
