<?php
/**
 * Tests for ExeLearning_Preview_Serving_Controller (serving contract v2 read side).
 *
 * @package Exelearning
 */

/**
 * Class PreviewServingControllerTest.
 *
 * @covers ExeLearning_Preview_Serving_Controller
 */
class PreviewServingControllerTest extends WP_UnitTestCase {

	const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

	/**
	 * Byte-identical CSP from doc/development/preview-serving-contract.md.
	 *
	 * @var string
	 */
	const EXPECTED_CSP = "sandbox allow-scripts allow-popups allow-forms; default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self';";

	/**
	 * @var string
	 */
	private $base;

	/**
	 * @var ExeLearning_Preview_Session_Store
	 */
	private $store;

	/**
	 * @var ExeLearning_Preview_Fixed_Resources
	 */
	private $fixed;

	/**
	 * @var ExeLearning_Preview_Serving_Controller
	 */
	private $serving;

	/**
	 * @var int
	 */
	private $author;

	public function set_up() {
		parent::set_up();
		$this->base = trailingslashit( get_temp_dir() ) . 'exe-preview-serving-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->base . '/store' );
		wp_mkdir_p( $this->base . '/tmp' );
		wp_mkdir_p( $this->base . '/dist' );
		$this->store   = new ExeLearning_Preview_Session_Store( $this->base . '/store' );
		$this->fixed   = new ExeLearning_Preview_Fixed_Resources( $this->base . '/dist' );
		$this->serving = new ExeLearning_Preview_Serving_Controller( $this->store, $this->fixed );
		$this->author  = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	public function tear_down() {
		$this->rrmdir( $this->base );
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

	private function tmp_with( $bytes ) {
		$path = $this->base . '/tmp/' . wp_generate_password( 12, false );
		file_put_contents( $path, $bytes );
		return $path;
	}

	private function invoke( $method, $args ) {
		$ref = new ReflectionMethod( ExeLearning_Preview_Serving_Controller::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->serving, $args );
	}

	private function create_session_id() {
		return $this->store->create_session( $this->author )['previewId'];
	}

	private function publish_document( $id, $path, $bytes ) {
		$meta = $this->store->get_owned_session( $id, $this->author )['meta'];
		$this->store->apply_revision(
			$id,
			array(
				'baseRevision' => $meta['revision'],
				'nextRevision' => $meta['revision'] + 1,
				'writes'       => array( array( 'path' => $path, 'tmp_path' => $this->tmp_with( $bytes ) ) ),
				'deletes'      => array(),
				'assetRefs'    => array(),
				'fixedRefs'    => array(),
			),
			$this->fixed
		);
	}

	// ---- pure range / conditional helpers --------------------------------

	public function test_parse_range_satisfiable_and_unsatisfiable() {
		$this->assertNull( $this->invoke( 'parse_range', array( null, 10 ) ) );
		$this->assertNull( $this->invoke( 'parse_range', array( '', 10 ) ) );
		$this->assertSame( array( 'start' => 2, 'end' => 4 ), $this->invoke( 'parse_range', array( 'bytes=2-4', 10 ) ) );
		$this->assertSame( array( 'start' => 2, 'end' => 9 ), $this->invoke( 'parse_range', array( 'bytes=2-', 10 ) ) );
		$this->assertSame( array( 'start' => 7, 'end' => 9 ), $this->invoke( 'parse_range', array( 'bytes=-3', 10 ) ) );
		$this->assertSame( array( 'start' => 0, 'end' => 9 ), $this->invoke( 'parse_range', array( 'bytes=0-100', 10 ) ) );
		// A syntactically valid single range past the end is 416.
		$this->assertSame( 'unsatisfiable', $this->invoke( 'parse_range', array( 'bytes=99-', 10 ) ) );
		$this->assertSame( 'unsatisfiable', $this->invoke( 'parse_range', array( 'bytes=-0', 10 ) ) );
	}

	public function test_parse_range_ignores_malformed_and_multirange() {
		// Contract v2: malformed / multi-range / non-bytes unit are IGNORED
		// (null -> full 200), never 416.
		$this->assertNull( $this->invoke( 'parse_range', array( 'items=0-1', 10 ) ) );
		$this->assertNull( $this->invoke( 'parse_range', array( 'bytes=0-1,3-4', 10 ) ) );
		$this->assertNull( $this->invoke( 'parse_range', array( 'bytes=abc', 10 ) ) );
		$this->assertNull( $this->invoke( 'parse_range', array( 'bytes=-', 10 ) ) );
		// last-byte-pos < first-byte-pos is an invalid spec -> ignore -> full 200.
		$this->assertNull( $this->invoke( 'parse_range', array( 'bytes=5-2', 10 ) ) );
	}

	public function test_if_none_match() {
		$this->assertFalse( $this->invoke( 'if_none_match', array( null, 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( '"x"', 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( 'W/"x"', 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( '"a", "x"', 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( '*', 'x' ) ) );
		$this->assertFalse( $this->invoke( 'if_none_match', array( '"y"', 'x' ) ) );
	}

	public function test_stream_range_emits_slice() {
		$path = $this->tmp_with( '0123456789' );
		ob_start();
		$this->invoke( 'stream_range', array( $path, 2, 3 ) );
		$this->assertSame( '234', ob_get_clean() );
	}

	// ---- serving (build_serve_response) ----------------------------------

	public function test_bare_capability_url_redirects_to_index() {
		$id = $this->create_session_id();
		// A bare {previewId} (empty file) never serves index.html bytes: 302 so
		// a served page's relative subresource URLs resolve against index.html.
		$resp = $this->serving->build_serve_response( $id, '' );
		$this->assertSame( 302, $resp['status'] );
		$this->assertStringContainsString( '/preview/' . $id . '/index.html', $resp['headers']['Location'] );
		$this->assertSame( 'no-store', $resp['headers']['Cache-Control'] );
		$this->assertSame( 'nosniff', $resp['headers']['X-Content-Type-Options'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $resp['headers'] );
		$this->assertSame( 'none', $resp['body']['kind'] );
	}

	public function test_serve_response_unknown_is_hardened_404() {
		$resp = $this->serving->build_serve_response( 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'index.html' );
		$this->assertSame( 404, $resp['status'] );
		$this->assertSame( 'nosniff', $resp['headers']['X-Content-Type-Options'] );
		$this->assertSame( 'no-store', $resp['headers']['Cache-Control'] );
		$this->assertSame( '*', $resp['headers']['Access-Control-Allow-Origin'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $resp['headers'] );
	}

	public function test_serve_response_document_carries_csp_and_no_store() {
		$id = $this->create_session_id();
		$this->publish_document( $id, 'index.html', '<html>x</html>' );
		$resp = $this->serving->build_serve_response( $id, 'index.html' );
		$this->assertSame( 200, $resp['status'] );
		$this->assertSame( 'text/html; charset=utf-8', $resp['headers']['Content-Type'] );
		$this->assertSame( 'no-store', $resp['headers']['Cache-Control'] );
		$this->assertSame( self::EXPECTED_CSP, $resp['headers']['Content-Security-Policy'] );
		$this->assertSame( 'file', $resp['body']['kind'] );
	}

	public function test_serve_response_asset_tier_and_conditional() {
		$id = $this->create_session_id();
		$this->store->store_assets(
			$id,
			array( array( 'key' => self::PHOTO_KEY, 'declaredSize' => 10, 'tmp_path' => $this->tmp_with( '0123456789' ) ) )
		);
		$this->store->apply_revision(
			$id,
			array(
				'baseRevision' => 0,
				'nextRevision' => 1,
				'writes'       => array(),
				'deletes'      => array(),
				'assetRefs'    => array( 'm/clip.png' => self::PHOTO_KEY ),
				'fixedRefs'    => array(),
			),
			$this->fixed
		);

		// Plain GET: no-cache, ETag, Accept-Ranges, png -> no CSP.
		$resp = $this->serving->build_serve_response( $id, 'm/clip.png' );
		$this->assertSame( 200, $resp['status'] );
		$this->assertSame( 'no-cache', $resp['headers']['Cache-Control'] );
		$this->assertSame( '"' . self::PHOTO_KEY . '"', $resp['headers']['ETag'] );
		$this->assertSame( 'bytes', $resp['headers']['Accept-Ranges'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $resp['headers'] );

		// Conditional -> 304.
		$resp304 = $this->serving->build_serve_response( $id, 'm/clip.png', array( 'if_none_match' => '"' . self::PHOTO_KEY . '"' ) );
		$this->assertSame( 304, $resp304['status'] );

		// Range -> 206.
		$resp206 = $this->serving->build_serve_response( $id, 'm/clip.png', array( 'range' => 'bytes=2-4' ) );
		$this->assertSame( 206, $resp206['status'] );
		$this->assertSame( 'bytes 2-4/10', $resp206['headers']['Content-Range'] );
		$this->assertSame( '3', $resp206['headers']['Content-Length'] );
		$this->assertSame( 'range', $resp206['body']['kind'] );

		// Unsatisfiable range -> 416.
		$resp416 = $this->serving->build_serve_response( $id, 'm/clip.png', array( 'range' => 'bytes=99-' ) );
		$this->assertSame( 416, $resp416['status'] );
		$this->assertSame( 'bytes */10', $resp416['headers']['Content-Range'] );

		// Malformed / multi-range -> ignored -> full 200 (not 416).
		$resp_full = $this->serving->build_serve_response( $id, 'm/clip.png', array( 'range' => 'bytes=0-1,3-4' ) );
		$this->assertSame( 200, $resp_full['status'] );
		$this->assertSame( '10', $resp_full['headers']['Content-Length'] );
		$this->assertArrayNotHasKey( 'Content-Range', $resp_full['headers'] );

		$resp_unit = $this->serving->build_serve_response( $id, 'm/clip.png', array( 'range' => 'items=0-1' ) );
		$this->assertSame( 200, $resp_unit['status'] );
	}

	public function test_serve_response_fixed_tier_and_scriptable_svg() {
		wp_mkdir_p( $this->base . '/dist/files' );
		wp_mkdir_p( $this->base . '/dist/bundles' );
		file_put_contents( $this->base . '/dist/files/icon.svg', '<svg><script>1</script></svg>' );
		file_put_contents(
			$this->base . '/dist/bundles/preview-fixed-resources.json',
			wp_json_encode(
				array(
					'schemaVersion' => 1,
					'buildVersion'  => 'test',
					'resources'     => array( 'theme:icon' => array( 'path' => 'files/icon.svg', 'size' => 29 ) ),
				)
			),
			LOCK_EX
		);
		$fixed   = new ExeLearning_Preview_Fixed_Resources( $this->base . '/dist' );
		$serving = new ExeLearning_Preview_Serving_Controller( $this->store, $fixed );

		$id = $this->create_session_id();
		$this->store->apply_revision(
			$id,
			array(
				'baseRevision' => 0,
				'nextRevision' => 1,
				'writes'       => array(),
				'deletes'      => array(),
				'assetRefs'    => array(),
				'fixedRefs'    => array( 'theme/icon.svg' => 'theme:icon' ),
			),
			$fixed
		);

		$resp = $serving->build_serve_response( $id, 'theme/icon.svg' );
		$this->assertSame( 200, $resp['status'] );
		$this->assertSame( 'private, max-age=31536000', $resp['headers']['Cache-Control'] );
		$this->assertSame( 'image/svg+xml; charset=utf-8', $resp['headers']['Content-Type'] );
		$this->assertSame( self::EXPECTED_CSP, $resp['headers']['Content-Security-Policy'] );
	}

	public function test_serve_after_delete_is_404() {
		$id = $this->create_session_id();
		$this->publish_document( $id, 'index.html', '<html>x</html>' );
		$this->store->delete_session( $id );
		$this->assertSame( 404, $this->serving->build_serve_response( $id, 'index.html' )['status'] );
	}

	public function test_uninjected_serving_builds_default_dependencies() {
		$serving = new ExeLearning_Preview_Serving_Controller();
		// A bad capability id is rejected before any filesystem access, but it
		// still exercises the lazy store()/fixed() getters.
		$this->assertSame( 404, $serving->build_serve_response( 'not-a-valid-uuid', 'index.html' )['status'] );
	}
}
