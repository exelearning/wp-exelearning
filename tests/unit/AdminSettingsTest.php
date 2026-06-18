<?php
/**
 * Tests for ExeLearning_Admin_Settings class.
 *
 * @package Exelearning
 */

/**
 * Class AdminSettingsTest.
 *
 * @covers ExeLearning_Admin_Settings
 */
class AdminSettingsTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Admin_Settings
	 */
	private $settings;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->settings = new ExeLearning_Admin_Settings();
	}

	/**
	 * Test add_action_links adds settings link.
	 */
	public function test_add_action_links() {
		$links = array(
			'deactivate' => '<a href="#">Deactivate</a>',
		);

		$result = $this->settings->add_action_links( $links );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'exelearning-settings', $result[0] );
		$this->assertStringContainsString( '<a href=', $result[0] );
	}

	/**
	 * Test the action-links filter is registered against the real main plugin
	 * file (exelearning.php), not the old wp-exelearning.php basename.
	 *
	 * With the wrong basename the filter never fires and the Settings link is
	 * never added.
	 */
	public function test_action_links_filter_uses_correct_basename() {
		// Re-run the constructor wiring to register the filter for this instance.
		$this->settings->__construct();

		$expected_hook = 'plugin_action_links_' . plugin_basename( EXELEARNING_PLUGIN_FILE );

		$this->assertGreaterThan(
			0,
			has_filter( $expected_hook, array( $this->settings, 'add_action_links' ) )
		);

		// The buggy hook must NOT be the one in use.
		$wrong_basename = plugin_basename( dirname( __DIR__, 2 ) . '/wp-exelearning.php' );
		$this->assertStringContainsString( 'exelearning.php', $expected_hook );
		$this->assertStringNotContainsString( $wrong_basename, $expected_hook );
	}

	/**
	 * Test add_action_links preserves existing links.
	 */
	public function test_add_action_links_preserves_existing() {
		$links = array(
			'edit'       => '<a href="#">Edit</a>',
			'deactivate' => '<a href="#">Deactivate</a>',
		);

		$result = $this->settings->add_action_links( $links );

		$this->assertCount( 3, $result );
		$this->assertArrayHasKey( 'edit', $result );
		$this->assertArrayHasKey( 'deactivate', $result );
	}

	/**
	 * Test add_admin_menu method exists.
	 */
	public function test_add_admin_menu_exists() {
		$this->assertTrue( method_exists( $this->settings, 'add_admin_menu' ) );
	}

	/**
	 * Test display_settings_page method exists.
	 */
	public function test_display_settings_page_exists() {
		$this->assertTrue( method_exists( $this->settings, 'display_settings_page' ) );
	}

	/**
	 * Test display_settings_page outputs heading.
	 */
	public function test_display_settings_page_outputs_heading() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<h1>', $output );
	}

	/**
	 * Test display_settings_page outputs editor status section.
	 */
	public function test_display_settings_page_outputs_editor_section() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-install-editor', $output );
	}

	/**
	 * Test constructor adds admin_menu action.
	 */
	public function test_constructor_adds_admin_menu_action() {
		$settings = new ExeLearning_Admin_Settings();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_menu', array( $settings, 'add_admin_menu' ) )
		);
	}

	/**
	 * Test add_admin_menu adds options page.
	 */
	public function test_add_admin_menu_adds_options_page() {
		global $submenu;

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->settings->add_admin_menu();

		// Check that the menu was added.
		$this->assertArrayHasKey( 'options-general.php', $submenu );

		// Find our settings page in the submenu.
		$found = false;
		foreach ( $submenu['options-general.php'] as $item ) {
			if ( in_array( 'exelearning-settings', $item, true ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'eXeLearning settings page not found in submenu' );
	}

	/**
	 * Test add_action_links returns proper URL format.
	 */
	public function test_add_action_links_url_format() {
		$links  = array();
		$result = $this->settings->add_action_links( $links );

		$this->assertStringContainsString( 'options-general.php', $result[0] );
		$this->assertStringContainsString( 'page=exelearning-settings', $result[0] );
	}

	/**
	 * Test display_settings_page outputs the help section with shortcode usage.
	 */
	public function test_display_settings_page_outputs_help_section() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-help-card', $output );
		$this->assertStringContainsString( '[exelearning', $output );
	}

	/**
	 * Test the help section links to the GitHub shortcode and hooks references.
	 */
	public function test_display_settings_page_help_links_to_docs() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'docs/SHORTCODES.md', $output );
		$this->assertStringContainsString( 'docs/HOOKS.md', $output );
	}

	/**
	 * Clean up AJAX state and the content-delivery option between tests.
	 */
	public function tear_down() {
		$this->disable_ajax_die_handler();
		delete_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS );
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * The settings page renders the content-delivery (asset-proxy) section.
	 */
	public function test_display_settings_page_outputs_content_delivery_section() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-content-delivery-card', $output );
		$this->assertStringContainsString( 'exelearning-proxy-assets', $output );
	}

	/**
	 * The constructor registers the content-delivery AJAX toggle.
	 */
	public function test_constructor_registers_proxy_assets_ajax_action() {
		$this->assertNotFalse(
			has_action( 'wp_ajax_exelearning_toggle_proxy_assets', array( $this->settings, 'ajax_toggle_proxy_assets' ) )
		);
	}

	/**
	 * Toggling the asset-proxy option on then off persists through the option.
	 */
	public function test_toggle_proxy_assets_round_trip() {
		$this->setup_admin();

		$_POST['enabled'] = '1';
		$response         = $this->expect_json_response(
			function () {
				$this->settings->ajax_toggle_proxy_assets();
			}
		);
		$this->assertTrue( $response['success'] );
		$this->assertTrue( (bool) get_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS ) );
		$this->assertTrue( ExeLearning_Content_Proxy::is_asset_proxy_enabled() );

		$_POST['enabled'] = '';
		$this->expect_json_response(
			function () {
				$this->settings->ajax_toggle_proxy_assets();
			}
		);
		$this->assertFalse( (bool) get_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS ) );
		$this->assertFalse( ExeLearning_Content_Proxy::is_asset_proxy_enabled() );
	}

	/**
	 * The toggle rejects users without manage_options.
	 */
	public function test_toggle_proxy_assets_rejects_non_admin() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_ajax_nonce'] = wp_create_nonce( ExeLearning_Admin_Settings::PROXY_ASSETS_NONCE );
		$_POST['enabled']        = '1';
		$this->enable_ajax_die_handler();

		$response = $this->expect_json_response(
			function () {
				$this->settings->ajax_toggle_proxy_assets();
			}
		);
		$this->assertFalse( $response['success'] );
		$this->assertFalse( get_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, false ) );
	}

	/**
	 * The toggle rejects requests with an invalid nonce.
	 */
	public function test_toggle_proxy_assets_rejects_bad_nonce() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_ajax_nonce'] = 'not-a-valid-nonce';
		$_POST['enabled']        = '1';
		$this->enable_ajax_die_handler();

		$response = $this->expect_json_response(
			function () {
				$this->settings->ajax_toggle_proxy_assets();
			}
		);
		$this->assertFalse( $response['success'] );
		$this->assertFalse( get_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, false ) );
	}

	// ------------------------------------------------------------------
	// Helpers (AJAX die-handler harness, mirrors AdminStylesTest).
	// ------------------------------------------------------------------

	/**
	 * Create an admin and seed a valid nonce so the toggle guard passes.
	 */
	private function setup_admin() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_ajax_nonce'] = wp_create_nonce( ExeLearning_Admin_Settings::PROXY_ASSETS_NONCE );
		$this->enable_ajax_die_handler();
	}

	/**
	 * Run a callable expecting wp_send_json_* to die, returning the captured
	 * JSON payload.
	 *
	 * @param callable $fn Callable that invokes an AJAX handler.
	 * @return array
	 */
	private function expect_json_response( callable $fn ) {
		ob_start();
		try {
			$fn();
			$this->fail( 'Expected WPDieException but none was thrown.' );
		} catch ( WPDieException $e ) {
			// Normal exit path for AJAX endpoints.
		}
		$decoded = json_decode( ob_get_clean(), true );
		$this->assertIsArray( $decoded, 'AJAX handler did not emit JSON' );
		return $decoded;
	}

	private function enable_ajax_die_handler() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return array( $this, 'wp_die_handler' );
			},
			1
		);
	}

	private function disable_ajax_die_handler() {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'wp_die_ajax_handler' );
	}

	/**
	 * Die handler that raises WPDieException instead of exiting the process.
	 *
	 * @param string|WP_Error $message Die message.
	 * @param string          $title   Page title.
	 * @param string|array    $args    wp_die args.
	 */
	public function wp_die_handler( $message, $title = '', $args = array() ) {
		throw new WPDieException( is_scalar( $message ) ? (string) $message : '' );
	}
}
