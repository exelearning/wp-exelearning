<?php
/**
 * Tests for ExeLearning_Preview_Session_Store (serving contract v2 store).
 *
 * @package Exelearning
 */

/**
 * Class PreviewSessionStoreTest.
 *
 * @covers ExeLearning_Preview_Session_Store
 */
class PreviewSessionStoreTest extends WP_UnitTestCase {

	const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';
	const CLIP_KEY  = '12345678-90ab-4cde-8f01-234567890abc@00112233';

	/**
	 * Store root.
	 *
	 * @var string
	 */
	private $store_dir;

	/**
	 * Scratch directory for upload temp files.
	 *
	 * @var string
	 */
	private $tmp_root;

	/**
	 * Distribution root for the (usually empty) fixed manifest.
	 *
	 * @var string
	 */
	private $dist_root;

	/**
	 * Store under test.
	 *
	 * @var ExeLearning_Preview_Session_Store
	 */
	private $store;

	/**
	 * Fixed-resource resolver (empty manifest by default).
	 *
	 * @var ExeLearning_Preview_Fixed_Resources
	 */
	private $fixed;

	public function set_up() {
		parent::set_up();
		$base            = trailingslashit( get_temp_dir() ) . 'exe-preview-store-' . wp_generate_password( 8, false );
		$this->store_dir = $base . '/store';
		$this->tmp_root  = $base . '/tmp';
		$this->dist_root = $base . '/dist';
		wp_mkdir_p( $this->store_dir );
		wp_mkdir_p( $this->tmp_root );
		wp_mkdir_p( $this->dist_root );
		$this->store = new ExeLearning_Preview_Session_Store( $this->store_dir );
		$this->fixed = new ExeLearning_Preview_Fixed_Resources( $this->dist_root );
	}

	public function tear_down() {
		$this->rrmdir( dirname( $this->store_dir ) );
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
		$path = $this->tmp_root . '/' . wp_generate_password( 12, false );
		file_put_contents( $path, $bytes );
		return $path;
	}

	private function asset_entry( $key, $bytes ) {
		return array(
			'key'          => $key,
			'declaredSize' => strlen( $bytes ),
			'tmp_path'     => $this->tmp_with( $bytes ),
		);
	}

	private function write( $path, $bytes ) {
		return array(
			'path'     => $path,
			'tmp_path' => $this->tmp_with( $bytes ),
		);
	}

	private function revision_meta( $base, $next, $writes, $overrides = array() ) {
		return array_merge(
			array(
				'baseRevision' => $base,
				'nextRevision' => $next,
				'writes'       => $writes,
				'deletes'      => array(),
				'assetRefs'    => array(),
				'fixedRefs'    => array(),
			),
			$overrides
		);
	}

	// ---- normalize_content_path ------------------------------------------

	public function test_normalize_content_path_valid() {
		$this->assertSame( 'html/page.html', ExeLearning_Preview_Session_Store::normalize_content_path( 'html/page.html' ) );
		$this->assertSame( 'index.html', ExeLearning_Preview_Session_Store::normalize_content_path( '' ) );
		$this->assertSame( 'index.html', ExeLearning_Preview_Session_Store::normalize_content_path( '/' ) );
		$this->assertSame( 'a/b.css', ExeLearning_Preview_Session_Store::normalize_content_path( '/a/./b.css' ) );
		$this->assertSame( 'b', ExeLearning_Preview_Session_Store::normalize_content_path( 'a/../b' ) );
	}

	public function test_normalize_content_path_rejects_traversal() {
		$this->assertNull( ExeLearning_Preview_Session_Store::normalize_content_path( '../secret' ) );
		$this->assertNull( ExeLearning_Preview_Session_Store::normalize_content_path( 'a/../../secret' ) );
		$this->assertNull( ExeLearning_Preview_Session_Store::normalize_content_path( '..' ) );
	}

	public function test_normalize_content_path_decodes_and_rejects_encoded_traversal() {
		$this->assertSame( 'a/b.html', ExeLearning_Preview_Session_Store::normalize_content_path( 'a%2Fb.html' ) );
		$this->assertNull( ExeLearning_Preview_Session_Store::normalize_content_path( '%2e%2e%2fsecret' ) );
	}

	public function test_normalize_content_path_rejects_null_byte() {
		$this->assertNull( ExeLearning_Preview_Session_Store::normalize_content_path( "a\0b" ) );
	}

	public function test_normalize_content_path_strips_query_and_fragment() {
		$this->assertSame( 'p.html', ExeLearning_Preview_Session_Store::normalize_content_path( 'p.html?v=1#frag' ) );
	}

	// ---- session lifecycle -----------------------------------------------

	public function test_create_session_returns_uuid_and_zero_revision() {
		$session = $this->store->create_session( 7 );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			$session['previewId']
		);
		$this->assertSame( 0, $session['revision'] );
		$this->assertTrue( is_dir( $this->store_dir . '/' . $session['previewId'] ) );
	}

	public function test_get_limits_matches_constants() {
		$limits = $this->store->get_limits();
		$this->assertSame( ExeLearning_Preview_Session_Store::MAX_FILES_PER_SESSION, $limits['maxFilesPerSession'] );
		$this->assertSame( ExeLearning_Preview_Session_Store::MAX_BYTES_PER_SESSION, $limits['maxBytesPerSession'] );
		$this->assertSame( ExeLearning_Preview_Session_Store::MAX_ASSET_BYTES, $limits['maxAssetBytes'] );
		$this->assertSame( ExeLearning_Preview_Session_Store::RECOMMENDED_BATCH_BYTES, $limits['recommendedBatchBytes'] );
	}

	public function test_get_owned_session_ownership() {
		$id = $this->store->create_session( 7 )['previewId'];
		$this->assertArrayHasKey( 'meta', $this->store->get_owned_session( $id, 7 ) );
		$this->assertSame( 403, $this->store->get_owned_session( $id, 9 )['status'] );
		$this->assertSame( 404, $this->store->get_owned_session( 'not-a-session', 7 )['status'] );
		$this->assertSame(
			404,
			$this->store->get_owned_session( 'ffffffff-ffff-4fff-8fff-ffffffffffff', 7 )['status']
		);
	}

	public function test_per_user_cap_evicts_lru() {
		$ids = array();
		for ( $i = 0; $i < ExeLearning_Preview_Session_Store::MAX_SESSIONS_PER_USER; $i++ ) {
			$ids[] = $this->store->create_session( 42 )['previewId'];
			// Stagger the access clock so the first session is the strict LRU.
			touch( $this->store_dir . '/' . $ids[ $i ] . '/access', time() - ( 100 - $i ) );
		}
		$extra = $this->store->create_session( 42 )['previewId'];
		$this->assertFalse( is_dir( $this->store_dir . '/' . $ids[0] ), 'oldest session should be evicted' );
		$this->assertTrue( is_dir( $this->store_dir . '/' . $extra ) );
	}

	// ---- assets (immutability, validation) -------------------------------

	public function test_store_assets_new_then_immutable() {
		$id  = $this->store->create_session( 1 )['previewId'];
		$out = $this->store->store_assets( $id, array( $this->asset_entry( self::PHOTO_KEY, 'PHOTO-BYTES-v1' ) ) );
		$this->assertSame( array( self::PHOTO_KEY ), $out['stored'] );
		$this->assertSame( array(), $out['alreadyStored'] );

		// Re-upload the same key with DIFFERENT bytes: reported alreadyStored,
		// bytes NOT replaced.
		$out2 = $this->store->store_assets( $id, array( $this->asset_entry( self::PHOTO_KEY, 'PHOTO-BYTES-v2-DIFFERENT' ) ) );
		$this->assertSame( array(), $out2['stored'] );
		$this->assertSame( array( self::PHOTO_KEY ), $out2['alreadyStored'] );

		$this->assertSame(
			'PHOTO-BYTES-v1',
			file_get_contents( $this->store_dir . '/' . $id . '/assets/' . self::PHOTO_KEY )
		);
	}

	public function test_store_assets_rejects_invalid_key_and_size_mismatch() {
		$id  = $this->store->create_session( 1 )['previewId'];
		$out = $this->store->store_assets(
			$id,
			array(
				array(
					'key'          => 'not-a-valid-key',
					'declaredSize' => 3,
					'tmp_path'     => $this->tmp_with( 'abc' ),
				),
				array(
					'key'          => self::CLIP_KEY,
					'declaredSize' => 999,
					'tmp_path'     => $this->tmp_with( 'short' ),
				),
			)
		);
		$this->assertSame( array(), $out['stored'] );
		$reasons = wp_list_pluck( $out['rejected'], 'reason' );
		$this->assertContains( 'invalid-key', $reasons );
		$this->assertContains( 'size-mismatch', $reasons );
	}

	// ---- revisions (atomic publication, validation order) ----------------

	public function test_apply_revision_publishes_and_serves_document() {
		$id  = $this->store->create_session( 1 )['previewId'];
		$res = $this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array( $this->write( 'index.html', '<h1>one</h1>' ) ) ),
			$this->fixed
		);
		$this->assertTrue( $res['active'] );
		$this->assertSame( 1, $res['revision'] );

		$lookup = $this->store->serve_lookup( $id, 'index.html', $this->fixed );
		$this->assertSame( 'document', $lookup['kind'] );
		$this->assertSame( '<h1>one</h1>', file_get_contents( $lookup['path'] ) );
	}

	public function test_apply_revision_conflict_on_stale_base() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->store->apply_revision( $id, $this->revision_meta( 0, 1, array( $this->write( 'index.html', 'a' ) ) ), $this->fixed );

		$res = $this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array( $this->write( 'index.html', 'stale' ) ) ),
			$this->fixed
		);
		$this->assertSame( 409, $res['status'] );
		$this->assertSame( 1, $res['currentRevision'] );
	}

	public function test_apply_revision_rejects_unsafe_path() {
		$id  = $this->store->create_session( 1 )['previewId'];
		$res = $this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array( $this->write( '../escape.html', 'x' ) ) ),
			$this->fixed
		);
		$this->assertSame( 400, $res['status'] );
	}

	public function test_apply_revision_missing_assets_422() {
		$id  = $this->store->create_session( 1 )['previewId'];
		$res = $this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array(), array( 'assetRefs' => array( 'r/ghost.png' => self::PHOTO_KEY ) ) ),
			$this->fixed
		);
		$this->assertSame( 422, $res['status'] );
		$this->assertSame( 'missing-assets', $res['reason'] );
		$this->assertSame( array( self::PHOTO_KEY ), $res['missing'] );
	}

	public function test_apply_revision_unknown_fixed_422() {
		$id  = $this->store->create_session( 1 )['previewId'];
		$res = $this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array(), array( 'fixedRefs' => array( 'libs/x.js' => 'libs/unknown' ) ) ),
			$this->fixed
		);
		$this->assertSame( 422, $res['status'] );
		$this->assertSame( 'unknown-fixed-resources', $res['reason'] );
		$this->assertSame( array( 'libs/unknown' ), $res['resources'] );
	}

	public function test_apply_revision_is_atomic_across_revisions() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array( $this->write( 'index.html', 'v1' ), $this->write( 'keep.html', 'keep' ) ) ),
			$this->fixed
		);
		$this->store->apply_revision(
			$id,
			$this->revision_meta( 1, 2, array( $this->write( 'index.html', 'v2' ) ) ),
			$this->fixed
		);

		// index.html updated, keep.html carried forward into revision 2.
		$this->assertSame( 'v2', file_get_contents( $this->store->serve_lookup( $id, 'index.html', $this->fixed )['path'] ) );
		$this->assertSame( 'keep', file_get_contents( $this->store->serve_lookup( $id, 'keep.html', $this->fixed )['path'] ) );
	}

	public function test_revision_delete_drops_document() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array( $this->write( 'gone.html', 'x' ), $this->write( 'index.html', 'i' ) ) ),
			$this->fixed
		);
		$this->store->apply_revision(
			$id,
			$this->revision_meta( 1, 2, array(), array( 'deletes' => array( 'gone.html' ) ) ),
			$this->fixed
		);
		$this->assertNull( $this->store->serve_lookup( $id, 'gone.html', $this->fixed ) );
		$this->assertNotNull( $this->store->serve_lookup( $id, 'index.html', $this->fixed ) );
	}

	// ---- serving resolution & TTL ----------------------------------------

	public function test_serve_lookup_resolves_asset_with_etag() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->store->store_assets( $id, array( $this->asset_entry( self::PHOTO_KEY, 'PNGDATA' ) ) );
		$this->store->apply_revision(
			$id,
			$this->revision_meta( 0, 1, array(), array( 'assetRefs' => array( 'img/p.png' => self::PHOTO_KEY ) ) ),
			$this->fixed
		);
		$lookup = $this->store->serve_lookup( $id, 'img/p.png', $this->fixed );
		$this->assertSame( 'asset', $lookup['kind'] );
		$this->assertSame( self::PHOTO_KEY, $lookup['etag'] );
		$this->assertSame( 'PNGDATA', file_get_contents( $lookup['path'] ) );
	}

	public function test_serve_lookup_unknown_and_bad_id() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->store->apply_revision( $id, $this->revision_meta( 0, 1, array( $this->write( 'index.html', 'x' ) ) ), $this->fixed );
		$this->assertNull( $this->store->serve_lookup( $id, 'nope.css', $this->fixed ) );
		$this->assertNull( $this->store->serve_lookup( 'not-a-uuid', 'index.html', $this->fixed ) );
		$this->assertNull( $this->store->serve_lookup( $id, '../secret', $this->fixed ) );
	}

	public function test_serve_lookup_before_first_revision_is_404() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->assertNull( $this->store->serve_lookup( $id, 'index.html', $this->fixed ) );
	}

	public function test_idle_ttl_expiry_deletes_on_serve() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->store->apply_revision( $id, $this->revision_meta( 0, 1, array( $this->write( 'index.html', 'x' ) ) ), $this->fixed );
		touch( $this->store_dir . '/' . $id . '/access', time() - ( ExeLearning_Preview_Session_Store::TTL_SECONDS + 60 ) );

		$this->assertNull( $this->store->serve_lookup( $id, 'index.html', $this->fixed ) );
		$this->assertFalse( is_dir( $this->store_dir . '/' . $id ), 'expired session should be reclaimed' );
	}

	public function test_sweep_expired_removes_idle_sessions() {
		$fresh   = $this->store->create_session( 1 )['previewId'];
		$expired = $this->store->create_session( 1 )['previewId'];
		touch( $this->store_dir . '/' . $expired . '/access', time() - ( ExeLearning_Preview_Session_Store::TTL_SECONDS + 60 ) );

		$this->assertSame( 1, $this->store->sweep_expired() );
		$this->assertTrue( is_dir( $this->store_dir . '/' . $fresh ) );
		$this->assertFalse( is_dir( $this->store_dir . '/' . $expired ) );
	}

	public function test_delete_session_removes_directory() {
		$id = $this->store->create_session( 1 )['previewId'];
		$this->assertTrue( $this->store->delete_session( $id ) );
		$this->assertFalse( is_dir( $this->store_dir . '/' . $id ) );
		$this->assertFalse( $this->store->delete_session( $id ) );
	}

	// ---- web-access guard (CSP bypass defence) ---------------------------

	public function test_base_dir_is_guarded_against_direct_web_access() {
		// The store lives under wp-content/uploads, which is web-servable. Author
		// HTML fetched directly (bypassing the REST route) would be served
		// same-origin WITHOUT the sandbox CSP. The base dir must deny direct
		// access as soon as it is used.
		$this->store->create_session( 1 );

		$htaccess = $this->store_dir . '/.htaccess';
		$index    = $this->store_dir . '/index.php';
		$this->assertFileExists( $htaccess );
		$this->assertFileExists( $index );

		$rules = file_get_contents( $htaccess );
		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( 'Deny from all', $rules );
		$this->assertStringContainsString( 'Silence is golden', file_get_contents( $index ) );
	}

	public function test_web_access_guard_is_idempotent_and_self_healing() {
		$this->store->create_session( 1 );
		$htaccess = $this->store_dir . '/.htaccess';

		// A tampered/removed guard is rewritten on the next store operation.
		unlink( $htaccess );
		$this->assertFileDoesNotExist( $htaccess );
		$this->store->create_session( 1 );
		$this->assertFileExists( $htaccess );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $htaccess ) );
	}

	public function test_ships_nginx_deny_snippet_for_non_apache_servers() {
		// nginx does not read the .htaccess guard, so the plugin must ship an
		// includable deny snippet for the preview store — otherwise a direct GET
		// serves untrusted author HTML same-origin without the sandbox CSP.
		$snippet = EXELEARNING_PLUGIN_DIR . 'nginx-exelearning-preview.conf';
		$this->assertFileExists( $snippet );
		$conf = file_get_contents( $snippet );
		$this->assertStringContainsString( 'exelearning-preview/', $conf );
		$this->assertMatchesRegularExpression( '/return\s+403/', $conf );
	}
}
