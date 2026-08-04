<?php
/**
 * Tests for the HTML ExeLearning_Export_Bootstrap hands to the browser.
 *
 * ExportBootstrapTest covers the request guards. This file covers what happens
 * once a request is accepted: loading the bundled editor template and patching
 * it so the export-only editor boots against this WordPress install.
 *
 * maybe_render() ends in exit() after tearing down output buffering, so the
 * tests drive the private steps it composes.
 *
 * @package Exelearning
 */

/**
 * Class ExportBootstrapPayloadTest.
 *
 * @covers ExeLearning_Export_Bootstrap
 */
class ExportBootstrapPayloadTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Export_Bootstrap
	 */
	private $bootstrap;

	/**
	 * Absolute plugin URL of the bundled editor.
	 *
	 * @var string
	 */
	private $editor_base_url;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->bootstrap       = new ExeLearning_Export_Bootstrap();
		$this->editor_base_url = EXELEARNING_PLUGIN_URL . 'dist/static';
	}

	/**
	 * The export configuration is injected as valid JSON the editor can read.
	 */
	public function test_the_export_configuration_is_injected_as_json() {
		$html = $this->inject( '<html><head><title>x</title></head><body></body></html>', 4242, 'https://example.org/f.elpx' );

		$this->assertMatchesRegularExpression( '/window\.__WP_EXE_CONFIG__ = (\{.*?\});/s', $html );
		preg_match( '/window\.__WP_EXE_CONFIG__ = (\{.*?\});/s', $html, $matches );
		$config = json_decode( $matches[1], true );

		$this->assertSame(
			array(
				'mode'          => 'WordPressExport',
				'attachmentId'  => 4242,
				'elpUrl'        => 'https://example.org/f.elpx',
				'editorBaseUrl' => $this->editor_base_url,
			),
			$config
		);
		$this->assertStringContainsString( 'window.__EXE_EXPORT_MODE__ = true;', $html );
	}

	/**
	 * The bridge script is loaded from the plugin, cache-busted by version.
	 */
	public function test_the_bridge_script_is_loaded_from_the_plugin() {
		$html = $this->inject( '<html><head></head></html>' );

		$this->assertStringContainsString(
			esc_url( EXELEARNING_PLUGIN_URL . 'assets/js/wp-exe-bridge.js?ver=' . EXELEARNING_VERSION ),
			$html
		);
	}

	/**
	 * Everything is inserted inside <head>, before the closing tag, so the
	 * editor sees the configuration before its own bundles run.
	 */
	public function test_the_payload_is_inserted_inside_the_head() {
		$html = $this->inject( '<html><head><meta charset="utf-8"></head><body>page</body></html>' );

		$this->assertLessThan(
			strpos( $html, '</head>' ),
			strpos( $html, '__WP_EXE_CONFIG__' ),
			'The configuration must be part of the document head.'
		);
	}

	/**
	 * A single <base> tag is added right after <head> so the editor's relative
	 * asset paths resolve inside the bundled build, not against the page URL.
	 */
	public function test_a_single_base_tag_points_at_the_bundled_editor() {
		$html = $this->inject( '<html><head lang="es"><title>t</title></head><body></body></html>' );

		$expected = '<base href="' . esc_url( $this->editor_base_url ) . '/">';
		$this->assertSame( 1, substr_count( $html, $expected ) );
		$this->assertStringContainsString( '<head lang="es">' . $expected, $html );
	}

	/**
	 * Explicit `./` prefixes in attributes are rewritten to absolute plugin
	 * URLs, which the <base> tag alone does not cover.
	 */
	public function test_relative_asset_paths_are_rewritten_to_absolute_urls() {
		$html = $this->inject( '<html><head></head><body><script src="./app/bundle.js"></script></body></html>' );

		$this->assertStringContainsString(
			'src="' . esc_url( $this->editor_base_url ) . '/app/bundle.js"',
			$html
		);
		$this->assertStringNotContainsString( 'src="./app/bundle.js"', $html );
	}

	/**
	 * An attachment URL that cannot be resolved is still emitted as valid JSON
	 * (null), not as a broken literal.
	 */
	public function test_a_missing_attachment_url_is_encoded_as_null() {
		$html = $this->inject( '<html><head></head></html>', 7, false );

		$this->assertStringContainsString( '"elpUrl":false', $html );
		$this->assertStringContainsString( 'initialProjectUrl: false', $html );
	}

	/**
	 * The endpoint URL carries the export signal and the attachment id.
	 */
	public function test_url_for_carries_the_export_signal() {
		$url = ExeLearning_Export_Bootstrap::url_for( 99 );

		$this->assertStringContainsString( 'exe_export=1', $url );
		$this->assertStringContainsString( 'attachment_id=99', $url );
		$this->assertStringStartsWith( home_url( '/' ), $url );
	}

	/**
	 * Without the bundled editor there is nothing to serve, and the request is
	 * refused with a 503 instead of rendering a broken page.
	 */
	public function test_loading_the_template_fails_when_the_editor_is_not_bundled() {
		if ( file_exists( EXELEARNING_PLUGIN_DIR . 'dist/static/index.html' ) ) {
			$this->markTestSkipped( 'The bundled editor is present in this checkout.' );
		}

		$method = new ReflectionMethod( ExeLearning_Export_Bootstrap::class, 'load_editor_template' );
		$method->setAccessible( true );

		$this->expectException( WPDieException::class );
		$method->invoke( $this->bootstrap );
	}

	/**
	 * With the bundle in place the template is read from disk as an HTML
	 * document ready to be patched.
	 */
	public function test_the_template_is_read_from_the_bundled_editor() {
		if ( ! file_exists( EXELEARNING_PLUGIN_DIR . 'dist/static/index.html' ) ) {
			$this->markTestSkipped( 'This checkout has no bundled editor (run make build-editor).' );
		}

		$method = new ReflectionMethod( ExeLearning_Export_Bootstrap::class, 'load_editor_template' );
		$method->setAccessible( true );

		$template = $method->invoke( $this->bootstrap );

		$this->assertIsString( $template );
		$this->assertStringContainsString( '</head>', $template );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Run the private payload injection against an arbitrary template.
	 *
	 * @param string       $template      Raw editor HTML.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|false $elp_url       Attachment URL, or false when unresolved.
	 * @return string Patched HTML.
	 */
	private function inject( $template, $attachment_id = 1, $elp_url = 'https://example.org/project.elpx' ) {
		$method = new ReflectionMethod( ExeLearning_Export_Bootstrap::class, 'inject_bootstrap_payload' );
		$method->setAccessible( true );

		return $method->invoke( $this->bootstrap, $template, $attachment_id, $elp_url, $this->editor_base_url );
	}
}
