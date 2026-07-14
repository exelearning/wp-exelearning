<?php
/**
 * Tests for complete opaque editor-preview snapshots.
 *
 * @package Exelearning
 */

/**
 * Preview snapshot store tests.
 */
class PreviewSnapshotStoreTest extends WP_UnitTestCase {

	/** @var string Test storage root. */
	private $root;

	/** @var string[] Temporary ZIP paths. */
	private $zip_files = array();

	/** Set up private test storage. */
	public function set_up() {
		parent::set_up();
		$this->root = trailingslashit( get_temp_dir() ) . 'exe-preview-test-' . wp_generate_password( 12, false );
	}

	/** Remove test storage. */
	public function tear_down() {
		$this->remove_tree( $this->root );
		foreach ( $this->zip_files as $file ) {
			@unlink( $file );
		}
		parent::tear_down();
	}

	/** Complete snapshots can be replaced and served by one capability. */
	public function test_create_replace_serve_and_delete() {
		$store = new ExeLearning_Preview_Snapshot_Store( $this->root );
		$id    = $store->replace( 7, 42, $this->zip( array( 'index.html' => 'first' ) ) );
		$this->assertIsString( $id );
		$first = $store->get( $id, 'index.html' );
		$this->assertSame( 'first', file_get_contents( $first['path'] ) );

		$result = $store->replace(
			7,
			42,
			$this->zip( array( 'index.html' => 'second', 'assets/app.js' => 'run()' ) ),
			$id
		);
		$this->assertSame( $id, $result );
		$this->assertSame( 'second', file_get_contents( $store->get( $id, 'index.html' )['path'] ) );
		$this->assertSame( 'application/javascript; charset=utf-8', $store->get( $id, 'assets/app.js' )['mime'] );
		$this->assertTrue( $store->delete( 7, 42, $id ) );
		$this->assertNull( $store->get( $id, 'index.html' ) );
	}

	/** Unsafe archives, metadata access, and cross-owner updates are rejected. */
	public function test_security_boundaries() {
		$store = new ExeLearning_Preview_Snapshot_Store( $this->root );
		$bad   = $store->replace( 7, 42, $this->zip( array( '../index.html' => 'escape' ) ) );
		$this->assertWPError( $bad );

		$missing = $store->replace( 7, 42, $this->zip( array( 'other.html' => 'missing' ) ) );
		$this->assertWPError( $missing );

		$id = $store->replace( 7, 42, $this->zip( array( 'index.html' => 'ok' ) ) );
		$this->assertNull( $store->get( $id, '%2e%2e/index.html' ) );
		$this->assertNull( $store->get( $id, '.metadata.json' ) );
		$this->assertWPError( $store->replace( 8, 42, $this->zip( array( 'index.html' => 'no' ) ), $id ) );
		$this->assertWPError( $store->delete( 7, 99, $id ) );
	}

	/** Idle capabilities expire and are removed. */
	public function test_expiry() {
		$now   = 1000;
		$clock = static function () use ( &$now ) {
			return $now;
		};
		$store = new ExeLearning_Preview_Snapshot_Store( $this->root, $clock );
		$id    = $store->replace( 7, 42, $this->zip( array( 'index.html' => 'ok' ) ) );
		$now   = 2801;
		$this->assertNull( $store->get( $id, 'index.html' ) );
		$this->assertDirectoryDoesNotExist( trailingslashit( $this->root ) . $id );
	}

	/** The REST contract registers public serving and protected management routes. */
	public function test_routes_and_management_permission() {
		$proxy = new ExeLearning_Preview_Proxy( new ExeLearning_Preview_Snapshot_Store( $this->root ) );
		$this->assertStringContainsString( 'allow-downloads', ExeLearning_Preview_Proxy::SANDBOX_CSP );
		$this->assertStringContainsString( 'allow-presentation', ExeLearning_Preview_Proxy::SANDBOX_CSP );
		$this->assertStringNotContainsString( 'allow-same-origin', ExeLearning_Preview_Proxy::SANDBOX_CSP );
		$proxy->register_routes();
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/exelearning/v1/preview-session/(?P<attachmentId>\d+)', $routes );
		$this->assertArrayHasKey(
			'/exelearning/v1/preview/(?P<previewId>' . ExeLearning_Preview_Proxy::PREVIEW_ID_PATTERN . ')(?:/(?P<file>.*))?',
			$routes
		);

		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', 0, array( 'post_author' => 1 ) );
		$request       = new WP_REST_Request();
		$request->set_param( 'attachmentId', $attachment_id );
		wp_set_current_user( 0 );
		$this->assertFalse( $proxy->can_manage_preview( $request ) );
	}

	/**
	 * Create a ZIP fixture.
	 *
	 * @param array<string,string> $files Archive contents.
	 * @return string ZIP path.
	 */
	private function zip( $files ) {
		$path = tempnam( get_temp_dir(), 'exe-preview-' );
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		foreach ( $files as $name => $content ) {
			$this->assertTrue( $zip->addFromString( $name, $content ) );
		}
		$zip->close();
		$this->zip_files[] = $path;
		return $path;
	}

	/** @param string $path Directory path. */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( new FilesystemIterator( $path ) as $entry ) {
			$entry->isDir() ? $this->remove_tree( $entry->getPathname() ) : @unlink( $entry->getPathname() );
		}
		@rmdir( $path );
	}
}
