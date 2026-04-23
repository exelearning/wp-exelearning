<?php
/**
 * Tests for ExeLearning_Admin_Styles.
 *
 * AJAX handlers call `wp_send_json_*` which in turn call `wp_die()`. In the
 * test environment we re-route that through the WP_Ajax_UnitTestCase-style
 * die handler so each call throws {@see WPDieException} and the response
 * payload is captured in the output buffer.
 *
 * @package Exelearning
 */

/**
 * Class AdminStylesTest.
 *
 * @covers ExeLearning_Admin_Styles
 */
class AdminStylesTest extends WP_UnitTestCase {

	/**
	 * @var ExeLearning_Admin_Styles
	 */
	private $handler;

	public function set_up() {
		parent::set_up();
		$this->handler = new ExeLearning_Admin_Styles();
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
		delete_option( ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT );
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
	}

	public function tear_down() {
		$this->disable_ajax_die_handler();
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
		delete_option( ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT );
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		parent::tear_down();
	}

	public function test_constructor_registers_all_ajax_actions() {
		$expected = array(
			'wp_ajax_exelearning_styles_upload',
			'wp_ajax_exelearning_styles_toggle_uploaded',
			'wp_ajax_exelearning_styles_toggle_builtin',
			'wp_ajax_exelearning_styles_delete',
			'wp_ajax_exelearning_styles_toggle_block_import',
		);
		foreach ( $expected as $hook ) {
			$this->assertTrue( has_action( $hook ) !== false, "missing hook: $hook" );
		}
	}

	public function test_rejects_request_without_manage_options() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->enable_ajax_die_handler();
		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_delete();
			}
		);
		$this->assertFalse( $response['success'] );
		// Message wording depends on the active locale; just require
		// that the handler produced a non-empty error message.
		$this->assertNotEmpty( $response['data']['message'] );
	}

	public function test_rejects_request_without_nonce() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_ajax_nonce'] = 'not-a-valid-nonce';

		$this->enable_ajax_die_handler();
		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_delete();
			}
		);
		$this->assertFalse( $response['success'] );
		$this->assertNotEmpty( $response['data']['message'] );
	}

	public function test_toggle_uploaded_returns_error_when_slug_missing() {
		$this->setup_admin();

		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_uploaded();
			}
		);
		$this->assertFalse( $response['success'] );
		$this->assertNotEmpty( $response['data']['message'] );
	}

	public function test_toggle_uploaded_propagates_to_service() {
		$this->setup_admin();
		$this->install_fake_style( 'acme' );
		$this->assertTrue( ExeLearning_Styles_Service::get_registry()['uploaded']['acme']['enabled'] );

		$_POST['slug']    = 'acme';
		$_POST['enabled'] = '0';
		$response         = $this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_uploaded();
			}
		);
		$this->assertTrue( $response['success'] );
		$this->assertFalse( ExeLearning_Styles_Service::get_registry()['uploaded']['acme']['enabled'] );
	}

	public function test_toggle_uploaded_rejects_unknown_slug() {
		$this->setup_admin();
		$_POST['slug']    = 'does-not-exist';
		$_POST['enabled'] = '1';

		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_uploaded();
			}
		);
		$this->assertFalse( $response['success'] );
	}

	public function test_toggle_builtin_returns_error_when_id_missing() {
		$this->setup_admin();
		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_builtin();
			}
		);
		$this->assertFalse( $response['success'] );
	}

	public function test_toggle_builtin_propagates_disable_then_enable() {
		$this->setup_admin();

		$_POST['id']      = 'zen';
		$_POST['enabled'] = '0';
		$this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_builtin();
			}
		);
		$this->assertContains( 'zen', ExeLearning_Styles_Service::get_registry()['disabled_builtins'] );

		$_POST['enabled'] = '1';
		$this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_builtin();
			}
		);
		$this->assertNotContains( 'zen', ExeLearning_Styles_Service::get_registry()['disabled_builtins'] );
	}

	public function test_delete_returns_error_when_slug_missing() {
		$this->setup_admin();
		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_delete();
			}
		);
		$this->assertFalse( $response['success'] );
	}

	public function test_delete_removes_uploaded_style() {
		$this->setup_admin();
		$this->install_fake_style( 'bye' );
		$dir = ExeLearning_Styles_Service::get_storage_dir() . '/bye';
		$this->assertDirectoryExists( $dir );

		$_POST['slug'] = 'bye';
		$response      = $this->expect_json_response(
			function () {
				$this->handler->ajax_delete();
			}
		);
		$this->assertTrue( $response['success'] );
		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_toggle_block_import_round_trip() {
		$this->setup_admin();

		$_POST['enabled'] = '1';
		$this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_block_import();
			}
		);
		$this->assertTrue( ExeLearning_Styles_Service::is_import_blocked() );

		$_POST['enabled'] = '';
		$this->expect_json_response(
			function () {
				$this->handler->ajax_toggle_block_import();
			}
		);
		$this->assertFalse( ExeLearning_Styles_Service::is_import_blocked() );
	}

	public function test_upload_rejects_missing_file() {
		$this->setup_admin();
		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_upload();
			}
		);
		$this->assertFalse( $response['success'] );
		$this->assertNotEmpty( $response['data']['message'] );
	}

	public function test_upload_rejects_broken_upload() {
		$this->setup_admin();
		$_FILES['style_zip'] = array(
			'error' => UPLOAD_ERR_PARTIAL,
			'name'  => 'bad.zip',
			'size'  => 10,
			'tmp_name' => '',
		);
		$response = $this->expect_json_response(
			function () {
				$this->handler->ajax_upload();
			}
		);
		$this->assertFalse( $response['success'] );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Create an admin and seed POST with a valid nonce so the guard passes.
	 */
	private function setup_admin() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_ajax_nonce'] = wp_create_nonce( ExeLearning_Admin_Styles::AJAX_NONCE );
		$this->enable_ajax_die_handler();
	}

	/**
	 * Run the given callable expecting wp_send_json_* to die, and return
	 * the JSON payload that was captured on its way out.
	 *
	 * @param callable $fn
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
		$json = ob_get_clean();
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'AJAX handler did not emit JSON' );
		return $decoded;
	}

	/**
	 * Install a small, valid style on disk and in the registry.
	 */
	private function install_fake_style( $slug ) {
		$zip_path = wp_tempnam( $slug . '.zip' );
		wp_delete_file( $zip_path );
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'config.xml',
			'<?xml version="1.0"?><theme><name>' . $slug . '</name>'
			. '<title>' . ucfirst( $slug ) . '</title><version>1.0</version></theme>'
		);
		$zip->addFromString( 'style.css', 'body{}' );
		$zip->close();
		ExeLearning_Styles_Service::install_from_zip( $zip_path, $slug . '.zip' );
		wp_delete_file( $zip_path );
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
	 * Signature matches WP_UnitTestCase_Base::wp_die_handler so PHP's
	 * strict LSP check during class loading does not blow up the whole
	 * test file.
	 *
	 * @param string|WP_Error $message Die message.
	 * @param string          $title   Page title.
	 * @param string|array    $args    wp_die args.
	 */
	public function wp_die_handler( $message, $title = '', $args = array() ) {
		throw new WPDieException( is_scalar( $message ) ? (string) $message : '' );
	}
}
