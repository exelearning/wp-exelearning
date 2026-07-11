<?php
/**
 * Tests for ExeLearning_Preview_Proxy (serving contract v2 HTTP adapter).
 *
 * @package Exelearning
 */

/**
 * Class PreviewProxyTest.
 *
 * @covers ExeLearning_Preview_Proxy
 */
class PreviewProxyTest extends WP_UnitTestCase {

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
	 * @var ExeLearning_Preview_Proxy
	 */
	private $proxy;

	/**
	 * @var int
	 */
	private $author;

	public function set_up() {
		parent::set_up();
		$this->base = trailingslashit( get_temp_dir() ) . 'exe-preview-proxy-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->base . '/store' );
		wp_mkdir_p( $this->base . '/tmp' );
		wp_mkdir_p( $this->base . '/dist' );
		$this->store  = new ExeLearning_Preview_Session_Store( $this->base . '/store' );
		$this->fixed  = new ExeLearning_Preview_Fixed_Resources( $this->base . '/dist' );
		$this->proxy  = new ExeLearning_Preview_Proxy( $this->store, $this->fixed );
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
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
		$ref = new ReflectionMethod( ExeLearning_Preview_Proxy::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->proxy, $args );
	}

	/** Build a POST request carrying a JSON field plus index-aligned files[]. */
	private function multipart_request( $preview_id, $field, $json, $contents ) {
		$req = new WP_REST_Request( 'POST', '/exelearning/v1/preview-session' );
		$req->set_header( 'Content-Type', 'multipart/form-data; boundary=b' );
		$req->set_url_params( array( 'previewId' => $preview_id ) );
		$req->set_body_params( array( $field => $json ) );

		$tmp_names = array();
		$sizes     = array();
		$errors    = array();
		$names      = array();
		foreach ( $contents as $i => $bytes ) {
			$tmp_names[] = $this->tmp_with( $bytes );
			$sizes[]     = strlen( $bytes );
			$errors[]    = UPLOAD_ERR_OK;
			$names[]     = 'f' . $i;
		}
		if ( ! empty( $contents ) ) {
			$req->set_file_params(
				array(
					'files' => array(
						'name'     => $names,
						'tmp_name' => $tmp_names,
						'size'     => $sizes,
						'error'    => $errors,
					),
				)
			);
		}
		return $req;
	}

	private function create_session_id() {
		$resp = $this->proxy->create_session( new WP_REST_Request( 'POST', '/x' ) );
		return $resp->get_data()['previewId'];
	}

	// ---- constants & helpers ---------------------------------------------

	public function test_sandbox_csp_is_byte_identical_to_core() {
		$this->assertSame( self::EXPECTED_CSP, ExeLearning_Preview_Proxy::SANDBOX_CSP );
		$this->assertStringStartsWith( 'sandbox allow-scripts allow-popups allow-forms', ExeLearning_Preview_Proxy::SANDBOX_CSP );
	}

	public function test_scriptable_types_cover_all_document_kinds() {
		$this->assertTrue( $this->invoke( 'is_scriptable', array( 'text/html; charset=utf-8' ) ) );
		$this->assertTrue( $this->invoke( 'is_scriptable', array( 'image/svg+xml' ) ) );
		$this->assertTrue( $this->invoke( 'is_scriptable', array( 'application/xml' ) ) );
		$this->assertTrue( $this->invoke( 'is_scriptable', array( 'application/xhtml+xml' ) ) );
		$this->assertFalse( $this->invoke( 'is_scriptable', array( 'image/png' ) ) );
		$this->assertFalse( $this->invoke( 'is_scriptable', array( 'text/css' ) ) );
	}

	public function test_content_type_appends_charset_to_textual_types() {
		$this->assertSame( 'text/html; charset=utf-8', $this->invoke( 'content_type_for', array( 'index.html' ) ) );
		$this->assertSame( 'image/svg+xml; charset=utf-8', $this->invoke( 'content_type_for', array( 'a.svg' ) ) );
		$this->assertSame( 'application/javascript; charset=utf-8', $this->invoke( 'content_type_for', array( 'a.js' ) ) );
		$this->assertSame( 'image/png', $this->invoke( 'content_type_for', array( 'a.png' ) ) );
		$this->assertSame( 'application/octet-stream', $this->invoke( 'content_type_for', array( 'a.bin' ) ) );
	}

	public function test_parse_range() {
		$this->assertNull( $this->invoke( 'parse_range', array( null, 10 ) ) );
		$this->assertNull( $this->invoke( 'parse_range', array( '', 10 ) ) );
		$this->assertSame( 'unsatisfiable', $this->invoke( 'parse_range', array( 'items=0-1', 10 ) ) );
		$this->assertSame( 'unsatisfiable', $this->invoke( 'parse_range', array( 'bytes=99-', 10 ) ) );
		$this->assertSame( array( 'start' => 2, 'end' => 4 ), $this->invoke( 'parse_range', array( 'bytes=2-4', 10 ) ) );
		$this->assertSame( array( 'start' => 2, 'end' => 9 ), $this->invoke( 'parse_range', array( 'bytes=2-', 10 ) ) );
		$this->assertSame( array( 'start' => 7, 'end' => 9 ), $this->invoke( 'parse_range', array( 'bytes=-3', 10 ) ) );
		$this->assertSame( array( 'start' => 0, 'end' => 9 ), $this->invoke( 'parse_range', array( 'bytes=0-100', 10 ) ) );
	}

	public function test_if_none_match() {
		$this->assertFalse( $this->invoke( 'if_none_match', array( null, 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( '"x"', 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( 'W/"x"', 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( '"a", "x"', 'x' ) ) );
		$this->assertTrue( $this->invoke( 'if_none_match', array( '*', 'x' ) ) );
		$this->assertFalse( $this->invoke( 'if_none_match', array( '"y"', 'x' ) ) );
	}

	public function test_register_cron_schedule_adds_interval() {
		$schedules = ExeLearning_Preview_Proxy::register_cron_schedule( array() );
		$this->assertArrayHasKey( ExeLearning_Preview_Proxy::CRON_SCHEDULE, $schedules );
		$this->assertSame( 900, $schedules[ ExeLearning_Preview_Proxy::CRON_SCHEDULE ]['interval'] );
	}

	public function test_stream_range_emits_slice() {
		$path = $this->tmp_with( '0123456789' );
		ob_start();
		$this->invoke( 'stream_range', array( $path, 2, 3 ) );
		$this->assertSame( '234', ob_get_clean() );
	}

	// ---- management API ---------------------------------------------------

	public function test_create_session_returns_protocol_v2() {
		$resp = $this->proxy->create_session( new WP_REST_Request( 'POST', '/x' ) );
		$this->assertSame( 201, $resp->get_status() );
		$data = $resp->get_data();
		$this->assertSame( 2, $data['protocolVersion'] );
		$this->assertSame( 0, $data['revision'] );
		$this->assertMatchesRegularExpression(
			'/' . ExeLearning_Preview_Proxy::PREVIEW_ID_REGEX . '/',
			$data['previewId']
		);
		$this->assertArrayHasKey( 'maxAssetBytes', $data['limits'] );
	}

	public function test_check_manage_permission_requires_upload_files() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->proxy->check_manage_permission() );
		wp_set_current_user( $this->author );
		$this->assertTrue( $this->proxy->check_manage_permission() );
	}

	public function test_upload_assets_stores_and_reports() {
		$id  = $this->create_session_id();
		$req = $this->multipart_request(
			$id,
			'assets',
			wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
			array( 'PNG' )
		);
		$resp = $this->proxy->upload_assets( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( self::PHOTO_KEY ), $resp->get_data()['stored'] );
	}

	public function test_upload_assets_index_alignment_error() {
		$id  = $this->create_session_id();
		$req = $this->multipart_request(
			$id,
			'assets',
			wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
			array() // no files
		);
		$resp = $this->proxy->upload_assets( $req );
		$this->assertSame( 400, $resp->get_status() );
	}

	public function test_upload_assets_ownership_denied() {
		$id = $this->create_session_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$req = $this->multipart_request(
			$id,
			'assets',
			wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
			array( 'PNG' )
		);
		$this->assertSame( 403, $this->proxy->upload_assets( $req )->get_status() );
	}

	public function test_upload_assets_missing_session_404() {
		$req = $this->multipart_request(
			'ffffffff-ffff-4fff-8fff-ffffffffffff',
			'assets',
			wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
			array( 'PNG' )
		);
		$this->assertSame( 404, $this->proxy->upload_assets( $req )->get_status() );
	}

	public function test_publish_revision_happy_path() {
		$id   = $this->create_session_id();
		$meta = array(
			'baseRevision' => 0,
			'nextRevision' => 1,
			'deletes'      => array(),
			'assetRefs'    => (object) array(),
			'fixedRefs'    => (object) array(),
			'writes'       => array( 'index.html' ),
		);
		$req  = $this->multipart_request( $id, 'revision', wp_json_encode( $meta ), array( '<h1>hi</h1>' ) );
		$resp = $this->proxy->publish_revision( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 1, $resp->get_data()['revision'] );
		$this->assertTrue( $resp->get_data()['active'] );
	}

	public function test_publish_revision_conflict() {
		$id = $this->create_session_id();
		$this->store->apply_revision(
			$id,
			array(
				'baseRevision' => 0,
				'nextRevision' => 1,
				'writes'       => array( array( 'path' => 'index.html', 'tmp_path' => $this->tmp_with( 'a' ) ) ),
				'deletes'      => array(),
				'assetRefs'    => array(),
				'fixedRefs'    => array(),
			),
			$this->fixed
		);
		$meta = array(
			'baseRevision' => 0,
			'nextRevision' => 1,
			'deletes'      => array(),
			'assetRefs'    => (object) array(),
			'fixedRefs'    => (object) array(),
			'writes'       => array( 'index.html' ),
		);
		$resp = $this->proxy->publish_revision(
			$this->multipart_request( $id, 'revision', wp_json_encode( $meta ), array( 'stale' ) )
		);
		$this->assertSame( 409, $resp->get_status() );
		$this->assertSame( 'revision-conflict', $resp->get_data()['reason'] );
		$this->assertSame( 1, $resp->get_data()['currentRevision'] );
	}

	public function test_publish_revision_invalid_json() {
		$id  = $this->create_session_id();
		$req = new WP_REST_Request( 'POST', '/x' );
		$req->set_header( 'Content-Type', 'multipart/form-data; boundary=b' );
		$req->set_url_params( array( 'previewId' => $id ) );
		$req->set_body_params( array( 'revision' => 'not json {' ) );
		$this->assertSame( 400, $this->proxy->publish_revision( $req )->get_status() );
	}

	public function test_delete_session_ok() {
		$id  = $this->create_session_id();
		$req = new WP_REST_Request( 'DELETE', '/x' );
		$req->set_url_params( array( 'previewId' => $id ) );
		$resp = $this->proxy->delete_session( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertTrue( $resp->get_data()['success'] );
		// The capability URL is dead once the session is deleted.
		$this->assertSame( 404, $this->proxy->build_serve_response( $id, 'index.html' )['status'] );
	}

	// ---- serving (build_serve_response) ----------------------------------

	private function publish_document( $id, $path, $bytes ) {
		$this->store->apply_revision(
			$id,
			array(
				'baseRevision' => $this->store->get_owned_session( $id, $this->author )['meta']['revision'],
				'nextRevision' => $this->store->get_owned_session( $id, $this->author )['meta']['revision'] + 1,
				'writes'       => array( array( 'path' => $path, 'tmp_path' => $this->tmp_with( $bytes ) ) ),
				'deletes'      => array(),
				'assetRefs'    => array(),
				'fixedRefs'    => array(),
			),
			$this->fixed
		);
	}

	public function test_serve_response_unknown_is_hardened_404() {
		$resp = $this->proxy->build_serve_response( 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'index.html' );
		$this->assertSame( 404, $resp['status'] );
		$this->assertSame( 'nosniff', $resp['headers']['X-Content-Type-Options'] );
		$this->assertSame( 'no-store', $resp['headers']['Cache-Control'] );
		$this->assertSame( '*', $resp['headers']['Access-Control-Allow-Origin'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $resp['headers'] );
	}

	public function test_serve_response_document_carries_csp_and_no_store() {
		$id = $this->create_session_id();
		$this->publish_document( $id, 'index.html', '<html>x</html>' );
		$resp = $this->proxy->build_serve_response( $id, 'index.html' );
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
		$resp = $this->proxy->build_serve_response( $id, 'm/clip.png' );
		$this->assertSame( 200, $resp['status'] );
		$this->assertSame( 'no-cache', $resp['headers']['Cache-Control'] );
		$this->assertSame( '"' . self::PHOTO_KEY . '"', $resp['headers']['ETag'] );
		$this->assertSame( 'bytes', $resp['headers']['Accept-Ranges'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $resp['headers'] );

		// Conditional -> 304.
		$resp304 = $this->proxy->build_serve_response( $id, 'm/clip.png', array( 'if_none_match' => '"' . self::PHOTO_KEY . '"' ) );
		$this->assertSame( 304, $resp304['status'] );

		// Range -> 206.
		$resp206 = $this->proxy->build_serve_response( $id, 'm/clip.png', array( 'range' => 'bytes=2-4' ) );
		$this->assertSame( 206, $resp206['status'] );
		$this->assertSame( 'bytes 2-4/10', $resp206['headers']['Content-Range'] );
		$this->assertSame( '3', $resp206['headers']['Content-Length'] );
		$this->assertSame( 'range', $resp206['body']['kind'] );

		// Unsatisfiable range -> 416.
		$resp416 = $this->proxy->build_serve_response( $id, 'm/clip.png', array( 'range' => 'bytes=99-' ) );
		$this->assertSame( 416, $resp416['status'] );
		$this->assertSame( 'bytes */10', $resp416['headers']['Content-Range'] );
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
		$fixed = new ExeLearning_Preview_Fixed_Resources( $this->base . '/dist' );
		$proxy = new ExeLearning_Preview_Proxy( $this->store, $fixed );

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

		$resp = $proxy->build_serve_response( $id, 'theme/icon.svg' );
		$this->assertSame( 200, $resp['status'] );
		$this->assertSame( 'private, max-age=31536000', $resp['headers']['Cache-Control'] );
		$this->assertSame( 'image/svg+xml; charset=utf-8', $resp['headers']['Content-Type'] );
		$this->assertSame( self::EXPECTED_CSP, $resp['headers']['Content-Security-Policy'] );
	}

	// ---- wiring ----------------------------------------------------------

	public function test_uninjected_proxy_builds_default_dependencies() {
		$proxy = new ExeLearning_Preview_Proxy();
		// A bad capability id is rejected before any filesystem access, but it
		// still exercises the lazy store()/fixed() getters.
		$this->assertSame( 404, $proxy->build_serve_response( 'not-a-valid-uuid', 'index.html' )['status'] );
	}

	public function test_run_cleanup_sweeps_idle_sessions() {
		$id = $this->create_session_id();
		touch(
			$this->base . '/store/' . $id . '/access',
			time() - ( ExeLearning_Preview_Session_Store::TTL_SECONDS + 60 )
		);
		$this->proxy->run_cleanup();
		$this->assertFalse( is_dir( $this->base . '/store/' . $id ) );
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/exelearning/v1/preview-session', $routes );

		$has_serving   = false;
		$has_assets    = false;
		$has_revisions = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/exelearning/v1/preview/' ) ) {
				$has_serving = true;
			}
			if ( false !== strpos( $route, '/assets' ) && false !== strpos( $route, 'preview-session' ) ) {
				$has_assets = true;
			}
			if ( false !== strpos( $route, '/revisions' ) && false !== strpos( $route, 'preview-session' ) ) {
				$has_revisions = true;
			}
		}
		$this->assertTrue( $has_serving, 'serving route registered' );
		$this->assertTrue( $has_assets, 'assets route registered' );
		$this->assertTrue( $has_revisions, 'revisions route registered' );
	}
}
