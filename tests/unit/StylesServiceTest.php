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
		// PHP's ZipArchive::addFromString may normalize leading "../" away
		// on some builds, so we trigger the guard with a path that every
		// stable build preserves verbatim ("foo/../evil.css").
		$zip_path = $this->make_zip(
			array(
				'config.xml'      => $this->sample_config_xml( 'acme' ),
				'foo/../evil.css' => 'pwn',
			)
		);
		$result = ExeLearning_Styles_Service::validate_zip( $zip_path );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_unsafe_entry', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	/**
	 * Covers the pure ZIP-entry safety matrix without depending on the
	 * ZipArchive build-to-build name-normalization differences.
	 *
	 * @dataProvider unsafe_entry_provider
	 */
	public function test_is_unsafe_zip_entry_matrix( $name, $unsafe ) {
		$this->assertSame( $unsafe, ExeLearning_Styles_Service::is_unsafe_zip_entry( $name ) );
	}

	public function unsafe_entry_provider() {
		return array(
			'empty'             => array( '', true ),
			'backslash'         => array( 'a\\b', true ),
			'absolute'          => array( '/abs.css', true ),
			'stream scheme'     => array( 'http://x', true ),
			'parent at root'    => array( '../evil', true ),
			'parent mid path'   => array( 'a/../b', true ),
			'parent at end'     => array( 'a/..', true ),
			'normal file'       => array( 'style.css', false ),
			'nested file'       => array( 'icons/a.svg', false ),
			'deep nested'       => array( 'img/icons/sub/a.png', false ),
		);
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

	public function test_set_uploaded_enabled_returns_wp_error_on_unknown_slug() {
		$this->assertInstanceOf( 'WP_Error', ExeLearning_Styles_Service::set_uploaded_enabled( 'missing', true ) );
	}

	public function test_delete_uploaded_returns_wp_error_on_unknown_slug() {
		$this->assertInstanceOf( 'WP_Error', ExeLearning_Styles_Service::delete_uploaded( 'missing' ) );
	}

	public function test_is_import_blocked_defaults_to_false() {
		$this->assertFalse( ExeLearning_Styles_Service::is_import_blocked() );
	}

	public function test_is_import_blocked_follows_the_option() {
		ExeLearning_Styles_Service::set_import_blocked( true );
		$this->assertTrue( ExeLearning_Styles_Service::is_import_blocked() );
		ExeLearning_Styles_Service::set_import_blocked( false );
		$this->assertFalse( ExeLearning_Styles_Service::is_import_blocked() );
	}

	public function test_normalize_slug_sanitizes_input() {
		$this->assertSame( 'a-b-c', ExeLearning_Styles_Service::normalize_slug( 'A B C' ) );
		$this->assertSame( 'style', ExeLearning_Styles_Service::normalize_slug( '   ' ) );
	}

	public function test_validate_zip_rejects_missing_file() {
		$result = ExeLearning_Styles_Service::validate_zip( '/nonexistent.zip' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_missing', $result->get_error_code() );
	}

	public function test_validate_zip_rejects_empty_file() {
		$empty = wp_tempnam( 'empty.zip' );
		file_put_contents( $empty, '' );
		$result = ExeLearning_Styles_Service::validate_zip( $empty );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertContains( $result->get_error_code(), array( 'zip_empty', 'zip_missing' ) );
		wp_delete_file( $empty );
	}

	public function test_validate_zip_rejects_non_zip_payload() {
		$notzip = wp_tempnam( 'notzip.zip' );
		file_put_contents( $notzip, 'this is not a zip archive' );
		$result = ExeLearning_Styles_Service::validate_zip( $notzip );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_open_failed', $result->get_error_code() );
		wp_delete_file( $notzip );
	}

	public function test_validate_zip_rejects_multiple_config_files() {
		$zip_path = $this->make_zip(
			array(
				'a/config.xml' => $this->sample_config_xml( 'a' ),
				'b/config.xml' => $this->sample_config_xml( 'b' ),
				'a/style.css'  => 'x{}',
				'b/style.css'  => 'y{}',
			)
		);
		$result = ExeLearning_Styles_Service::validate_zip( $zip_path );
		$this->assertInstanceOf( 'WP_Error', $result );
		wp_delete_file( $zip_path );
	}

	public function test_parse_config_xml_rejects_invalid_xml() {
		$result = ExeLearning_Styles_Service::parse_config_xml( '<<bad xml' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'style_bad_xml', $result->get_error_code() );
	}

	public function test_parse_config_xml_requires_name() {
		$result = ExeLearning_Styles_Service::parse_config_xml(
			'<?xml version="1.0"?><theme></theme>'
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'style_missing_name', $result->get_error_code() );
	}

	public function test_parse_config_xml_accepts_minimum_fields() {
		$result = ExeLearning_Styles_Service::parse_config_xml(
			'<?xml version="1.0"?><theme><name>min</name></theme>'
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'min', $result['name'] );
		$this->assertSame( 'min', $result['title'] );
	}

	public function test_extract_themes_from_bundle_ignores_malformed_entries() {
		$out = ExeLearning_Styles_Service::extract_themes_from_bundle(
			array(
				'themes' => array(
					'themes' => array(
						array( 'title' => 'no-name' ),
						'not-an-array',
						array( 'name' => 'ok', 'title' => 'OK' ),
					),
				),
			)
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'ok', $out[0]['id'] );
	}

	public function test_list_uploaded_styles_skips_scalar_entries() {
		$bad = array(
			'uploaded' => array(
				'good' => array( 'title' => 'Good', 'enabled' => true ),
				'bad'  => 'scalar',
			),
			'disabled_builtins' => array(),
		);
		update_option( ExeLearning_Styles_Service::OPTION_REGISTRY, $bad, false );
		$list = ExeLearning_Styles_Service::list_uploaded_styles();
		$this->assertCount( 1, $list );
		$this->assertSame( 'good', $list[0]['id'] );
	}

	public function test_build_override_skips_non_array_and_disabled_entries() {
		$seed = array(
			'uploaded' => array(
				'on'  => array( 'title' => 'On', 'enabled' => true, 'css_files' => array( 'style.css' ) ),
				'off' => array( 'title' => 'Off', 'enabled' => false ),
				'bad' => 'scalar',
			),
			'disabled_builtins' => array(),
		);
		update_option( ExeLearning_Styles_Service::OPTION_REGISTRY, $seed, false );
		$override = ExeLearning_Styles_Service::build_theme_registry_override();
		$this->assertCount( 1, $override['uploaded'] );
		$this->assertSame( 'on', $override['uploaded'][0]['id'] );
	}

	public function test_allocate_unique_slug_suffixes_around_existing_uploads() {
		$zip = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'duo' ),
				'style.css'  => 'x{}',
			)
		);
		ExeLearning_Styles_Service::install_from_zip( $zip );
		$this->assertSame( 'duo-2', ExeLearning_Styles_Service::allocate_unique_slug( 'duo' ) );
		wp_delete_file( $zip );
	}

	public function test_install_accepts_a_zip_wrapped_in_a_single_root_folder() {
		$zip = $this->make_zip(
			array(
				'acme/config.xml' => $this->sample_config_xml( 'acme' ),
				'acme/style.css'  => 'body{}',
				'acme/img/bg.png' => 'fake',
			)
		);
		$entry = ExeLearning_Styles_Service::install_from_zip( $zip );
		$this->assertIsArray( $entry );
		$dir = ExeLearning_Styles_Service::get_storage_dir() . '/acme';
		$this->assertFileExists( $dir . '/style.css' );
		$this->assertFileExists( $dir . '/img/bg.png' );
		wp_delete_file( $zip );
	}

	public function test_install_rejects_archive_without_any_css() {
		$zip = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'nocss' ),
				'info.md'    => 'no css',
			)
		);
		$result = ExeLearning_Styles_Service::install_from_zip( $zip );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'style_no_css', $result->get_error_code() );
		wp_delete_file( $zip );
	}

	public function test_get_registry_survives_garbage_option_value() {
		update_option( ExeLearning_Styles_Service::OPTION_REGISTRY, 'not-an-array', false );
		$r = ExeLearning_Styles_Service::get_registry();
		$this->assertSame( array(), $r['uploaded'] );
		$this->assertSame( array(), $r['disabled_builtins'] );
	}

	public function test_recursive_delete_handles_missing_path_gracefully() {
		ExeLearning_Styles_Service::recursive_delete( sys_get_temp_dir() . '/does-not-exist-' . uniqid() );
		$this->assertTrue( true );
	}

	public function test_recursive_delete_removes_nested_files() {
		$root = sys_get_temp_dir() . '/deltree-' . uniqid();
		mkdir( $root . '/inner/deep', 0755, true );
		file_put_contents( $root . '/a.txt', 'a' );
		file_put_contents( $root . '/inner/b.txt', 'b' );
		file_put_contents( $root . '/inner/deep/c.txt', 'c' );
		$this->assertDirectoryExists( $root );
		ExeLearning_Styles_Service::recursive_delete( $root );
		$this->assertDirectoryDoesNotExist( $root );
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
		// Default is "imports allowed" — exercise the block toggle
		// explicitly to lock the contract both ways.
		ExeLearning_Styles_Service::set_import_blocked( true );

		$override = ExeLearning_Styles_Service::build_theme_registry_override();
		$this->assertSame( array( 'zen' ), $override['disabledBuiltins'] );
		$this->assertTrue( $override['blockImportInstall'] );
		$this->assertSame( 'base', $override['fallbackTheme'] );
		$this->assertCount( 1, $override['uploaded'] );
		$this->assertSame( 'seen', $override['uploaded'][0]['id'] );

		// Toggle back to the default and confirm the flag follows.
		ExeLearning_Styles_Service::set_import_blocked( false );
		$override = ExeLearning_Styles_Service::build_theme_registry_override();
		$this->assertFalse( $override['blockImportInstall'] );

		// Disabling an upload hides it from the override.
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
