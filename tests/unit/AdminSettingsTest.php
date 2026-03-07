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
	 * Test sanitize_settings sanitizes input.
	 */
	public function test_sanitize_settings() {
		$input = array(
			'example_option' => '<script>alert("xss")</script>Test',
		);

		$result = $this->settings->sanitize_settings( $input );

		$this->assertArrayHasKey( 'example_option', $result );
		$this->assertStringNotContainsString( '<script>', $result['example_option'] );
		$this->assertStringContainsString( 'Test', $result['example_option'] );
	}

	/**
	 * Test sanitize_settings handles empty input.
	 */
	public function test_sanitize_settings_empty() {
		$result = $this->settings->sanitize_settings( array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test sanitize_settings handles missing keys.
	 */
	public function test_sanitize_settings_missing_keys() {
		$input = array(
			'unknown_key' => 'value',
		);

		$result = $this->settings->sanitize_settings( $input );

		$this->assertArrayNotHasKey( 'unknown_key', $result );
	}

	/**
	 * Test add_admin_menu method exists.
	 */
	public function test_add_admin_menu_exists() {
		$this->assertTrue( method_exists( $this->settings, 'add_admin_menu' ) );
	}

	/**
	 * Test register_settings method exists.
	 */
	public function test_register_settings_exists() {
		$this->assertTrue( method_exists( $this->settings, 'register_settings' ) );
	}

	/**
	 * Test display_option_example method exists.
	 */
	public function test_display_option_example_exists() {
		$this->assertTrue( method_exists( $this->settings, 'display_option_example' ) );
	}

	/**
	 * Test display_settings_page method exists.
	 */
	public function test_display_settings_page_exists() {
		$this->assertTrue( method_exists( $this->settings, 'display_settings_page' ) );
	}

	/**
	 * Test display_option_example outputs input field.
	 */
	public function test_display_option_example_outputs_input() {
		ob_start();
		$this->settings->display_option_example();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<input', $output );
		$this->assertStringContainsString( 'type="text"', $output );
		$this->assertStringContainsString( 'exelearning_settings', $output );
	}

	/**
	 * Test display_settings_page outputs form.
	 */
	public function test_display_settings_page_outputs_form() {
		// Set up admin user for settings_fields function.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form', $output );
		// Title text may be translated, check for heading structure.
		$this->assertStringContainsString( '<h1>', $output );
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
	 * Test constructor adds admin_init action.
	 */
	public function test_constructor_adds_admin_init_action() {
		$settings = new ExeLearning_Admin_Settings();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_init', array( $settings, 'register_settings' ) )
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
	 * Test register_settings registers setting.
	 */
	public function test_register_settings_registers_setting() {
		global $wp_registered_settings;

		$this->settings->register_settings();

		$this->assertArrayHasKey( 'exelearning_settings', $wp_registered_settings );
	}

	/**
	 * Test register_settings adds settings section.
	 */
	public function test_register_settings_adds_section() {
		global $wp_settings_sections;

		$this->settings->register_settings();

		$this->assertArrayHasKey( 'exelearning-settings', $wp_settings_sections );
		$this->assertArrayHasKey( 'exelearning_settings_section', $wp_settings_sections['exelearning-settings'] );
	}

	/**
	 * Test register_settings adds settings field.
	 */
	public function test_register_settings_adds_field() {
		global $wp_settings_fields;

		$this->settings->register_settings();

		$this->assertArrayHasKey( 'exelearning-settings', $wp_settings_fields );
		$this->assertArrayHasKey( 'exelearning_settings_section', $wp_settings_fields['exelearning-settings'] );
		$this->assertArrayHasKey( 'exelearning_option_example', $wp_settings_fields['exelearning-settings']['exelearning_settings_section'] );
	}

	/**
	 * Test sanitize_settings strips HTML tags.
	 */
	public function test_sanitize_settings_strips_html() {
		$input = array(
			'example_option' => '<b>Bold</b> and <i>italic</i>',
		);

		$result = $this->settings->sanitize_settings( $input );

		$this->assertStringNotContainsString( '<b>', $result['example_option'] );
		$this->assertStringNotContainsString( '<i>', $result['example_option'] );
	}

	/**
	 * Test sanitize_settings trims whitespace.
	 */
	public function test_sanitize_settings_trims_whitespace() {
		$input = array(
			'example_option' => '  test value  ',
		);

		$result = $this->settings->sanitize_settings( $input );

		$this->assertEquals( 'test value', $result['example_option'] );
	}

	/**
	 * Test display_option_example with saved value.
	 */
	public function test_display_option_example_with_saved_value() {
		update_option( 'exelearning_settings', array( 'example_option' => 'saved_value' ) );

		ob_start();
		$this->settings->display_option_example();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'saved_value', $output );

		delete_option( 'exelearning_settings' );
	}

	/**
	 * Test display_option_example with empty option.
	 */
	public function test_display_option_example_empty_option() {
		delete_option( 'exelearning_settings' );

		ob_start();
		$this->settings->display_option_example();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value=""', $output );
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
	 * Test display_settings_page has submit button.
	 */
	public function test_display_settings_page_has_submit_button() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'submit', $output );
	}

	/**
	 * Test display_settings_page has correct action.
	 */
	public function test_display_settings_page_has_correct_action() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings->display_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'options.php', $output );
	}
}
