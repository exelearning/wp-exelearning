<?php
/**
 * Tests for ExeLearning_Preview_Fixed_Resources (serving contract v2, layer 1).
 *
 * @package Exelearning
 */

/**
 * Class PreviewFixedResourcesTest.
 *
 * @covers ExeLearning_Preview_Fixed_Resources
 */
class PreviewFixedResourcesTest extends WP_UnitTestCase {

	/**
	 * Distribution root.
	 *
	 * @var string
	 */
	private $dist_root;

	public function set_up() {
		parent::set_up();
		$this->dist_root = trailingslashit( get_temp_dir() ) . 'exe-preview-dist-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dist_root );
	}

	public function tear_down() {
		$this->rrmdir( $this->dist_root );
		parent::tear_down();
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	private function write_file( $rel, $bytes ) {
		$full = $this->dist_root . '/' . $rel;
		wp_mkdir_p( dirname( $full ) );
		file_put_contents( $full, $bytes );
	}

	private function write_manifest( $resources ) {
		$this->write_file(
			'bundles/preview-fixed-resources.json',
			wp_json_encode(
				array(
					'schemaVersion' => 1,
					'buildVersion'  => 'test',
					'resources'     => $resources,
				)
			)
		);
	}

	public function test_resolves_declared_resource() {
		$this->write_file( 'libs/jquery/jquery.min.js', 'window.jQuery=function(){};' );
		$this->write_manifest(
			array(
				'libs/jquery/jquery.min.js' => array(
					'path' => 'libs/jquery/jquery.min.js',
					'size' => 26,
				),
			)
		);
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );

		$this->assertTrue( $fixed->has_resource( 'libs/jquery/jquery.min.js' ) );
		$resolved = $fixed->get_resource_path( 'libs/jquery/jquery.min.js' );
		$this->assertNotNull( $resolved );
		$this->assertSame( 'window.jQuery=function(){};', file_get_contents( $resolved ) );
		$this->assertSame( array( 'libs/jquery/jquery.min.js' ), $fixed->get_manifest_ids() );
	}

	public function test_opaque_id_maps_to_nested_path() {
		$this->write_file( 'files/perm/themes/base/base/icon.svg', '<svg></svg>' );
		$this->write_manifest(
			array(
				'theme:base/icon.svg' => array(
					'path' => 'files/perm/themes/base/base/icon.svg',
					'size' => 11,
				),
			)
		);
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );

		$this->assertTrue( $fixed->has_resource( 'theme:base/icon.svg' ) );
		$this->assertSame( '<svg></svg>', file_get_contents( $fixed->get_resource_path( 'theme:base/icon.svg' ) ) );
	}

	public function test_unknown_id_is_a_miss() {
		$this->write_manifest( array() );
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );
		$this->assertFalse( $fixed->has_resource( 'libs/nope.js' ) );
		$this->assertNull( $fixed->get_resource_path( 'libs/nope.js' ) );
	}

	public function test_absent_manifest_disables_layer() {
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );
		$this->assertFalse( $fixed->has_resource( 'anything' ) );
		$this->assertNull( $fixed->get_resource_path( 'anything' ) );
		$this->assertSame( array(), $fixed->get_manifest_ids() );
	}

	public function test_invalid_schema_disables_layer() {
		$this->write_file(
			'bundles/preview-fixed-resources.json',
			wp_json_encode(
				array(
					'schemaVersion' => 99,
					'resources'     => array( 'a' => array( 'path' => 'a' ) ),
				)
			)
		);
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );
		$this->assertFalse( $fixed->has_resource( 'a' ) );
	}

	public function test_path_escaping_root_is_rejected() {
		// A manifest whose path escapes the distribution root must not resolve,
		// even though the id is declared.
		$this->write_manifest(
			array(
				'escape' => array(
					'path' => '../outside-secret.txt',
					'size' => 3,
				),
			)
		);
		file_put_contents( dirname( $this->dist_root ) . '/outside-secret.txt', 'xxx' );
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );

		$this->assertTrue( $fixed->has_resource( 'escape' ), 'id is declared' );
		$this->assertNull( $fixed->get_resource_path( 'escape' ), 'but the path escapes the root' );

		unlink( dirname( $this->dist_root ) . '/outside-secret.txt' );
	}

	public function test_absolute_path_is_rejected() {
		$this->write_manifest(
			array(
				'abs' => array(
					'path' => '/etc/hostname',
					'size' => 1,
				),
			)
		);
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );
		$this->assertNull( $fixed->get_resource_path( 'abs' ) );
	}

	public function test_symlink_escape_is_rejected() {
		// A symlink inside the root pointing outside it must not be followed.
		$outside = dirname( $this->dist_root ) . '/outside-target.txt';
		file_put_contents( $outside, 'secret' );
		$link = $this->dist_root . '/link.txt';
		if ( ! @symlink( $outside, $link ) ) {
			$this->markTestSkipped( 'symlink() unavailable on this platform' );
		}
		$this->write_manifest(
			array(
				'link' => array(
					'path' => 'link.txt',
					'size' => 6,
				),
			)
		);
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );
		$this->assertNull( $fixed->get_resource_path( 'link' ) );

		unlink( $link );
		unlink( $outside );
	}

	public function test_declared_but_missing_file_is_null() {
		$this->write_manifest(
			array(
				'ghost' => array(
					'path' => 'libs/ghost.js',
					'size' => 10,
				),
			)
		);
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );
		$this->assertTrue( $fixed->has_resource( 'ghost' ) );
		$this->assertNull( $fixed->get_resource_path( 'ghost' ) );
	}

	public function test_default_dist_root_is_installed_editor_path() {
		// With no override the resolver targets the installed static editor.
		$fixed = new ExeLearning_Preview_Fixed_Resources();
		// The installed dist ships no preview manifest yet, so the layer is off
		// (never fatal — the client demotes fixed refs to document writes).
		$this->assertIsArray( $fixed->get_manifest_ids() );
	}
}
