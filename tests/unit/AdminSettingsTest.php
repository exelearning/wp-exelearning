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
}
