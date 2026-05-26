<?php
/**
 * Tests for ExeLearning_Export_Bootstrap.
 *
 * @package Exelearning
 */

/**
 * Class ExportBootstrapTest.
 *
 * @covers ExeLearning_Export_Bootstrap
 */
class ExportBootstrapTest extends WP_UnitTestCase {

	/**
	 * Bootstrap instance under test.
	 *
	 * @var ExeLearning_Export_Bootstrap
	 */
	private $bootstrap;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->bootstrap = new ExeLearning_Export_Bootstrap();
		unset( $_GET['exe_export'], $_GET['attachment_id'] );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		unset( $_GET['exe_export'], $_GET['attachment_id'] );
		parent::tear_down();
	}

	/**
	 * The constructor wires hooks. Exercise it by checking init/template_redirect
	 * have callbacks registered.
	 */
	public function test_constructor_registers_hooks() {
		$this->assertNotFalse( has_action( 'init', array( $this->bootstrap, 'register_endpoint' ) ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( $this->bootstrap, 'maybe_render' ) ) );
	}

	/**
	 * register_endpoint() registers the rewrite rule and query var.
	 */
	public function test_register_endpoint_adds_rewrite() {
		$this->bootstrap->register_endpoint();
		$rules = get_option( 'rewrite_rules' );
		// `rewrite_rules` is regenerated lazily; the rule is in $wp_rewrite->extra_rules_top.
		global $wp_rewrite;
		$this->assertArrayHasKey( '^exelearning/export/?$', $wp_rewrite->extra_rules_top );
		$this->assertContains( 'exe_export', $wp_rewrite->public_query_vars );
	}

	/**
	 * url_for() builds a URL with the expected query args.
	 */
	public function test_url_for_builds_query_args() {
		$url = ExeLearning_Export_Bootstrap::url_for( 42 );
		$this->assertStringContainsString( 'exe_export=1', $url );
		$this->assertStringContainsString( 'attachment_id=42', $url );
		$this->assertStringStartsWith( home_url( '/' ), $url );
	}

	/**
	 * url_for() sanitizes non-numeric input.
	 */
	public function test_url_for_sanitizes_input() {
		$url = ExeLearning_Export_Bootstrap::url_for( '7abc' );
		$this->assertStringContainsString( 'attachment_id=7', $url );
	}

	/**
	 * Without the query var / GET param, maybe_render returns silently.
	 */
	public function test_maybe_render_noop_without_signal() {
		// Should not throw / exit / output.
		$this->expectNotToPerformAssertions();
		$this->bootstrap->maybe_render();
	}

	/**
	 * Missing attachment_id triggers wp_die(400).
	 */
	public function test_maybe_render_requires_attachment_id() {
		$_GET['exe_export'] = '1';
		$this->expectException( 'WPDieException' );
		$this->bootstrap->maybe_render();
	}

	/**
	 * Non-existent attachment triggers wp_die(404).
	 */
	public function test_maybe_render_rejects_unknown_attachment() {
		$_GET['exe_export']    = '1';
		$_GET['attachment_id'] = '999999';
		$this->expectException( 'WPDieException' );
		$this->bootstrap->maybe_render();
	}

	/**
	 * Non-elpx attachment triggers wp_die(400).
	 */
	public function test_maybe_render_rejects_non_elpx_attachment() {
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_title'     => 'something',
				'post_mime_type' => 'image/png',
			)
		);
		$_GET['exe_export']    = '1';
		$_GET['attachment_id'] = (string) $attachment_id;
		$this->expectException( 'WPDieException' );
		$this->bootstrap->maybe_render();
	}
}
