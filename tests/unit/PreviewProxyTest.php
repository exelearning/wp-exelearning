<?php
/**
 * Tests for the REST surface in front of the opaque preview snapshots.
 *
 * PreviewSnapshotStoreTest covers the store. This file covers the routes that
 * expose it: who is allowed to write a capability, what a malformed upload
 * gets back, and that a capability is scoped to one owner and one attachment.
 * Those are the checks standing between a signed-in contributor and somebody
 * else's snapshot, and none of them had a test.
 *
 * The streaming half (serve_preview and below) ends every path in exit(), so it
 * cannot be driven from PHPUnit as written; what it relies on -- parse_range(),
 * if_none_match_matches() -- is covered directly here and in the store tests.
 *
 * @package Exelearning
 */

/**
 * Proxy whose request-ending step is recorded instead of taken.
 */
class ExeLearning_Preview_Proxy_Recording extends ExeLearning_Preview_Proxy {

	/**
	 * How many times a response was finished.
	 *
	 * @var int
	 */
	public $finished = 0;

	/**
	 * Record the end of the response rather than exiting.
	 *
	 * @return void
	 */
	protected function finish() {
		++$this->finished;
	}
}

/**
 * Class PreviewProxyTest.
 *
 * Both classes are named: the routes are thin over the store, so a test of the
 * REST surface runs store code too, and @covers discards anything executed
 * outside the classes it names.
 *
 * @covers ExeLearning_Preview_Proxy
 * @covers ExeLearning_Preview_Snapshot_Store
 */
class PreviewProxyTest extends WP_UnitTestCase {

	/**
	 * Private snapshot storage for this test.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Store bound to that storage.
	 *
	 * @var ExeLearning_Preview_Snapshot_Store
	 */
	private $store;

	/**
	 * Proxy under test.
	 *
	 * @var ExeLearning_Preview_Proxy
	 */
	private $proxy;

	/**
	 * Temporary ZIP paths to remove.
	 *
	 * @var string[]
	 */
	private $zips = array();

	/**
	 * Set up an isolated store and a proxy in front of it.
	 */
	public function set_up() {
		parent::set_up();
		$this->root  = trailingslashit( get_temp_dir() ) . 'exe-proxy-test-' . wp_generate_password( 12, false );
		$this->store = new ExeLearning_Preview_Snapshot_Store( $this->root );
		$this->proxy = new ExeLearning_Preview_Proxy( $this->store );
	}

	/**
	 * Remove the storage and any ZIP fixtures.
	 */
	public function tear_down() {
		$this->remove_tree( $this->root );
		foreach ( $this->zips as $zip ) {
			if ( file_exists( $zip ) ) {
				wp_delete_file( $zip );
			}
		}
		parent::tear_down();
	}

	/**
	 * Build a ZIP holding the given path => contents pairs.
	 *
	 * @param array $entries Relative path to contents.
	 * @return string Absolute ZIP path.
	 */
	private function zip( array $entries ) {
		$path = trailingslashit( get_temp_dir() ) . 'exe-proxy-' . wp_generate_password( 10, false ) . '.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		$this->zips[] = $path;

		return $path;
	}

	/**
	 * Build a REST request for one of the management routes.
	 *
	 * @param string $method        HTTP method.
	 * @param int    $attachment_id Attachment id.
	 * @param string $preview_id    Capability id, when the route carries one.
	 * @return WP_REST_Request
	 */
	private function request( $method, $attachment_id, $preview_id = '' ) {
		$request = new WP_REST_Request( $method, '/exelearning/v1/preview-session' );
		$request->set_param( 'attachmentId', (string) $attachment_id );
		if ( '' !== $preview_id ) {
			$request->set_param( 'previewId', $preview_id );
		}

		return $request;
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $path Directory to delete.
	 */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . '/' . $entry;
			if ( is_dir( $child ) ) {
				$this->remove_tree( $child );
			} else {
				wp_delete_file( $child );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $path );
	}

	// ------------------------------------------------------------------
	// Who may write a capability.
	// ------------------------------------------------------------------

	/**
	 * A visitor with no upload rights cannot open a preview session.
	 */
	public function test_a_user_who_cannot_upload_is_refused() {
		$attachment_id = self::factory()->attachment->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( $this->proxy->can_manage_preview( $this->request( 'POST', $attachment_id ) ) );
	}

	/**
	 * Being able to upload is not enough: the capability is per attachment, so
	 * an author cannot open a session against someone else's file.
	 */
	public function test_upload_rights_alone_do_not_grant_another_authors_attachment() {
		$owner         = self::factory()->user->create( array( 'role' => 'author' ) );
		$attachment_id = self::factory()->attachment->create( array( 'post_author' => $owner ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertFalse( $this->proxy->can_manage_preview( $this->request( 'POST', $attachment_id ) ) );
	}

	/**
	 * The attachment's own author may.
	 */
	public function test_the_attachments_author_may_manage_its_preview() {
		$owner         = self::factory()->user->create( array( 'role' => 'author' ) );
		$attachment_id = self::factory()->attachment->create( array( 'post_author' => $owner ) );
		wp_set_current_user( $owner );

		$this->assertTrue( $this->proxy->can_manage_preview( $this->request( 'POST', $attachment_id ) ) );
	}

	// ------------------------------------------------------------------
	// Uploading a snapshot.
	// ------------------------------------------------------------------

	/**
	 * A request carrying no snapshot is refused rather than creating an empty
	 * capability.
	 */
	public function test_a_request_without_a_snapshot_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->proxy->replace_preview( $this->request( 'POST', 42 ) );

		$this->assertWPError( $response );
		$this->assertSame( 'missing_snapshot', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * So is a snapshot the upload machinery reported as failed.
	 */
	public function test_a_failed_upload_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = $this->request( 'POST', 42 );
		$request->set_file_params(
			array(
				'snapshot' => array(
					'error'    => UPLOAD_ERR_INI_SIZE,
					'tmp_name' => $this->zip( array( 'index.html' => 'x' ) ),
				),
			)
		);

		$response = $this->proxy->replace_preview( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'missing_snapshot', $response->get_error_code() );
	}

	/**
	 * A path that was never uploaded is refused however well-formed the request
	 * looks. Without the is_uploaded_file() check, this parameter would let a
	 * caller name any file the web server can read and have it unpacked into a
	 * capability they then fetch.
	 */
	public function test_a_path_that_was_not_uploaded_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = $this->request( 'POST', 42 );
		$request->set_file_params(
			array(
				'snapshot' => array(
					'error'    => UPLOAD_ERR_OK,
					'tmp_name' => $this->zip( array( 'index.html' => 'x' ) ),
				),
			)
		);

		$response = $this->proxy->replace_preview( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'missing_snapshot', $response->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Deleting a capability.
	// ------------------------------------------------------------------

	/**
	 * Deleting a capability that does not exist reports 404, not success.
	 */
	public function test_deleting_an_unknown_capability_reports_not_found() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->proxy->delete_preview(
			$this->request( 'DELETE', 42, wp_generate_uuid4() )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * An owner can delete their own capability.
	 */
	public function test_an_owner_can_delete_their_own_capability() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$preview_id = $this->store->replace( $user, 42, $this->zip( array( 'index.html' => 'x' ) ) );
		$this->assertIsString( $preview_id );

		$response = $this->proxy->delete_preview( $this->request( 'DELETE', 42, $preview_id ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( $this->store->get( $preview_id, 'index.html' ) );
	}

	/**
	 * Somebody else's capability is refused, even from an administrator: the
	 * snapshot is scoped to the user who created it.
	 */
	public function test_another_users_capability_cannot_be_deleted() {
		$owner      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$preview_id = $this->store->replace( $owner, 42, $this->zip( array( 'index.html' => 'x' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $this->proxy->delete_preview( $this->request( 'DELETE', 42, $preview_id ) );

		$this->assertWPError( $response );
		$this->assertSame( 'preview_forbidden', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertNotNull( $this->store->get( $preview_id, 'index.html' ) );
	}

	/**
	 * Nor can a capability be deleted through a different attachment, which is
	 * the other half of the same scope.
	 */
	public function test_a_capability_cannot_be_deleted_through_another_attachment() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$preview_id = $this->store->replace( $user, 42, $this->zip( array( 'index.html' => 'x' ) ) );

		$response = $this->proxy->delete_preview( $this->request( 'DELETE', 99, $preview_id ) );

		$this->assertWPError( $response );
		$this->assertSame( 'preview_forbidden', $response->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Serving a capability.
	// ------------------------------------------------------------------

	/**
	 * Drive serve_preview() and return what it wrote.
	 *
	 * header() cannot be inspected under PHPUnit and warns that output already
	 * started, so the body is captured and the warning swallowed; what the
	 * headers say is asserted through the pieces that build them.
	 *
	 * @param string $preview_id Capability id.
	 * @param string $file       Path inside the snapshot.
	 * @return array Body and the recording proxy.
	 */
	private function serve( $preview_id, $file ) {
		$proxy   = new ExeLearning_Preview_Proxy_Recording( $this->store );
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/preview' );
		$request->set_param( 'previewId', $preview_id );
		$request->set_param( 'file', $file );

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler
			function ( $errno, $errstr ) {
				return false !== strpos( $errstr, 'Cannot modify header information' );
			}
		);
		ob_start();
		try {
			$proxy->serve_preview( $request );
			$body = ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();
			restore_error_handler();
			throw $e;
		}
		restore_error_handler();

		return array( 'body' => $body, 'proxy' => $proxy );
	}

	/**
	 * Create a snapshot owned by a fresh administrator.
	 *
	 * @param array $entries ZIP contents.
	 * @return string Capability id.
	 */
	private function snapshot( array $entries ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		// A snapshot without an index.html is not a snapshot the store accepts.
		if ( ! isset( $entries['index.html'] ) ) {
			$entries = array( 'index.html' => 'x' ) + $entries;
		}
		$id = $this->store->replace( $user, 42, $this->zip( $entries ) );
		$this->assertIsString( $id, 'The snapshot fixture was rejected by the store.' );

		return $id;
	}

	/**
	 * A document is streamed back whole.
	 */
	public function test_a_document_is_served() {
		$id = $this->snapshot( array( 'index.html' => '<p>hola</p>' ) );

		$result = $this->serve( $id, 'index.html' );

		$this->assertSame( '<p>hola</p>', $result['body'] );
		$this->assertSame( 1, $result['proxy']->finished );
	}

	/**
	 * An unknown file inside a live capability is a 404, not an empty 200.
	 */
	public function test_an_unknown_file_is_not_found() {
		$id = $this->snapshot( array( 'index.html' => 'x' ) );

		$result = $this->serve( $id, 'missing.html' );

		$this->assertSame( 'Not found', $result['body'] );
		$this->assertSame( 1, $result['proxy']->finished );
	}

	/**
	 * So is an attempt to walk out of the snapshot, and it is refused with the
	 * same response as any other miss -- nothing distinguishes a blocked
	 * traversal from a file that is simply not there.
	 */
	public function test_a_traversal_attempt_is_indistinguishable_from_a_miss() {
		$id = $this->snapshot( array( 'index.html' => 'x' ) );

		$this->assertSame( 'Not found', $this->serve( $id, '../../wp-config.php' )['body'] );
	}

	/**
	 * A non-scriptable asset is served through the revalidating tier, whole,
	 * when the request asks for no window.
	 */
	public function test_an_asset_is_served_whole_by_default() {
		$id = $this->snapshot( array( 'media/clip.mp4' => 'abcdefghij' ) );

		$result = $this->serve( $id, 'media/clip.mp4' );

		$this->assertSame( 'abcdefghij', $result['body'] );
	}

	/**
	 * A byte range gets only its window. This is what lets a video inside a
	 * snapshot seek instead of re-downloading from the start.
	 */
	public function test_a_range_request_gets_only_its_window() {
		$id = $this->snapshot( array( 'media/clip.mp4' => 'abcdefghij' ) );
		$_SERVER['HTTP_RANGE'] = 'bytes=2-5';

		$result = $this->serve( $id, 'media/clip.mp4' );
		unset( $_SERVER['HTTP_RANGE'] );

		$this->assertSame( 'cdef', $result['body'] );
	}

	/**
	 * A suffix range counts back from the end.
	 */
	public function test_a_suffix_range_counts_from_the_end() {
		$id = $this->snapshot( array( 'media/clip.mp4' => 'abcdefghij' ) );
		$_SERVER['HTTP_RANGE'] = 'bytes=-3';

		$result = $this->serve( $id, 'media/clip.mp4' );
		unset( $_SERVER['HTTP_RANGE'] );

		$this->assertSame( 'hij', $result['body'] );
	}

	/**
	 * A range past the end sends no body at all.
	 */
	public function test_an_unsatisfiable_range_sends_no_body() {
		$id = $this->snapshot( array( 'media/clip.mp4' => 'abcdefghij' ) );
		$_SERVER['HTTP_RANGE'] = 'bytes=999-1200';

		$result = $this->serve( $id, 'media/clip.mp4' );
		unset( $_SERVER['HTTP_RANGE'] );

		$this->assertSame( '', $result['body'] );
		$this->assertSame( 1, $result['proxy']->finished );
	}

	/**
	 * A matching entity tag sends no body either: the point of the tag is to
	 * avoid re-reading a whole video to answer a conditional request.
	 */
	public function test_a_matching_entity_tag_sends_no_body() {
		$id                            = $this->snapshot( array( 'media/clip.mp4' => 'abcdefghij' ) );
		$etag                          = $this->store->get( $id, 'media/clip.mp4' )['etag'];
		$_SERVER['HTTP_IF_NONE_MATCH'] = '"' . $etag . '"';

		$result = $this->serve( $id, 'media/clip.mp4' );
		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );

		$this->assertSame( '', $result['body'] );
		$this->assertSame( 1, $result['proxy']->finished );
	}

	/**
	 * A stale tag gets the bytes.
	 */
	public function test_a_stale_entity_tag_gets_the_bytes() {
		$id                            = $this->snapshot( array( 'media/clip.mp4' => 'abcdefghij' ) );
		$_SERVER['HTTP_IF_NONE_MATCH'] = '"not-the-current-tag"';

		$result = $this->serve( $id, 'media/clip.mp4' );
		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );

		$this->assertSame( 'abcdefghij', $result['body'] );
	}

	/**
	 * A document is never answered from cache, so a conditional request for one
	 * still gets the current bytes: the editor rewrites it on every refresh.
	 */
	public function test_a_document_ignores_a_conditional_request() {
		$id                            = $this->snapshot( array( 'index.html' => 'fresh' ) );
		$_SERVER['HTTP_IF_NONE_MATCH'] = '"anything"';

		$result = $this->serve( $id, 'index.html' );
		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );

		$this->assertSame( 'fresh', $result['body'] );
	}

	// ------------------------------------------------------------------
	// What the capability will and will not serve.
	// ------------------------------------------------------------------

	/**
	 * A capability serves only what is inside its own snapshot.
	 */
	public function test_a_capability_will_not_walk_out_of_its_snapshot() {
		$user       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$preview_id = $this->store->replace( $user, 42, $this->zip( array( 'index.html' => 'x' ) ) );

		foreach ( array(
			'../../../wp-config.php',
			'..%2f..%2fwp-config.php',
			'%2e%2e/%2e%2e/wp-config.php',
			'/etc/passwd',
		) as $attempt ) {
			$this->assertNull(
				$this->store->get( $preview_id, $attempt ),
				"Escaped the snapshot with: {$attempt}"
			);
		}
	}

	/**
	 * Its own metadata is not part of what it serves.
	 */
	public function test_a_capability_does_not_serve_its_own_metadata() {
		$user       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$preview_id = $this->store->replace( $user, 42, $this->zip( array( 'index.html' => 'x' ) ) );

		$this->assertNull( $this->store->get( $preview_id, '.exelearning-preview.json' ) );
	}

	/**
	 * An id that is not a capability is refused before anything is read.
	 */
	public function test_a_malformed_capability_id_serves_nothing() {
		foreach ( array( '', 'not-a-uuid', '../../etc', str_repeat( 'a', 36 ) ) as $id ) {
			$this->assertNull( $this->store->get( $id, 'index.html' ) );
		}
	}

	/**
	 * A snapshot nobody has touched for the whole TTL stops serving.
	 */
	public function test_an_idle_capability_expires() {
		$now   = time();
		$clock = function () use ( &$now ) {
			return $now;
		};
		$store      = new ExeLearning_Preview_Snapshot_Store( $this->root, $clock );
		$user       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$preview_id = $store->replace( $user, 42, $this->zip( array( 'index.html' => 'x' ) ) );
		$this->assertNotNull( $store->get( $preview_id, 'index.html' ) );

		$now += ExeLearning_Preview_Snapshot_Store::TTL_SECONDS + 60;

		$this->assertNull( $store->get( $preview_id, 'index.html' ) );
	}

	/**
	 * Replacing a capability that was never created is refused, so a caller
	 * cannot choose its own capability id and have it minted.
	 */
	public function test_replacing_an_unknown_capability_is_refused() {
		$user   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$result = $this->store->replace(
			$user,
			42,
			$this->zip( array( 'index.html' => 'x' ) ),
			wp_generate_uuid4()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_preview', $result->get_error_code() );
	}

	/**
	 * A capability id that is not a UUID is refused outright.
	 */
	public function test_replacing_with_a_malformed_capability_id_is_refused() {
		$user   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$result = $this->store->replace( $user, 42, $this->zip( array( 'index.html' => 'x' ) ), '../escape' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_preview_id', $result->get_error_code() );
	}

	/**
	 * A file that is not a ZIP is refused rather than half-extracted.
	 */
	public function test_something_that_is_not_a_zip_is_refused() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$path = trailingslashit( get_temp_dir() ) . 'exe-proxy-not-a-zip-' . wp_generate_password( 8, false );
		file_put_contents( $path, 'plain text' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->zips[] = $path;

		$result = $this->store->replace( $user, 42, $path );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_preview_zip', $result->get_error_code() );
	}
}
