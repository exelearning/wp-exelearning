<?php
/**
 * Tests for ExeLearning_Preview_Management_Controller (serving contract v2 write side).
 *
 * @package Exelearning
 */

/**
 * Class PreviewManagementControllerTest.
 *
 * @covers ExeLearning_Preview_Management_Controller
 */
class PreviewManagementControllerTest extends WP_UnitTestCase {

	const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

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
	 * @var ExeLearning_Preview_Management_Controller
	 */
	private $mgmt;

	/**
	 * @var int
	 */
	private $author;

	public function set_up() {
		parent::set_up();
		$this->base = trailingslashit( get_temp_dir() ) . 'exe-preview-mgmt-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->base . '/store' );
		wp_mkdir_p( $this->base . '/tmp' );
		wp_mkdir_p( $this->base . '/dist' );
		$this->store  = new ExeLearning_Preview_Session_Store( $this->base . '/store' );
		$this->fixed  = new ExeLearning_Preview_Fixed_Resources( $this->base . '/dist' );
		$this->mgmt   = new ExeLearning_Preview_Management_Controller( $this->store, $this->fixed );
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

	/** Build a POST request carrying a JSON field plus index-aligned files[]. */
	private function multipart_request( $preview_id, $field, $json, $contents ) {
		$req = new WP_REST_Request( 'POST', '/exelearning/v1/preview-session' );
		$req->set_header( 'Content-Type', 'multipart/form-data; boundary=b' );
		$req->set_url_params( array( 'previewId' => $preview_id ) );
		$req->set_body_params( array( $field => $json ) );

		$tmp_names = array();
		$sizes     = array();
		$errors    = array();
		$names     = array();
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

	/** Build a POST request with a single files[] part carrying a given error. */
	private function errored_part_request( $preview_id, $field, $json, $filename, $error ) {
		$req = new WP_REST_Request( 'POST', '/x' );
		$req->set_header( 'Content-Type', 'multipart/form-data; boundary=b' );
		$req->set_url_params( array( 'previewId' => $preview_id ) );
		$req->set_body_params( array( $field => $json ) );
		$req->set_file_params(
			array(
				'files' => array(
					'name'     => array( $filename ),
					'tmp_name' => array( '' ),
					'size'     => array( 0 ),
					'error'    => array( $error ),
				),
			)
		);
		return $req;
	}

	private function create_session_id() {
		$resp = $this->mgmt->create_session( new WP_REST_Request( 'POST', '/x' ) );
		return $resp->get_data()['previewId'];
	}

	// ---- create / permission ---------------------------------------------

	public function test_create_session_returns_protocol_v2() {
		$resp = $this->mgmt->create_session( new WP_REST_Request( 'POST', '/x' ) );
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
		$this->assertFalse( $this->mgmt->check_manage_permission() );
		wp_set_current_user( $this->author );
		$this->assertTrue( $this->mgmt->check_manage_permission() );
	}

	// ---- assets -----------------------------------------------------------

	public function test_upload_assets_stores_and_reports() {
		$id  = $this->create_session_id();
		$req = $this->multipart_request(
			$id,
			'assets',
			wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
			array( 'PNG' )
		);
		$resp = $this->mgmt->upload_assets( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( array( self::PHOTO_KEY ), $resp->get_data()['stored'] );
	}

	public function test_upload_assets_index_alignment_error() {
		$id   = $this->create_session_id();
		$req  = $this->multipart_request(
			$id,
			'assets',
			wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
			array() // no files
		);
		$resp = $this->mgmt->upload_assets( $req );
		$this->assertSame( 400, $resp->get_status() );
	}

	public function test_upload_assets_invalid_json() {
		$id  = $this->create_session_id();
		$req = new WP_REST_Request( 'POST', '/x' );
		$req->set_url_params( array( 'previewId' => $id ) );
		$req->set_body_params( array( 'assets' => 'not json {' ) );
		$this->assertSame( 400, $this->mgmt->upload_assets( $req )->get_status() );
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
		$this->assertSame( 403, $this->mgmt->upload_assets( $req )->get_status() );
	}

	public function test_upload_assets_missing_session_404() {
		$req = $this->multipart_request(
			'ffffffff-ffff-4fff-8fff-ffffffffffff',
			'assets',
			wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
			array( 'PNG' )
		);
		$this->assertSame( 404, $this->mgmt->upload_assets( $req )->get_status() );
	}

	public function test_upload_assets_rejects_errored_upload_part() {
		$id   = $this->create_session_id();
		$resp = $this->mgmt->upload_assets(
			$this->errored_part_request(
				$id,
				'assets',
				wp_json_encode( array( array( 'key' => self::PHOTO_KEY, 'size' => 3 ) ) ),
				'photo.png',
				UPLOAD_ERR_PARTIAL
			)
		);
		$this->assertSame( 400, $resp->get_status() );
		$this->assertStringContainsString( 'photo.png', $resp->get_data()['error'] );
		// Nothing was stored for the aborted batch.
		$this->assertFalse( is_file( $this->base . '/store/' . $id . '/assets/' . self::PHOTO_KEY ) );
	}

	// ---- revisions --------------------------------------------------------

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
		$resp = $this->mgmt->publish_revision( $req );
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
		$resp = $this->mgmt->publish_revision(
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
		$this->assertSame( 400, $this->mgmt->publish_revision( $req )->get_status() );
	}

	public function test_publish_revision_ownership_denied() {
		$id = $this->create_session_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$meta = array(
			'baseRevision' => 0,
			'nextRevision' => 1,
			'deletes'      => array(),
			'assetRefs'    => (object) array(),
			'fixedRefs'    => (object) array(),
			'writes'       => array( 'index.html' ),
		);
		$resp = $this->mgmt->publish_revision( $this->multipart_request( $id, 'revision', wp_json_encode( $meta ), array( 'x' ) ) );
		// A non-owner is refused BEFORE any revision is applied.
		$this->assertSame( 403, $resp->get_status() );
	}

	public function test_publish_revision_rejects_oversized_upload_part() {
		$id     = $this->create_session_id();
		$before = $this->store->get_owned_session( $id, $this->author )['meta']['revision'];
		$meta   = array(
			'baseRevision' => 0,
			'nextRevision' => 1,
			'deletes'      => array(),
			'assetRefs'    => (object) array(),
			'fixedRefs'    => (object) array(),
			'writes'       => array( 'index.html' ),
		);

		$resp = $this->mgmt->publish_revision(
			$this->errored_part_request( $id, 'revision', wp_json_encode( $meta ), 'index.html', UPLOAD_ERR_INI_SIZE )
		);

		// An oversized part is a 413 naming the offending index + filename.
		$this->assertSame( 413, $resp->get_status() );
		$this->assertStringContainsString( 'index.html', $resp->get_data()['error'] );

		// The store is untouched: no revision bump, no 0-byte document.
		$this->assertSame( $before, $this->store->get_owned_session( $id, $this->author )['meta']['revision'] );
		$this->assertNull( $this->store->serve_lookup( $id, 'index.html', $this->fixed ) );
		$this->assertFalse( is_dir( $this->base . '/store/' . $id . '/revisions/1' ) );
	}

	public function test_publish_revision_rejects_failed_upload_part_400() {
		$id   = $this->create_session_id();
		$meta = array(
			'baseRevision' => 0,
			'nextRevision' => 1,
			'deletes'      => array(),
			'assetRefs'    => (object) array(),
			'fixedRefs'    => (object) array(),
			'writes'       => array( 'index.html' ),
		);
		$resp = $this->mgmt->publish_revision(
			$this->errored_part_request( $id, 'revision', wp_json_encode( $meta ), 'index.html', UPLOAD_ERR_PARTIAL )
		);
		// Any non-size upload failure is a 400.
		$this->assertSame( 400, $resp->get_status() );
		$this->assertNull( $this->store->serve_lookup( $id, 'index.html', $this->fixed ) );
	}

	public function test_publish_revision_missing_asset_is_422() {
		$id   = $this->create_session_id();
		$meta = array(
			'baseRevision' => 0,
			'nextRevision' => 1,
			'deletes'      => array(),
			'assetRefs'    => array( 'content/ghost.png' => '99999999-9999-4999-8999-999999999999@deadbeef' ),
			'fixedRefs'    => (object) array(),
			'writes'       => array(),
		);
		$resp = $this->mgmt->publish_revision( $this->multipart_request( $id, 'revision', wp_json_encode( $meta ), array() ) );
		$this->assertSame( 422, $resp->get_status() );
		$this->assertSame( 'missing-assets', $resp->get_data()['reason'] );
	}

	// ---- delete + cleanup -------------------------------------------------

	public function test_delete_session_ok() {
		$id  = $this->create_session_id();
		$req = new WP_REST_Request( 'DELETE', '/x' );
		$req->set_url_params( array( 'previewId' => $id ) );
		$resp = $this->mgmt->delete_session( $req );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertTrue( $resp->get_data()['success'] );
		$this->assertFalse( is_dir( $this->base . '/store/' . $id ) );
	}

	public function test_delete_session_ownership_denied() {
		$id = $this->create_session_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$req = new WP_REST_Request( 'DELETE', '/x' );
		$req->set_url_params( array( 'previewId' => $id ) );
		$resp = $this->mgmt->delete_session( $req );
		$this->assertSame( 403, $resp->get_status() );
		// The session the non-owner tried to delete is still intact.
		$this->assertArrayHasKey( 'meta', $this->store->get_owned_session( $id, $this->author ) );
	}

	public function test_run_cleanup_sweeps_idle_sessions() {
		$id = $this->create_session_id();
		touch(
			$this->base . '/store/' . $id . '/access',
			time() - ( ExeLearning_Preview_Session_Store::TTL_SECONDS + 60 )
		);
		$this->mgmt->run_cleanup();
		$this->assertFalse( is_dir( $this->base . '/store/' . $id ) );
	}

	public function test_uninjected_management_builds_default_dependencies() {
		$mgmt = new ExeLearning_Preview_Management_Controller();
		$this->assertTrue( $mgmt->check_manage_permission() );
	}
}
