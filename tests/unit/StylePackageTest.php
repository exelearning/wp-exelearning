<?php
/**
 * Tests for ExeLearning_Style_Package.
 *
 * Exercises the style-ZIP validation, extraction, parsing and metadata logic
 * extracted out of ExeLearning_Styles_Service.
 *
 * @package Exelearning
 */

/**
 * Class StylePackageTest.
 *
 * @covers ExeLearning_Style_Package
 */
class StylePackageTest extends WP_UnitTestCase {

	/**
	 * Default generous max ZIP size used in most tests.
	 *
	 * @var int
	 */
	const MAX = 20971520;

	/**
	 * Directories to clean up after each test.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	public function tear_down() {
		foreach ( $this->temp_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				ExeLearning_Styles_Service::recursive_delete( $dir );
			}
		}
		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * validate()
	 * ------------------------------------------------------------------- */

	public function test_validate_missing_file() {
		$result = ExeLearning_Style_Package::validate( '/no/such/file.zip', self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_missing', $result->get_error_code() );
	}

	public function test_validate_empty_file() {
		$path = wp_tempnam( 'empty.zip' ); // wp_tempnam leaves a 0-byte file.
		$result = ExeLearning_Style_Package::validate( $path, self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_empty', $result->get_error_code() );
		wp_delete_file( $path );
	}

	public function test_validate_too_large() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'acme' ),
				'style.css'  => 'body{}',
			)
		);
		$result = ExeLearning_Style_Package::validate( $zip_path, 1 ); // 1-byte cap.
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_too_large', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_not_a_zip() {
		$path = wp_tempnam( 'notzip.zip' );
		file_put_contents( $path, 'this is not a zip archive' );
		$result = ExeLearning_Style_Package::validate( $path, self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_open_failed', $result->get_error_code() );
		wp_delete_file( $path );
	}

	public function test_validate_missing_config() {
		$zip_path = $this->make_zip( array( 'style.css' => '.x{}' ) );
		$result   = ExeLearning_Style_Package::validate( $zip_path, self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_missing_config', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_multiple_configs() {
		$zip_path = $this->make_zip(
			array(
				'config.xml'   => $this->sample_config_xml( 'a' ),
				'b/config.xml' => $this->sample_config_xml( 'b' ),
				'style.css'    => 'x{}',
			)
		);
		$result = ExeLearning_Style_Package::validate( $zip_path, self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_multiple_configs', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_unsafe_entry() {
		$zip_path = $this->make_zip(
			array(
				'config.xml'      => $this->sample_config_xml( 'acme' ),
				'foo/../evil.css' => 'pwn',
			)
		);
		$result = ExeLearning_Style_Package::validate( $zip_path, self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_unsafe_entry', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_mixed_roots() {
		$zip_path = $this->make_zip(
			array(
				'acme/config.xml' => $this->sample_config_xml( 'acme' ),
				'other/style.css' => 'x{}',
			)
		);
		$result = ExeLearning_Style_Package::validate( $zip_path, self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_mixed_roots', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_bad_extension() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'acme' ),
				'evil.php'   => '<?php echo 1; ?>',
			)
		);
		$result = ExeLearning_Style_Package::validate( $zip_path, self::MAX );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_bad_extension', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	public function test_validate_accepts_root_package() {
		$zip_path = $this->make_zip(
			array(
				'config.xml' => $this->sample_config_xml( 'acme-2026', 'Acme 2026', '1.0.0' ),
				'style.css'  => 'body{}',
			)
		);
		$result = ExeLearning_Style_Package::validate( $zip_path, self::MAX );
		$this->assertIsArray( $result );
		$this->assertSame( 'acme-2026', $result['config']['name'] );
		$this->assertSame( '', $result['prefix'] );
		wp_delete_file( $zip_path );
	}

	public function test_validate_accepts_single_root_folder() {
		$zip_path = $this->make_zip(
			array(
				'acme/config.xml' => $this->sample_config_xml( 'acme' ),
				'acme/style.css'  => 'body{}',
			)
		);
		$result = ExeLearning_Style_Package::validate( $zip_path, self::MAX );
		$this->assertIsArray( $result );
		$this->assertSame( 'acme/', $result['prefix'] );
		wp_delete_file( $zip_path );
	}

	/* ---------------------------------------------------------------------
	 * extract_safely()
	 * ------------------------------------------------------------------- */

	public function test_extract_safely_writes_files() {
		$zip_path = $this->make_zip(
			array(
				'config.xml'      => $this->sample_config_xml( 'acme' ),
				'style.css'       => 'body{color:red}',
				'assets/logo.svg' => '<svg></svg>',
			)
		);
		$dest = $this->temp_dir();
		$result = ExeLearning_Style_Package::extract_safely( $zip_path, $dest, '' );
		$this->assertTrue( $result );
		$this->assertFileExists( $dest . '/style.css' );
		$this->assertFileExists( $dest . '/assets/logo.svg' );
		$this->assertSame( 'body{color:red}', file_get_contents( $dest . '/style.css' ) );
		wp_delete_file( $zip_path );
	}

	public function test_extract_safely_strips_prefix() {
		$zip_path = $this->make_zip(
			array(
				'acme/config.xml' => $this->sample_config_xml( 'acme' ),
				'acme/style.css'  => 'x{}',
			)
		);
		$dest = $this->temp_dir();
		$result = ExeLearning_Style_Package::extract_safely( $zip_path, $dest, 'acme/' );
		$this->assertTrue( $result );
		$this->assertFileExists( $dest . '/style.css' );
		$this->assertFileExists( $dest . '/config.xml' );
		$this->assertFileDoesNotExist( $dest . '/acme/style.css' );
		wp_delete_file( $zip_path );
	}

	public function test_extract_safely_open_failure() {
		$path = wp_tempnam( 'broken.zip' );
		file_put_contents( $path, 'not a zip' );
		$result = ExeLearning_Style_Package::extract_safely( $path, $this->temp_dir(), '' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_open_failed', $result->get_error_code() );
		wp_delete_file( $path );
	}

	public function test_extract_safely_rejects_unsafe_entry() {
		$zip_path = $this->make_zip(
			array(
				'config.xml'      => $this->sample_config_xml( 'acme' ),
				'foo/../evil.css' => 'pwn',
			)
		);
		$result = ExeLearning_Style_Package::extract_safely( $zip_path, $this->temp_dir(), '' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'zip_unsafe_entry', $result->get_error_code() );
		wp_delete_file( $zip_path );
	}

	/* ---------------------------------------------------------------------
	 * parse_config_xml()
	 * ------------------------------------------------------------------- */

	public function test_parse_config_xml_full() {
		$parsed = ExeLearning_Style_Package::parse_config_xml( $this->sample_config_xml( 'neo', 'Neo', '2.0' ) );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'neo', $parsed['name'] );
		$this->assertSame( 'Neo', $parsed['title'] );
		$this->assertSame( '2.0', $parsed['version'] );
		$this->assertSame( 'Test', $parsed['author'] );
	}

	public function test_parse_config_xml_minimum() {
		$parsed = ExeLearning_Style_Package::parse_config_xml( '<?xml version="1.0"?><theme><name>min</name></theme>' );
		$this->assertIsArray( $parsed );
		$this->assertSame( 'min', $parsed['name'] );
		$this->assertSame( 'min', $parsed['title'] );
	}

	public function test_parse_config_xml_bad_xml() {
		$result = ExeLearning_Style_Package::parse_config_xml( 'not-xml<<<' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'style_bad_xml', $result->get_error_code() );
	}

	public function test_parse_config_xml_missing_name() {
		$result = ExeLearning_Style_Package::parse_config_xml( '<?xml version="1.0"?><theme></theme>' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'style_missing_name', $result->get_error_code() );
	}

	/* ---------------------------------------------------------------------
	 * is_unsafe_entry() / is_allowed_filename()
	 * ------------------------------------------------------------------- */

	/**
	 * @dataProvider unsafe_entry_provider
	 */
	public function test_is_unsafe_entry( $name, $unsafe ) {
		$this->assertSame( $unsafe, ExeLearning_Style_Package::is_unsafe_entry( $name ) );
	}

	public function unsafe_entry_provider() {
		return array(
			'empty'           => array( '', true ),
			'backslash'       => array( 'a\\b', true ),
			'absolute'        => array( '/abs.css', true ),
			'stream scheme'   => array( 'phar://x', true ),
			'parent at root'  => array( '../evil', true ),
			'parent mid path' => array( 'a/../b', true ),
			'parent at end'   => array( 'a/..', true ),
			'normal file'     => array( 'style.css', false ),
			'nested file'     => array( 'icons/a.svg', false ),
		);
	}

	/**
	 * @dataProvider allowed_filename_provider
	 */
	public function test_is_allowed_filename( $name, $allowed ) {
		$this->assertSame( $allowed, ExeLearning_Style_Package::is_allowed_filename( $name ) );
	}

	public function allowed_filename_provider() {
		return array(
			'css'          => array( 'style.css', true ),
			'svg nested'   => array( 'icons/a.svg', true ),
			'php'          => array( 'evil.php', false ),
			'no extension' => array( 'Makefile', false ),
			'directory'    => array( 'assets/', false ),
			'empty'        => array( '', false ),
		);
	}

	/* ---------------------------------------------------------------------
	 * find_css_files()
	 * ------------------------------------------------------------------- */

	public function test_find_css_files_prioritizes_style_css() {
		$dir = $this->temp_dir();
		file_put_contents( $dir . '/theme.css', 'a{}' );
		file_put_contents( $dir . '/style.css', 'b{}' );
		$found = ExeLearning_Style_Package::find_css_files( $dir );
		$this->assertSame( 'style.css', $found[0] );
		$this->assertContains( 'theme.css', $found );
	}

	public function test_find_css_files_empty_when_none() {
		$this->assertSame( array(), ExeLearning_Style_Package::find_css_files( $this->temp_dir() ) );
	}

	/* ---------------------------------------------------------------------
	 * extract_themes_from_bundle()
	 * ------------------------------------------------------------------- */

	public function test_extract_themes_double_nested() {
		$out = ExeLearning_Style_Package::extract_themes_from_bundle(
			array(
				'themes' => array(
					'themes' => array(
						array( 'name' => 'neo', 'title' => 'Neo', 'version' => '2025' ),
						array( 'name' => 'base' ),
					),
				),
			)
		);
		$this->assertCount( 2, $out );
		$this->assertSame( 'neo', $out[0]['id'] );
		$this->assertSame( 'base', $out[1]['title'] ); // Falls back to name.
	}

	public function test_extract_themes_flat() {
		$out = ExeLearning_Style_Package::extract_themes_from_bundle(
			array( 'themes' => array( array( 'name' => 'alpha' ) ) )
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'alpha', $out[0]['name'] );
	}

	public function test_extract_themes_empty_and_malformed() {
		$this->assertSame( array(), ExeLearning_Style_Package::extract_themes_from_bundle( array() ) );
		$this->assertSame( array(), ExeLearning_Style_Package::extract_themes_from_bundle( array( 'themes' => 'nope' ) ) );
		$out = ExeLearning_Style_Package::extract_themes_from_bundle(
			array(
				'themes' => array(
					'themes' => array(
						array( 'title' => 'no-name' ),
						'scalar',
						array( 'name' => 'ok' ),
					),
				),
			)
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'ok', $out[0]['id'] );
	}

	/* ---------------------------------------------------------------------
	 * build_entry()
	 * ------------------------------------------------------------------- */

	public function test_build_entry_full() {
		$zip_path = $this->make_zip( array( 'config.xml' => $this->sample_config_xml( 'acme' ) ) );
		$config   = array(
			'title'       => 'Acme',
			'version'     => '1.2.3',
			'author'      => 'Me',
			'license'     => 'MIT',
			'description' => 'Desc',
		);
		$entry = ExeLearning_Style_Package::build_entry( $config, 'acme', $zip_path, array( 'style.css' ) );
		$this->assertSame( 'Acme', $entry['title'] );
		$this->assertSame( '1.2.3', $entry['version'] );
		$this->assertTrue( $entry['enabled'] );
		$this->assertSame( array( 'style.css' ), $entry['css_files'] );
		$this->assertStringStartsWith( 'sha256:', $entry['checksum'] );
		$this->assertGreaterThan( 0, $entry['size'] );
		wp_delete_file( $zip_path );
	}

	public function test_build_entry_falls_back_to_slug_title() {
		$entry = ExeLearning_Style_Package::build_entry( array(), 'fallback', '/no/such/file.zip', array() );
		$this->assertSame( 'fallback', $entry['title'] );
		$this->assertSame( '', $entry['version'] );
		$this->assertSame( '', $entry['checksum'] );
		$this->assertSame( 0, $entry['size'] );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Create an empty temp directory tracked for cleanup.
	 *
	 * @return string Directory path (no trailing slash).
	 */
	private function temp_dir() {
		$dir = wp_tempnam( 'style-pkg' );
		wp_delete_file( $dir );
		wp_mkdir_p( $dir );
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Build a ZIP file containing the given entries in a temp location.
	 *
	 * @param array<string,string> $entries Map of entry-name => contents.
	 * @return string Absolute path to the created ZIP.
	 */
	private function make_zip( array $entries ) {
		$path = wp_tempnam( 'pkg-test.zip' );
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

	/* ---------------------------------------------------------------------
	 * Security: XXE + field sanitization
	 * ------------------------------------------------------------------- */

	public function test_parse_config_xml_rejects_doctype_xxe() {
		$xxe = '<?xml version="1.0"?>'
			. '<!DOCTYPE config [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<theme><name>t</name><title>&xxe;</title></theme>';
		$result = ExeLearning_Style_Package::parse_config_xml( $xxe );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'style_bad_xml', $result->get_error_code() );
	}

	public function test_parse_config_xml_does_not_disclose_files_via_entity() {
		// Even if a DOCTYPE somehow parsed, the entity must not be substituted
		// with file contents. We assert the title never contains a known token.
		$tmp = wp_tempnam( 'secret' );
		file_put_contents( $tmp, 'TOPSECRET-XXE-TOKEN' );
		$xxe = '<?xml version="1.0"?>'
			. '<!DOCTYPE config [<!ENTITY xxe SYSTEM "file://' . $tmp . '">]>'
			. '<theme><name>t</name><title>&xxe;</title></theme>';
		$result = ExeLearning_Style_Package::parse_config_xml( $xxe );
		// DOCTYPE is rejected outright, so this is a WP_Error and nothing leaks.
		$this->assertInstanceOf( 'WP_Error', $result );
		if ( is_array( $result ) ) {
			$this->assertStringNotContainsString( 'TOPSECRET-XXE-TOKEN', $result['title'] );
		}
		wp_delete_file( $tmp );
	}

	public function test_parse_config_xml_sanitizes_markup_fields() {
		$xml = '<?xml version="1.0"?><theme>'
			. '<name>t</name>'
			. '<title>A&lt;img src=x onerror=alert(1)&gt;B</title>'
			. '<description>D&lt;script&gt;evil&lt;/script&gt;</description>'
			. '</theme>';
		$result = ExeLearning_Style_Package::parse_config_xml( $xml );
		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<', $result['title'] );
		$this->assertStringNotContainsString( '<', $result['description'] );
	}
}
