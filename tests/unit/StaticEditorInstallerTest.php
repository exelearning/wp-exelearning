<?php
/**
 * Tests for ExeLearning_Static_Editor_Installer class.
 *
 * @package Exelearning
 */

/**
 * Class StaticEditorInstallerTest.
 *
 * @covers ExeLearning_Static_Editor_Installer
 */
class StaticEditorInstallerTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Static_Editor_Installer
	 */
	private $installer;

	/**
	 * Temporary directories to clean up.
	 *
	 * @var array
	 */
	private $temp_dirs = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->installer = new ExeLearning_Static_Editor_Installer();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		foreach ( $this->temp_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				$this->recursive_delete( $dir );
			}
		}
		delete_option( ExeLearning_Static_Editor_Installer::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Test is_editor_installed returns false when dist/static is missing.
	 */
	public function test_is_editor_installed_returns_false_when_missing() {
		$this->assertFalse( ExeLearning_Static_Editor_Installer::is_editor_installed() );
	}

	/**
	 * Test get_editor_path returns expected path.
	 */
	public function test_get_editor_path() {
		$path = ExeLearning_Static_Editor_Installer::get_editor_path();
		$this->assertStringEndsWith( 'dist/static/', $path );
	}

	/**
	 * Test get_installed_version returns false when no version stored.
	 */
	public function test_get_installed_version_returns_false_when_none() {
		$this->assertFalse( ExeLearning_Static_Editor_Installer::get_installed_version() );
	}

	/**
	 * Test get_installed_version returns data when set.
	 */
	public function test_get_installed_version_returns_data() {
		$data = array(
			'version'      => '4.0.0',
			'installed_at' => '2026-03-22 10:00:00',
		);
		update_option( ExeLearning_Static_Editor_Installer::OPTION_NAME, $data );

		$result = ExeLearning_Static_Editor_Installer::get_installed_version();
		$this->assertIsArray( $result );
		$this->assertEquals( '4.0.0', $result['version'] );
	}

	/**
	 * Test get_installed_version returns false for malformed data.
	 */
	public function test_get_installed_version_false_for_malformed() {
		update_option( ExeLearning_Static_Editor_Installer::OPTION_NAME, 'not-an-array' );
		$this->assertFalse( ExeLearning_Static_Editor_Installer::get_installed_version() );

		update_option( ExeLearning_Static_Editor_Installer::OPTION_NAME, array( 'no_version' => true ) );
		$this->assertFalse( ExeLearning_Static_Editor_Installer::get_installed_version() );
	}

	/**
	 * Test get_asset_url builds correct URL.
	 */
	public function test_get_asset_url() {
		$url = $this->installer->get_asset_url( '4.0.0-beta2' );
		$this->assertEquals(
			'https://github.com/exelearning/exelearning/releases/download/v4.0.0-beta2/exelearning-static-v4.0.0-beta2.zip',
			$url
		);
	}

	/**
	 * Test get_asset_url with simple version.
	 */
	public function test_get_asset_url_simple_version() {
		$url = $this->installer->get_asset_url( '4.0.0' );
		$this->assertStringContainsString( 'exelearning-static-v4.0.0.zip', $url );
	}

	/**
	 * Test validate_zip rejects non-zip files.
	 */
	public function test_validate_zip_rejects_non_zip() {
		$tmp = wp_tempnam();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, 'This is not a ZIP file.' );

		$result = $this->installer->validate_zip( $tmp );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_zip', $result->get_error_code() );

		wp_delete_file( $tmp );
	}

	/**
	 * Test validate_zip accepts valid zip.
	 */
	public function test_validate_zip_accepts_valid() {
		$tmp = wp_tempnam();
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'test.txt', 'hello' );
		$zip->close();

		$result = $this->installer->validate_zip( $tmp );
		$this->assertTrue( $result );

		wp_delete_file( $tmp );
	}

	/**
	 * Test validate_editor_contents rejects dir without index.html.
	 */
	public function test_validate_editor_contents_missing_index() {
		$tmp_dir = $this->create_temp_dir();
		wp_mkdir_p( $tmp_dir . '/app' );

		$result = $this->installer->validate_editor_contents( $tmp_dir );
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_index', $result->get_error_code() );
	}

	/**
	 * Test validate_editor_contents rejects dir without asset dirs.
	 */
	public function test_validate_editor_contents_missing_assets() {
		$tmp_dir = $this->create_temp_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . '/index.html', '<html></html>' );

		$result = $this->installer->validate_editor_contents( $tmp_dir );
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_assets', $result->get_error_code() );
	}

	/**
	 * Test validate_editor_contents accepts valid directory.
	 */
	public function test_validate_editor_contents_valid() {
		$tmp_dir = $this->create_temp_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . '/index.html', '<html></html>' );
		wp_mkdir_p( $tmp_dir . '/app' );

		$result = $this->installer->validate_editor_contents( $tmp_dir );
		$this->assertTrue( $result );
	}

	/**
	 * Test normalize_extraction with files at root.
	 */
	public function test_normalize_extraction_root_files() {
		$tmp_dir = $this->create_temp_dir();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . '/index.html', '<html></html>' );

		$result = $this->installer->normalize_extraction( $tmp_dir );
		$this->assertNotWPError( $result );
		$this->assertStringEndsWith( '/', $result );
	}

	/**
	 * Test normalize_extraction with single top-level directory.
	 */
	public function test_normalize_extraction_single_dir() {
		$tmp_dir = $this->create_temp_dir();
		wp_mkdir_p( $tmp_dir . '/exelearning-static-v4.0.0' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . '/exelearning-static-v4.0.0/index.html', '<html></html>' );

		$result = $this->installer->normalize_extraction( $tmp_dir );
		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'exelearning-static-v4.0.0', $result );
	}

	/**
	 * Test normalize_extraction fails when no index.html found.
	 */
	public function test_normalize_extraction_fails_no_index() {
		$tmp_dir = $this->create_temp_dir();
		wp_mkdir_p( $tmp_dir . '/some-dir' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_dir . '/some-dir/readme.txt', 'hello' );

		$result = $this->installer->normalize_extraction( $tmp_dir );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_layout', $result->get_error_code() );
	}

	/**
	 * Test extract_zip with a valid ZIP.
	 */
	public function test_extract_zip_valid() {
		$tmp = wp_tempnam();
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'index.html', '<html></html>' );
		$zip->close();

		$result = $this->installer->extract_zip( $tmp );
		$this->assertNotWPError( $result );
		$this->assertDirectoryExists( $result );
		$this->assertFileExists( $result . '/index.html' );

		$this->temp_dirs[] = $result;
		wp_delete_file( $tmp );
	}

	/**
	 * Test handle_install_request requires nonce.
	 */
	public function test_handle_install_request_requires_nonce() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// No nonce set - should fail.
		$this->expectException( WPDieException::class );
		$this->installer->handle_install_request();
	}

	/**
	 * Test handle_install_request requires manage_options capability.
	 */
	public function test_handle_install_request_requires_admin() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$_REQUEST['_nonce'] = wp_create_nonce( ExeLearning_Static_Editor_Installer::AJAX_ACTION );

		$this->expectException( WPDieException::class );
		$this->installer->handle_install_request();
	}

	/**
	 * Test discover_latest_version fails gracefully on network error.
	 */
	public function test_discover_latest_version_network_error() {
		// Mock wp_remote_get to return an error.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return new WP_Error( 'http_request_failed', 'Connection timed out' );
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->discover_latest_version();
		$this->assertWPError( $result );
		$this->assertEquals( 'github_request_failed', $result->get_error_code() );
	}

	/**
	 * Test discover_latest_version fails on non-200 response.
	 */
	public function test_discover_latest_version_api_error() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 403 ),
						'body'     => '{"message": "rate limit exceeded"}',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->discover_latest_version();
		$this->assertWPError( $result );
		$this->assertEquals( 'github_api_error', $result->get_error_code() );
	}

	/**
	 * Test discover_latest_version parses version correctly.
	 */
	public function test_discover_latest_version_success() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'tag_name' => 'v4.0.0-beta2',
								'name'     => 'v4.0.0-beta2',
							)
						),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->discover_latest_version();
		$this->assertNotWPError( $result );
		$this->assertEquals( '4.0.0-beta2', $result );
	}

	/**
	 * Test discover_latest_version strips v prefix.
	 */
	public function test_discover_latest_version_strips_prefix() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'tag_name' => 'v5.1.0' ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->discover_latest_version();
		$this->assertEquals( '5.1.0', $result );
	}

	/**
	 * Test download_asset fails gracefully.
	 */
	public function test_download_asset_failure() {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'DNS resolution failed' );
			}
		);

		$result = $this->installer->download_asset( 'https://example.com/fake.zip' );
		$this->assertWPError( $result );
		$this->assertEquals( 'download_failed', $result->get_error_code() );
	}

	/**
	 * Test safe_install method exists.
	 */
	public function test_safe_install_exists() {
		$this->assertTrue( method_exists( $this->installer, 'safe_install' ) );
	}

	/**
	 * Test AJAX action is registered.
	 */
	public function test_ajax_action_registered() {
		$installer = new ExeLearning_Static_Editor_Installer();
		$this->assertGreaterThan(
			0,
			has_action( 'wp_ajax_' . ExeLearning_Static_Editor_Installer::AJAX_ACTION, array( $installer, 'handle_install_request' ) )
		);
	}

	/**
	 * Test constants are defined.
	 */
	public function test_constants_defined() {
		$this->assertEquals( 'exelearning_static_editor', ExeLearning_Static_Editor_Installer::OPTION_NAME );
		$this->assertEquals( 'exelearning_install_editor', ExeLearning_Static_Editor_Installer::AJAX_ACTION );
		$this->assertEquals( 'exelearning-static-v', ExeLearning_Static_Editor_Installer::ASSET_PREFIX );
	}

	/**
	 * Test discover_latest_version rejects invalid version format.
	 */
	public function test_discover_latest_version_rejects_invalid_format() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'tag_name' => 'not-a-version' ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->discover_latest_version();
		$this->assertWPError( $result );
		$this->assertEquals( 'github_parse_error', $result->get_error_code() );
	}

	/**
	 * Test concurrent install is blocked by transient.
	 */
	public function test_concurrent_install_blocked() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_REQUEST['_nonce'] = wp_create_nonce( ExeLearning_Static_Editor_Installer::AJAX_ACTION );

		// Simulate an in-progress installation.
		set_transient( 'exelearning_installing_editor', true, 300 );

		$this->expectException( WPDieException::class );
		$this->installer->handle_install_request();

		delete_transient( 'exelearning_installing_editor' );
	}

	/**
	 * Test discover_latest_version handles malformed JSON.
	 */
	public function test_discover_latest_version_malformed_json() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'not json',
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->discover_latest_version();
		$this->assertWPError( $result );
		$this->assertEquals( 'github_parse_error', $result->get_error_code() );
	}

	/**
	 * Test discover_latest_version handles missing tag_name.
	 */
	public function test_discover_latest_version_missing_tag() {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'name' => 'Some Release' ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->discover_latest_version();
		$this->assertWPError( $result );
		$this->assertEquals( 'github_parse_error', $result->get_error_code() );
	}

	/**
	 * Test install_latest_editor with mocked GitHub and download.
	 */
	public function test_install_latest_editor_download_failure() {
		// Mock GitHub API success but download failure.
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'tag_name' => 'v4.0.0' ) ),
					);
				}
				// Download request.
				if ( strpos( $url, 'github.com' ) !== false && strpos( $url, '.zip' ) !== false ) {
					return new WP_Error( 'http_request_failed', 'Download failed' );
				}
				return $preempt;
			},
			10,
			3
		);

		$result = $this->installer->install_latest_editor();
		$this->assertWPError( $result );
		$this->assertEquals( 'download_failed', $result->get_error_code() );
	}

	/**
	 * Create a temporary directory for tests.
	 *
	 * @return string Path to temp directory.
	 */
	private function create_temp_dir() {
		$tmp_dir = trailingslashit( get_temp_dir() ) . 'exelearning-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $tmp_dir );
		$this->temp_dirs[] = $tmp_dir;
		return $tmp_dir;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$this->recursive_delete( $dir . DIRECTORY_SEPARATOR . $file );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
}
