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
	 * Clean up registered options and globals between tests.
	 */
	public function tear_down() {
		global $wp_registered_settings, $wp_settings_sections, $wp_settings_fields;
		unset( $wp_registered_settings[ ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT ] );
		unset( $wp_registered_settings[ ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS ] );
		unset( $wp_registered_settings[ ExeLearning_Styles_Service::OPTION_DISABLED_STYLES ] );
		unset( $wp_settings_sections[ ExeLearning_Admin_Settings::PAGE_SLUG ] );
		unset( $wp_settings_fields[ ExeLearning_Admin_Settings::PAGE_SLUG ] );
		delete_option( ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT );
		delete_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS );
		delete_option( ExeLearning_Styles_Service::OPTION_DISABLED_STYLES );
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
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
	 * Test constructor adds admin_menu and admin_init actions.
	 */
	public function test_constructor_registers_hooks() {
		$settings = new ExeLearning_Admin_Settings();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_menu', array( $settings, 'add_admin_menu' ) )
		);
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
	 * Test add_action_links returns proper URL format.
	 */
	public function test_add_action_links_url_format() {
		$links  = array();
		$result = $this->settings->add_action_links( $links );

		$this->assertStringContainsString( 'options-general.php', $result[0] );
		$this->assertStringContainsString( 'page=exelearning-settings', $result[0] );
	}

	/**
	 * register_settings() must register every option with a sanitize callback.
	 */
	public function test_register_settings_registers_all_options() {
		$this->settings->register_settings();

		$registered = get_registered_settings();

		foreach ( array(
			ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT,
			ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS,
			ExeLearning_Styles_Service::OPTION_DISABLED_STYLES,
		) as $option ) {
			$this->assertArrayHasKey( $option, $registered, "option not registered: $option" );
			$this->assertSame( ExeLearning_Admin_Settings::OPTION_GROUP, $registered[ $option ]['group'] );
			$this->assertNotEmpty( $registered[ $option ]['sanitize_callback'], "missing sanitize callback: $option" );
		}
	}

	/**
	 * Checkbox sanitizer collapses everything to strict 1/0.
	 */
	public function test_sanitize_checkbox() {
		$this->assertSame( 1, $this->settings->sanitize_checkbox( '1' ) );
		$this->assertSame( 1, $this->settings->sanitize_checkbox( 'on' ) );
		$this->assertSame( 0, $this->settings->sanitize_checkbox( '' ) );
		$this->assertSame( 0, $this->settings->sanitize_checkbox( '0' ) );
		$this->assertSame( 0, $this->settings->sanitize_checkbox( null ) );
	}

	/**
	 * The disabled-styles sanitizer understands the hidden+checkbox map the
	 * settings form posts: "1" (hidden) means disabled, "0" (checkbox) means
	 * enabled.
	 */
	public function test_sanitize_disabled_styles_accepts_form_map() {
		$out = $this->settings->sanitize_disabled_styles(
			array(
				'zen'  => '0',
				'flux' => '1',
				'nova' => '1',
			)
		);
		$this->assertSame( array( 'flux', 'nova' ), $out );
	}

	/**
	 * The sanitizer also accepts the flat list shape used by programmatic
	 * writes and the DB migration.
	 */
	public function test_sanitize_disabled_styles_accepts_flat_list() {
		$out = $this->settings->sanitize_disabled_styles( array( 'zen', 'flux', 'zen' ) );
		$this->assertSame( array( 'flux', 'zen' ), $out );
	}

	/**
	 * Non-array input and junk entries never make it into the option.
	 */
	public function test_sanitize_disabled_styles_rejects_junk() {
		$this->assertSame( array(), $this->settings->sanitize_disabled_styles( 'zen' ) );
		$this->assertSame( array(), $this->settings->sanitize_disabled_styles( null ) );
		$this->assertSame( array(), $this->settings->sanitize_disabled_styles( array( '' => '1' ) ) );
	}

	/**
	 * The settings page renders the single options.php form with a submit
	 * button and all registered sections.
	 */
	public function test_display_settings_page_renders_settings_form() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings->register_settings();

		$output = $this->render_settings_page();

		$this->assertStringContainsString( '<h1>', $output );
		$this->assertStringContainsString( 'action="options.php"', $output );
		// settings_fields() renders its hidden inputs with single-quoted attributes.
		$this->assertStringContainsString( "name='option_page' value='" . ExeLearning_Admin_Settings::OPTION_GROUP . "'", $output );
		$this->assertStringContainsString( 'id="submit"', $output );
		$this->assertStringContainsString( 'name="' . ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT . '"', $output );
		$this->assertStringContainsString( 'name="' . ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS . '"', $output );
	}

	/**
	 * The upload form is separate, posts to admin-post.php and carries the
	 * action field plus a nonce.
	 */
	public function test_display_settings_page_renders_upload_form() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$output = $this->render_settings_page();

		$this->assertStringContainsString( 'admin-post.php', $output );
		$this->assertStringContainsString( 'value="' . ExeLearning_Admin_Styles::ACTION_UPLOAD . '"', $output );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $output );
		$this->assertStringContainsString( 'name="style_zip"', $output );
	}

	/**
	 * The editor install/update button posts to admin-post.php as well.
	 */
	public function test_display_settings_page_renders_editor_action_form() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$output = $this->render_settings_page();

		$this->assertStringContainsString( 'value="' . ExeLearning_Static_Editor_Installer::ACTION . '"', $output );
	}

	/**
	 * Enabled checkboxes for uploaded styles are part of the settings form,
	 * named after the registered disabled-styles option, and each row links
	 * to a nonced admin-post delete action.
	 */
	public function test_display_settings_page_renders_style_rows_in_settings_form() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->install_fake_style( 'acme' );
		$this->settings->register_settings();

		$output = $this->render_settings_page();

		$this->assertStringContainsString( ExeLearning_Styles_Service::OPTION_DISABLED_STYLES . '[acme]', $output );
		$this->assertStringContainsString( 'action=' . ExeLearning_Admin_Styles::ACTION_DELETE, $output );
		$this->assertStringContainsString( 'slug=acme', $output );
		$this->assertStringContainsString( '_wpnonce', $output );
	}

	/**
	 * The inline reference lives in a native <details> block at the bottom,
	 * with the shortcode examples and links to the GitHub docs.
	 */
	public function test_display_settings_page_outputs_collapsed_help_reference() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$output = $this->render_settings_page();

		$this->assertStringContainsString( '<details class="exelearning-help"', $output );
		$this->assertStringContainsString( '<summary>', $output );
		$this->assertStringContainsString( '[exelearning', $output );
		$this->assertStringContainsString( 'docs/SHORTCODES.md', $output );
		$this->assertStringContainsString( 'docs/HOOKS.md', $output );
	}

	/**
	 * No inline <script> blocks remain on the settings screen: persistence
	 * happens through plain form submissions.
	 */
	public function test_display_settings_page_has_no_inline_scripts() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings->register_settings();

		$output = $this->render_settings_page();

		$this->assertStringNotContainsString( '<script', $output );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Render the settings page into a string.
	 *
	 * @return string
	 */
	private function render_settings_page() {
		ob_start();
		$this->settings->display_settings_page();
		return ob_get_clean();
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
}
