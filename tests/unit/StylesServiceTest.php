<?php
/**
 * Tests for ExeLearning_Styles_Service.
 *
 * Covers the parts of the contract that the admin UI and the editor
 * bootstrap depend on: ZIP validation, registry shape, disable/delete
 * semantics and the themeRegistryOverride payload.
 *
 * @package Exelearning
 */

/**
 * Class StylesServiceTest.
 *
 * @covers ExeLearning_Styles_Service
 */
class StylesServiceTest extends WP_UnitTestCase {

	/**
	 * Reset the persisted registry between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
	}

	public function tear_down() {
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
		$storage = ExeLearning_Styles_Service::get_storage_dir();
		if ( is_dir( $storage ) ) {
			ExeLearning_Styles_Service::recursive_delete( $storage );
		}
		parent::tear_down();
	}

	public function test_extract_themes_from_bundle_accepts_double_nested_shape() {
		$decoded = array(
			'version' => 'x',
			'themes'  => array(
				'themes' => array(
					array( 'name' => 'neo', 'title' => 'Neo', 'version' => '2025' ),
					array( 'name' => 'base', 'title' => 'Default' ),
				),
			),
		);
		$out = ExeLearning_Styles_Service::extract_themes_from_bundle( $decoded );
		$this->assertCount( 2, $out );
		$this->assertSame( 'neo', $out[0]['id'] );
		$this->assertSame( 'Neo', $out[0]['title'] );
	}

	public function test_extract_themes_from_bundle_accepts_flat_shape() {
		$decoded = array(
			'themes' => array(
				array( 'name' => 'alpha', 'title' => 'Alpha' ),
			),
		);
		$out = ExeLearning_Styles_Service::extract_themes_from_bundle( $decoded );
		$this->assertCount( 1, $out );
		$this->assertSame( 'alpha', $out[0]['name'] );
	}

	public function test_extract_themes_from_bundle_empty_on_missing_themes() {
		$this->assertSame( array(), ExeLearning_Styles_Service::extract_themes_from_bundle( array() ) );
		$this->assertSame(
			array(),
			ExeLearning_Styles_Service::extract_themes_from_bundle( array( 'themes' => 'not-an-array' ) )
		);
	}

	public function test_get_registry_returns_default_shape() {
		$r = ExeLearning_Styles_Service::get_registry();
		$this->assertSame( array(), $r['uploaded'] );
		$this->assertSame( array(), $r['disabled_builtins'] );
	}

	public function test_set_builtin_enabled_toggles_disabled_list() {
		ExeLearning_Styles_Service::set_builtin_enabled( 'zen', false );
		$r = ExeLearning_Styles_Service::get_registry();
		$this->assertSame( array( 'zen' ), $r['disabled_builtins'] );

		// Idempotent add.
		ExeLearning_Styles_Service::set_builtin_enabled( 'zen', false );
		$r = ExeLearning_Styles_Service::get_registry();
		$this->assertSame( array( 'zen' ), $r['disabled_builtins'] );

		ExeLearning_Styles_Service::set_builtin_enabled( 'zen', true );
		$r = ExeLearning_Styles_Service::get_registry();
		$this->assertSame( array(), $r['disabled_builtins'] );
	}

	public function test_set_uploaded_enabled_returns_error_on_unknown_slug() {
		$result = ExeLearning_Styles_Service::set_uploaded_enabled( 'nope', true );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_validate_zip_rejects_missing_config() {
		$zip_path = $this->make_zip(
			array(
				'style.css' => '.x{}',
			)
		);
		$result = ExeLearning_Styles_Service::validate_zip( $zip_path );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_missing_config', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_zip_rejects_traversal_entry() {
		$zip_path = $this->make_zip(
			array(
				'config.xml'  => $this->sample_config_xml( 'acme' ),
				'../evil.css' => 'pwn',
			)
		);
		$result = ExeLearning_Styles_Service::validate_zip( $zip_path );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_unsafe_entry', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_zip_rejects_disallowed_extension() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'acme' ),
				'evil.php'   => '<?php echo "bad"; ?>',
			)
		);
		$result = ExeLearning_Styles_Service::validate_zip( $zip_path );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_bad_extension', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_zip_accepts_valid_package_at_root() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'acme-2026', 'Acme 2026', '1.0.0' ),
				'style.css'  => 'body { color: #000; }',
			)
		);
		$result = ExeLearning_Styles_Service::validate_zip( $zip_path );
		$this->assertIsArray( $result );
		$this->assertSame( 'acme-2026', $result['config']['name'] );
		$this->assertSame( 'Acme 2026', $result['config']['title'] );
		$this->assertSame( '', $result['prefix'] );
		wp_delete_file( $zip_path );
	}

	public function test_validate_zip_accepts_single_root_folder() {
		$zip_path = $this->make_zip(
			array(
				'acme/config.xml' => $this->sample_config_xml( 'acme' ),
				'acme/style.css'  => 'body{}',
			)
		);
		$result = ExeLearning_Styles_Service::validate_zip( $zip_path );
		$this->assertIsArray( $result );
		$this->assertSame( 'acme/', $result['prefix'] );
		wp_delete_file( $zip_path );
	}

	public function test_install_from_zip_installs_extracts_and_registers() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'acme', 'Acme', '1.0.0' ),
				'style.css'  => 'body { color: red; }',
			)
		);
		$entry = ExeLearning_Styles_Service::install_from_zip( $zip_path, 'acme.zip' );
		$this->assertIsArray( $entry );
		$this->assertSame( 'acme', $entry['name'] );
		$this->assertTrue( $entry['enabled'] );
		$this->assertContains( 'style.css', $entry['css_files'] );

		$extracted_css = ExeLearning_Styles_Service::get_storage_dir() . '/acme/style.css';
		$this->assertFileExists( $extracted_css );

		$registry = ExeLearning_Styles_Service::get_registry();
		$this->assertArrayHasKey( 'acme', $registry['uploaded'] );

		wp_delete_file( $zip_path );
	}

	public function test_install_from_zip_generates_unique_slug_on_collision() {
		$zip1 = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'duo' ),
				'style.css'  => 'a{}',
			)
		);
		$zip2 = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'duo' ),
				'style.css'  => 'b{}',
			)
		);
		$a = ExeLearning_Styles_Service::install_from_zip( $zip1 );
		$b = ExeLearning_Styles_Service::install_from_zip( $zip2 );
		$this->assertSame( 'duo', $a['name'] );
		$this->assertSame( 'duo-2', $b['name'] );

		wp_delete_file( $zip1 );
		wp_delete_file( $zip2 );
	}

	public function test_delete_uploaded_removes_files_and_registry_entry() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'bye' ),
				'style.css'  => 'x{}',
			)
		);
		ExeLearning_Styles_Service::install_from_zip( $zip_path );
		$dir = ExeLearning_Styles_Service::get_storage_dir() . '/bye';
		$this->assertDirectoryExists( $dir );

		$result = ExeLearning_Styles_Service::delete_uploaded( 'bye' );
		$this->assertTrue( $result );
		$this->assertDirectoryDoesNotExist( $dir );
		$registry = ExeLearning_Styles_Service::get_registry();
		$this->assertArrayNotHasKey( 'bye', $registry['uploaded'] );

		wp_delete_file( $zip_path );
	}

	public function test_build_theme_registry_override_respects_enabled_flag() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'seen' ),
				'style.css'  => 'a{}',
			)
		);
		ExeLearning_Styles_Service::install_from_zip( $zip_path );
		ExeLearning_Styles_Service::set_builtin_enabled( 'zen', false );

		$override = ExeLearning_Styles_Service::build_theme_registry_override();
		$this->assertSame( array( 'zen' ), $override['disabledBuiltins'] );
		$this->assertTrue( $override['blockImportInstall'] );
		$this->assertSame( 'base', $override['fallbackTheme'] );
		$this->assertCount( 1, $override['uploaded'] );
		$this->assertSame( 'seen', $override['uploaded'][0]['id'] );

		// Disabling hides it from the override.
		ExeLearning_Styles_Service::set_uploaded_enabled( 'seen', false );
		$override = ExeLearning_Styles_Service::build_theme_registry_override();
		$this->assertCount( 0, $override['uploaded'] );

		wp_delete_file( $zip_path );
	}

	/**
	 * Build a ZIP file containing the given entries in a temp location.
	 *
	 * @param array<string,string> $entries Map of entry-name => contents.
	 * @return string Absolute path to the created ZIP.
	 */
	private function make_zip( array $entries ) {
		$path = wp_tempnam( 'styles-test.zip' );
		// wp_tempnam creates an empty file; ZipArchive needs to overwrite it.
		wp_delete_file( $path );
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE ) );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		return $path;
	}

	/**
	 * Minimal valid eXeLearning style config.xml.
	 *
	 * @param string $name    Theme id.
	 * @param string $title   Human-readable title.
	 * @param string $version Version string.
	 * @return string
	 */
	private function sample_config_xml( $name, $title = '', $version = '1.0.0' ) {
		$title = '' === $title ? ucfirst( $name ) : $title;
		return '<?xml version="1.0"?>'
			. '<theme>'
			. '<name>' . esc_html( $name ) . '</name>'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<version>' . esc_html( $version ) . '</version>'
			. '<author>Test</author>'
			. '<license>CC-BY-SA</license>'
			. '<description>Test theme.</description>'
			. '</theme>';
	}
}
