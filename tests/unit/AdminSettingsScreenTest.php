<?php
/**
 * Tests for the screen-scoped behaviour of ExeLearning_Admin_Settings.
 *
 * AdminSettingsTest covers the option registration and the rendered markup.
 * This file covers the parts that only run once WordPress has loaded the
 * settings screen: the contextual help, the screen-scoped assets, and the
 * notice that explains a missing editor bundle.
 *
 * @package Exelearning
 */

/**
 * Class AdminSettingsScreenTest.
 *
 * @covers ExeLearning_Admin_Settings
 */
class AdminSettingsScreenTest extends WP_UnitTestCase {

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

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->settings                = new ExeLearning_Admin_Settings();
		$GLOBALS['wp_settings_errors'] = array();
		$_GET                          = array();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		ExeLearning_Bundle_Fixture::destroy();
		$GLOBALS['wp_settings_errors'] = array();
		$_GET                          = array();
		wp_dequeue_style( 'exelearning-settings' );
		wp_dequeue_script( 'exelearning-settings' );
		parent::tear_down();
	}

	/**
	 * The screen gets a contextual Help tab pointing at the shortcode, plus a
	 * sidebar linking the reference docs.
	 */
	public function test_the_settings_screen_gets_contextual_help() {
		set_current_screen( 'settings_page_' . ExeLearning_Admin_Settings::PAGE_SLUG );

		$this->settings->on_settings_page_load();
		$screen = get_current_screen();

		$tab = $screen->get_help_tab( 'exelearning-overview' );
		$this->assertIsArray( $tab, 'The overview help tab must be registered.' );
		$this->assertStringContainsString( '[exelearning id="123"]', $tab['content'] );

		$this->assertStringContainsString( 'docs/SHORTCODES.md', $screen->get_help_sidebar() );
		$this->assertStringContainsString( 'docs/HOOKS.md', $screen->get_help_sidebar() );
	}

	/**
	 * Nothing is announced on a plain visit to the settings screen.
	 */
	public function test_no_notice_is_raised_without_the_redirect_marker() {
		set_current_screen( 'settings_page_' . ExeLearning_Admin_Settings::PAGE_SLUG );

		$this->settings->on_settings_page_load();

		$this->assertSame( array(), get_settings_errors( 'exelearning_editor' ) );
	}

	/**
	 * The editor bootstrap redirects here with `editor-missing=1` when the
	 * bundle is absent. The notice is raised only when that is really the case,
	 * so the marker cannot be used to fake a warning on a healthy install.
	 */
	public function test_the_editor_missing_notice_is_raised_when_the_bundle_is_absent() {
		ExeLearning_Bundle_Fixture::create_empty();
		set_current_screen( 'settings_page_' . ExeLearning_Admin_Settings::PAGE_SLUG );
		$_GET['editor-missing'] = '1';

		$this->settings->on_settings_page_load();
		$notices = get_settings_errors( 'exelearning_editor' );

		$this->assertCount( 1, $notices );
		$this->assertSame( 'exelearning_editor_missing', $notices[0]['code'] );
		$this->assertSame( 'warning', $notices[0]['type'] );
		$this->assertStringContainsString( 'make build-editor', $notices[0]['message'] );
	}

	/**
	 * The marker alone cannot fake the warning: with the editor bundled, the
	 * notice is not raised however the query string arrived.
	 */
	public function test_the_editor_missing_notice_is_not_raised_when_the_bundle_is_there() {
		ExeLearning_Bundle_Fixture::create();
		set_current_screen( 'settings_page_' . ExeLearning_Admin_Settings::PAGE_SLUG );
		$_GET['editor-missing'] = '1';

		$this->settings->on_settings_page_load();

		$this->assertSame(
			array(),
			get_settings_errors( 'exelearning_editor' ),
			'A bundled editor must not be reported as missing.'
		);
	}

	/**
	 * A value other than "1" in the marker is ignored.
	 */
	public function test_an_unexpected_marker_value_is_ignored() {
		set_current_screen( 'settings_page_' . ExeLearning_Admin_Settings::PAGE_SLUG );
		$_GET['editor-missing'] = 'yes';

		$this->settings->on_settings_page_load();

		$this->assertSame( array(), get_settings_errors( 'exelearning_editor' ) );
	}

	/**
	 * The settings assets load on the plugin's own screen only.
	 */
	public function test_assets_are_enqueued_on_the_settings_screen_only() {
		$hook = $this->register_menu();

		$this->settings->enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'exelearning-settings', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'exelearning-settings', 'enqueued' ) );

		$this->settings->enqueue_assets( $hook );
		$this->assertTrue( wp_style_is( 'exelearning-settings', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'exelearning-settings', 'enqueued' ) );
	}

	/**
	 * Registering the menu also wires the screen-load callback, which is what
	 * scopes the help tab and the notice to this screen.
	 */
	public function test_registering_the_menu_hooks_the_screen_load_callback() {
		$hook = $this->register_menu();

		$this->assertStringEndsWith( '_page_' . ExeLearning_Admin_Settings::PAGE_SLUG, $hook );
		$this->assertSame(
			10,
			has_action( 'load-' . $hook, array( $this->settings, 'on_settings_page_load' ) )
		);
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Register the options page and return its hook suffix.
	 *
	 * @return string Hook suffix.
	 */
	private function register_menu() {
		$this->settings->add_admin_menu();

		$property = new ReflectionProperty( ExeLearning_Admin_Settings::class, 'hook_suffix' );
		$property->setAccessible( true );

		return $property->getValue( $this->settings );
	}
}
