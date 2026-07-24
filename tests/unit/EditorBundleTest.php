<?php
/**
 * Tests for ExeLearning_Editor_Bundle.
 *
 * @package Exelearning
 */

/**
 * Test double pointing the bundle helper at a fixture directory.
 */
class ExeLearning_Editor_Bundle_Fixture extends ExeLearning_Editor_Bundle {

	/**
	 * Fixture directory standing in for the plugin directory.
	 *
	 * @var string
	 */
	public static $plugin_dir = '';

	/**
	 * Return the fixture directory instead of EXELEARNING_PLUGIN_DIR.
	 *
	 * @return string
	 */
	protected static function get_plugin_dir() {
		return self::$plugin_dir;
	}
}

/**
 * Class EditorBundleTest.
 *
 * @covers ExeLearning_Editor_Bundle
 */
class EditorBundleTest extends WP_UnitTestCase {

	/**
	 * Fixture plugin directory.
	 *
	 * @var string
	 */
	private $fixture_dir;

	/**
	 * Set up a fresh fixture directory per test.
	 */
	public function set_up() {
		parent::set_up();
		$this->fixture_dir = trailingslashit( get_temp_dir() ) . 'exelearning-bundle-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->fixture_dir );
		ExeLearning_Editor_Bundle_Fixture::$plugin_dir = $this->fixture_dir;
	}

	/**
	 * Remove the fixture directory.
	 */
	public function tear_down() {
		ExeLearning_Editor_Bundle_Fixture::$plugin_dir = '';
		$this->recursive_delete( $this->fixture_dir );
		parent::tear_down();
	}

	/**
	 * A complete bundle (index.html plus an asset directory) is detected.
	 */
	public function test_valid_bundle_is_available() {
		$this->make_bundle( array( 'app' ) );

		$this->assertTrue( ExeLearning_Editor_Bundle_Fixture::is_available() );
	}

	/**
	 * Any one of the expected asset directories satisfies the check.
	 */
	public function test_each_asset_directory_satisfies_validation() {
		foreach ( ExeLearning_Editor_Bundle::ASSET_DIRS as $dir ) {
			$this->recursive_delete( $this->fixture_dir . '/dist' );
			$this->make_bundle( array( $dir ) );
			$this->assertTrue( ExeLearning_Editor_Bundle_Fixture::is_available(), "Bundle with only {$dir}/ should be valid." );
		}
	}

	/**
	 * Without dist/static/ the bundle is unavailable and editing is disabled.
	 */
	public function test_absent_bundle_is_unavailable() {
		$this->assertFalse( ExeLearning_Editor_Bundle_Fixture::is_available() );
	}

	/**
	 * An index.html without any expected asset directory is rejected.
	 */
	public function test_invalid_bundle_without_asset_dirs_is_rejected() {
		$this->make_bundle( array() );

		$this->assertFalse( ExeLearning_Editor_Bundle_Fixture::is_available() );
	}

	/**
	 * Asset directories without index.html are rejected.
	 */
	public function test_bundle_without_index_is_rejected() {
		wp_mkdir_p( $this->fixture_dir . '/dist/static/app' );

		$this->assertFalse( ExeLearning_Editor_Bundle_Fixture::is_available() );
	}

	/**
	 * get_path points inside dist/static/ of the plugin directory.
	 */
	public function test_get_path_points_at_dist_static() {
		$this->assertSame(
			trailingslashit( $this->fixture_dir ) . 'dist/static/',
			ExeLearning_Editor_Bundle_Fixture::get_path()
		);
		$this->assertStringEndsWith( 'dist/static/', ExeLearning_Editor_Bundle::get_path() );
	}

	/**
	 * The bundled version comes from the trimmed .editor-version marker.
	 */
	public function test_get_version_reads_marker() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->fixture_dir . '/.editor-version', "v4.0.2\n" );

		$this->assertSame( 'v4.0.2', ExeLearning_Editor_Bundle_Fixture::get_version() );
	}

	/**
	 * A missing marker yields an empty version, not an error.
	 */
	public function test_get_version_empty_when_marker_missing() {
		$this->assertSame( '', ExeLearning_Editor_Bundle_Fixture::get_version() );
	}

	/**
	 * Create a fixture bundle under dist/static/.
	 *
	 * @param string[] $asset_dirs Asset directories to create.
	 */
	private function make_bundle( array $asset_dirs ) {
		$static = $this->fixture_dir . '/dist/static';
		wp_mkdir_p( $static );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $static . '/index.html', '<html></html>' );
		foreach ( $asset_dirs as $dir ) {
			wp_mkdir_p( $static . '/' . $dir );
		}
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
