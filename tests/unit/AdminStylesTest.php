<?php
/**
 * Tests for ExeLearning_Admin_Styles.
 *
 * The upload/delete handlers are admin-post endpoints: they end the request
 * either through wp_die() (capability/nonce failures) or through a redirect
 * back to the settings page. In tests, wp_die() is routed to a handler that
 * throws {@see WPDieException}, and the redirect-and-exit step is captured
 * by a test subclass overriding finish_request().
 *
 * @package Exelearning
 */

/**
 * Test double that records the final redirect instead of exiting.
 */
class ExeLearning_Admin_Styles_Test_Double extends ExeLearning_Admin_Styles {

	/**
	 * Captured redirect target.
	 *
	 * @var string|null
	 */
	public $redirect_location = null;

	/**
	 * Record the redirect and halt the handler like exit would.
	 *
	 * @param string $location Redirect target URL.
	 * @throws WPDieException Always, to stop the handler.
	 */
	protected function finish_request( $location ) {
		$this->redirect_location = $location;
		throw new WPDieException( 'redirect: ' . $location );
	}
}

/**
 * Class AdminStylesTest.
 *
 * @covers ExeLearning_Admin_Styles
 */
class AdminStylesTest extends WP_UnitTestCase {

	/**
	 * @var ExeLearning_Admin_Styles_Test_Double
	 */
	private $handler;

	public function set_up() {
		parent::set_up();
		$this->handler = new ExeLearning_Admin_Styles_Test_Double();
		$this->reset_state();
	}

	public function tear_down() {
		$this->disable_die_handler();
		$this->reset_state();
		parent::tear_down();
	}

	/**
	 * Clear options, superglobals and settings-error state between tests.
	 */
	private function reset_state() {
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
		delete_option( ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT );
		delete_option( ExeLearning_Styles_Service::OPTION_DISABLED_STYLES );
		delete_transient( 'settings_errors' );
		$GLOBALS['wp_settings_errors'] = array();
		$_GET                          = array();
		$_POST                         = array();
		$_REQUEST                      = array();
		$_FILES                        = array();
	}

	public function test_constructor_registers_admin_post_actions() {
		$expected = array(
			'admin_post_' . ExeLearning_Admin_Styles::ACTION_UPLOAD,
			'admin_post_' . ExeLearning_Admin_Styles::ACTION_DELETE,
		);
		foreach ( $expected as $hook ) {
			$this->assertNotFalse( has_action( $hook ), "missing hook: $hook" );
		}
	}

	public function test_upload_rejects_request_without_manage_options() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->enable_die_handler();

		$this->expectException( WPDieException::class );
		$this->handler->handle_upload();
	}

	public function test_upload_rejects_request_with_invalid_nonce() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'not-a-valid-nonce';
		$this->enable_die_handler();

		$this->expectException( WPDieException::class );
		$this->handler->handle_upload();
	}

	public function test_delete_rejects_request_without_manage_options() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->enable_die_handler();

		$this->expectException( WPDieException::class );
		$this->handler->handle_delete();
	}

	public function test_upload_redirects_with_error_when_file_missing() {
		$this->setup_admin_for( ExeLearning_Admin_Styles::ACTION_UPLOAD );

		$this->run_handler_expecting_redirect(
			function () {
				$this->handler->handle_upload();
			}
		);

		$this->assertNotNull( $this->handler->redirect_location );
		$this->assertStringContainsString( 'settings-updated=true', $this->handler->redirect_location );
		$this->assertStringContainsString( 'page=exelearning-settings', $this->handler->redirect_location );
		$this->assertSame( 'error', $this->get_persisted_notice()['type'] );
	}

	public function test_upload_redirects_with_error_on_broken_upload() {
		$this->setup_admin_for( ExeLearning_Admin_Styles::ACTION_UPLOAD );
		$_FILES['style_zip'] = array(
			'error'    => UPLOAD_ERR_PARTIAL,
			'name'     => 'bad.zip',
			'size'     => 10,
			'tmp_name' => '',
		);

		$this->run_handler_expecting_redirect(
			function () {
				$this->handler->handle_upload();
			}
		);

		$this->assertSame( 'error', $this->get_persisted_notice()['type'] );
	}

	public function test_delete_removes_uploaded_style_and_redirects_with_success() {
		$this->install_fake_style( 'bye' );
		$dir = ExeLearning_Styles_Service::get_storage_dir() . '/bye';
		$this->assertDirectoryExists( $dir );

		$this->setup_admin_for( ExeLearning_Admin_Styles::ACTION_DELETE . '_bye' );
		$_GET['slug'] = 'bye';

		$this->run_handler_expecting_redirect(
			function () {
				$this->handler->handle_delete();
			}
		);

		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertArrayNotHasKey( 'bye', ExeLearning_Styles_Service::get_registry()['uploaded'] );
		$this->assertSame( 'success', $this->get_persisted_notice()['type'] );
	}

	public function test_delete_redirects_with_error_on_unknown_slug() {
		$this->setup_admin_for( ExeLearning_Admin_Styles::ACTION_DELETE . '_does-not-exist' );
		$_GET['slug'] = 'does-not-exist';

		$this->run_handler_expecting_redirect(
			function () {
				$this->handler->handle_delete();
			}
		);

		$this->assertSame( 'error', $this->get_persisted_notice()['type'] );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Create an admin and seed a valid nonce for the given nonce action.
	 *
	 * @param string $nonce_action Nonce action check_admin_referer() expects.
	 */
	private function setup_admin_for( $nonce_action ) {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action );
		$this->enable_die_handler();
	}

	/**
	 * Invoke a handler and swallow the WPDieException thrown by the
	 * redirect-capturing test double.
	 *
	 * @param callable $fn Callable that invokes the handler.
	 */
	private function run_handler_expecting_redirect( callable $fn ) {
		try {
			$fn();
			$this->fail( 'Expected the handler to end with a redirect.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'redirect:', $e->getMessage() );
		}
	}

	/**
	 * Read the settings notice persisted for the post-redirect page load.
	 *
	 * @return array Notice array (setting, code, message, type).
	 */
	private function get_persisted_notice() {
		$stored = get_transient( 'settings_errors' );
		$this->assertIsArray( $stored, 'No settings_errors transient was stored.' );
		$this->assertNotEmpty( $stored );
		return $stored[0];
	}

	/**
	 * Install a small, valid style on disk and in the registry.
	 *
	 * @param string $slug Style slug.
	 */
	private function install_fake_style( $slug ) {
		$zip_path = wp_tempnam( $slug . '.zip' );
		wp_delete_file( $zip_path );
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString(
			'config.xml',
			'<?xml version="1.0"?><theme><name>' . $slug . '</name>'
			. '<title>' . ucfirst( $slug ) . '</title><version>1.0</version></theme>'
		);
		$zip->addFromString( 'style.css', 'body{}' );
		$zip->close();
		ExeLearning_Styles_Service::install_from_zip( $zip_path, $slug . '.zip' );
		wp_delete_file( $zip_path );
	}

	/**
	 * Route wp_die() to a handler that throws WPDieException.
	 */
	private function enable_die_handler() {
		add_filter(
			'wp_die_handler',
			function () {
				return array( $this, 'wp_die_handler' );
			},
			1
		);
	}

	/**
	 * Remove the wp_die() handler override.
	 */
	private function disable_die_handler() {
		remove_all_filters( 'wp_die_handler' );
	}

	/**
	 * Die handler that raises WPDieException instead of exiting the process.
	 *
	 * @param string|WP_Error $message Die message.
	 * @param string          $title   Page title.
	 * @param string|array    $args    wp_die args.
	 * @throws WPDieException Always.
	 */
	public function wp_die_handler( $message, $title = '', $args = array() ) {
		throw new WPDieException( is_scalar( $message ) ? (string) $message : '' );
	}
}
