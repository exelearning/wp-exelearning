<?php
/**
 * Tests for the stale content URL redirect behavior of the content proxy.
 *
 * Covers the redirect fallback designed in SDD-0001: requests for a known
 * obsolete extraction hash return a temporary same-origin redirect to the
 * equivalent validated file path under the owning attachment's current
 * extraction hash, while every invalid, unknown, ambiguous or unsafe case
 * keeps returning the existing safe errors.
 *
 * @package Exelearning
 */

/**
 * Class StaleContentRedirectTest.
 *
 * @covers ExeLearning_Content_Proxy
 */
class StaleContentRedirectTest extends WP_UnitTestCase {

	/**
	 * Meta key holding the current extraction hash.
	 *
	 * @var string
	 */
	const CURRENT_META = '_exelearning_extracted';

	/**
	 * Meta key holding retired (obsolete) extraction hashes.
	 *
	 * @var string
	 */
	const ALIAS_META = '_exelearning_obsolete_hash';

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Content_Proxy
	 */
	private $proxy;

	/**
	 * Paths created during a test that must be removed on tear down.
	 *
	 * @var string[]
	 */
	private $cleanup_paths = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->proxy         = new ExeLearning_Content_Proxy();
		$this->cleanup_paths = array();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		foreach ( $this->cleanup_paths as $path ) {
			$this->recursive_delete( $path );
		}
		parent::tear_down();
	}

	/**
	 * Generate a unique, format-valid extraction hash.
	 *
	 * @return string 40-char lowercase hex hash.
	 */
	private function make_hash() {
		return sha1( uniqid( 'exe-test-', true ) );
	}

	/**
	 * Create an extraction directory for a hash with an index.html and a
	 * nested asset file.
	 *
	 * @param string $hash Extraction hash.
	 * @return string Directory path.
	 */
	private function make_extraction_dir( $hash ) {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';
		wp_mkdir_p( $dir . 'assets/css' );
		file_put_contents( $dir . 'index.html', '<html><body>Current</body></html>' ); // phpcs:ignore
		file_put_contents( $dir . 'assets/css/main.css', 'body{}' ); // phpcs:ignore
		$this->cleanup_paths[] = $dir;
		return $dir;
	}

	/**
	 * Create an attachment with a current extraction (hash meta + directory).
	 *
	 * @return array { id: int, hash: string, dir: string }
	 */
	private function make_attachment_with_extraction() {
		$attachment_id = $this->factory->attachment->create();
		$current_hash  = $this->make_hash();
		update_post_meta( $attachment_id, self::CURRENT_META, $current_hash );
		$dir = $this->make_extraction_dir( $current_hash );

		return array(
			'id'   => $attachment_id,
			'hash' => $current_hash,
			'dir'  => $dir,
		);
	}

	/**
	 * Register an obsolete-hash alias for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $old_hash      Obsolete hash.
	 */
	private function register_alias( $attachment_id, $old_hash ) {
		$aliases = new ExeLearning_Content_Hash_Aliases();
		$this->assertTrue( $aliases->register( $attachment_id, $old_hash ) );
	}

	/**
	 * Dispatch a request through the content proxy.
	 *
	 * @param string $hash  Requested hash.
	 * @param string $file  Requested file path.
	 * @param array  $query Extra query parameters for the request.
	 * @return WP_REST_Response|WP_Error Proxy result.
	 */
	private function serve( $hash, $file = 'index.html', $query = array() ) {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/' . $hash . '/' . $file );
		// Route parameters land in the URL slot when the REST server routes a
		// real request; mirror that so query params stay separate.
		$request->set_url_params(
			array(
				'hash' => $hash,
				'file' => $file,
			)
		);
		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

		return $this->proxy->serve_content( $request );
	}

	/**
	 * Assert a result is a temporary redirect and return its Location header.
	 *
	 * @param mixed $result Proxy result.
	 * @return string Location header value.
	 */
	private function assert_temporary_redirect( $result ) {
		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 302, $result->get_status() );

		$headers = $result->get_headers();
		$this->assertArrayHasKey( 'Location', $headers );
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );

		return $headers['Location'];
	}

	/**
	 * A known obsolete hash redirects temporarily to index.html under the
	 * attachment's current hash.
	 */
	public function test_stale_hash_redirects_to_current_index() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$location = $this->assert_temporary_redirect( $this->serve( $old_hash, 'index.html' ) );

		$this->assertStringContainsString( $fixture['hash'], $location );
		$this->assertStringContainsString( 'index.html', $location );
		$this->assertStringNotContainsString( $old_hash, $location );
	}

	/**
	 * The requested nested relative path is preserved in the redirect.
	 */
	public function test_stale_hash_redirect_preserves_nested_path() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$location = $this->assert_temporary_redirect( $this->serve( $old_hash, 'assets/css/main.css' ) );

		$this->assertStringContainsString( $fixture['hash'] . '/assets/css/main.css', $location );
	}

	/**
	 * Request query parameters (e.g. ?exe-teacher=1) are preserved in the
	 * redirect location.
	 */
	public function test_stale_hash_redirect_preserves_query_parameters() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$location = $this->assert_temporary_redirect(
			$this->serve( $old_hash, 'index.html', array( 'exe-teacher' => '1' ) )
		);

		$this->assertStringContainsString( 'exe-teacher=1', $location );
		$this->assertStringContainsString( $fixture['hash'], $location );
	}

	/**
	 * The plain-permalink routing argument of the original request is not
	 * duplicated into the redirect location.
	 */
	public function test_stale_hash_redirect_drops_original_rest_route_param() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$location = $this->assert_temporary_redirect(
			$this->serve(
				$old_hash,
				'index.html',
				array(
					'rest_route'  => '/exelearning/v1/content/' . $old_hash . '/index.html',
					'exe-teacher' => '1',
				)
			)
		);

		$this->assertStringContainsString( 'exe-teacher=1', $location );
		$this->assertStringNotContainsString( $old_hash, $location );
	}

	/**
	 * After multiple sequential saves every retired hash redirects directly
	 * to the latest hash — never through an intermediate hash.
	 */
	public function test_sequential_saves_redirect_directly_to_latest() {
		$fixture = $this->make_attachment_with_extraction();
		$hash_a  = $this->make_hash();
		$hash_b  = $this->make_hash();
		$this->register_alias( $fixture['id'], $hash_a );
		$this->register_alias( $fixture['id'], $hash_b );

		$location_a = $this->assert_temporary_redirect( $this->serve( $hash_a ) );
		$location_b = $this->assert_temporary_redirect( $this->serve( $hash_b ) );

		$this->assertStringContainsString( $fixture['hash'], $location_a );
		$this->assertStringContainsString( $fixture['hash'], $location_b );
		$this->assertStringNotContainsString( $hash_b, $location_a );
		$this->assertStringNotContainsString( $hash_a, $location_b );
	}

	/**
	 * A syntactically valid but unknown hash keeps returning file_not_found.
	 */
	public function test_unknown_hash_still_returns_file_not_found() {
		$result = $this->serve( $this->make_hash() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Malformed hashes keep returning the existing validation error.
	 */
	public function test_invalid_hash_still_returns_invalid_hash() {
		$result = $this->serve( 'invalid-hash' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_hash', $result->get_error_code() );
	}

	/**
	 * Raw traversal attempts fail path validation and are never redirected,
	 * even when an alias exists for the requested hash.
	 */
	public function test_traversal_never_redirects() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$result = $this->serve( $old_hash, '../../../etc/passwd' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_path', $result->get_error_code() );
	}

	/**
	 * Encoded traversal attempts fail path validation and are never
	 * redirected.
	 */
	public function test_encoded_traversal_never_redirects() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$result = $this->serve( $old_hash, '%2e%2e/%2e%2e/secret' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_path', $result->get_error_code() );
	}

	/**
	 * When the equivalent file does not exist under the current extraction,
	 * the proxy returns the safe 404 instead of redirecting to a dead target.
	 */
	public function test_missing_destination_file_returns_not_found() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$result = $this->serve( $old_hash, 'not-in-current-extraction.html' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * An alias whose attachment has a malformed current hash never redirects.
	 */
	public function test_malformed_current_hash_never_redirects() {
		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, self::CURRENT_META, 'not-a-valid-hash' );
		$old_hash = $this->make_hash();
		add_post_meta( $attachment_id, self::ALIAS_META, $old_hash );

		$result = $this->serve( $old_hash );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * A self-referencing alias (hash equals the attachment's current hash)
	 * never redirects — no loop is possible.
	 */
	public function test_self_referencing_alias_never_redirects() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = $this->make_hash();
		update_post_meta( $attachment_id, self::CURRENT_META, $hash );
		// Inject the invalid self-alias directly, bypassing register() guards.
		add_post_meta( $attachment_id, self::ALIAS_META, $hash );

		$result = $this->serve( $hash );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Alias meta on a non-attachment post never redirects.
	 */
	public function test_alias_on_non_attachment_post_never_redirects() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'page' ) );
		$hash    = $this->make_hash();
		update_post_meta( $post_id, self::CURRENT_META, $this->make_hash() );
		add_post_meta( $post_id, self::ALIAS_META, $hash );

		$result = $this->serve( $hash );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * A hash aliased (via direct meta manipulation) to two attachments is
	 * ambiguous and never redirects.
	 */
	public function test_ambiguous_alias_never_redirects() {
		$fixture_a = $this->make_attachment_with_extraction();
		$fixture_b = $this->make_attachment_with_extraction();
		$hash      = $this->make_hash();
		add_post_meta( $fixture_a['id'], self::ALIAS_META, $hash );
		add_post_meta( $fixture_b['id'], self::ALIAS_META, $hash );

		$result = $this->serve( $hash );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * A hash that is still the CURRENT hash of another attachment is never
	 * redirected, even when stale alias data exists for it (shared legacy
	 * hashes cannot be hijacked).
	 */
	public function test_shared_current_hash_never_redirects() {
		$shared_hash = $this->make_hash();
		$owner_id    = $this->factory->attachment->create();
		update_post_meta( $owner_id, self::CURRENT_META, $shared_hash );

		$editing = $this->make_attachment_with_extraction();
		add_post_meta( $editing['id'], self::ALIAS_META, $shared_hash );

		// The shared directory is missing (e.g. removed out-of-band): the
		// request must fail safely instead of redirecting to the editor's
		// current content.
		$result = $this->serve( $shared_hash );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * After the owning attachment is permanently deleted, its stale hashes
	 * stop redirecting and return the safe 404 without warnings.
	 */
	public function test_deleted_attachment_stops_redirecting() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		// Sanity: redirect works while the attachment exists.
		$this->assert_temporary_redirect( $this->serve( $old_hash ) );

		wp_delete_attachment( $fixture['id'], true );

		$result = $this->serve( $old_hash );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
		$this->assertSame( array(), get_post_meta( $fixture['id'], self::ALIAS_META ) );
	}

	/**
	 * The redirect location stays on the site's own REST origin.
	 */
	public function test_redirect_location_is_same_origin() {
		$fixture  = $this->make_attachment_with_extraction();
		$old_hash = $this->make_hash();
		$this->register_alias( $fixture['id'], $old_hash );

		$location = $this->assert_temporary_redirect( $this->serve( $old_hash ) );

		$expected_base = ExeLearning_Content_Proxy::get_proxy_url( $fixture['hash'], 'index.html' );
		$this->assertStringStartsWith( $expected_base, $location );
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
			unlink( $dir ); // phpcs:ignore
		} else {
			$files = array_diff( scandir( $dir ), array( '.', '..' ) );
			foreach ( $files as $file ) {
				$this->recursive_delete( $dir . DIRECTORY_SEPARATOR . $file );
			}
			rmdir( $dir ); // phpcs:ignore
		}
	}
}
