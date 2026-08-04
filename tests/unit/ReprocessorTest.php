<?php
/**
 * Tests for ExeLearning_Reprocessor class.
 *
 * @package Exelearning
 */

/**
 * Class ReprocessorTest.
 *
 * @covers ExeLearning_Reprocessor
 */
class ReprocessorTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Reprocessor
	 */
	private $reprocessor;

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
		$this->reprocessor   = new ExeLearning_Reprocessor();
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
	 * Create an attachment backed by a .elpx file on disk.
	 *
	 * @param bool $with_index Whether the archive includes index.html (previewable).
	 * @param bool $valid_zip  Whether the file is a real ZIP (false writes garbage bytes).
	 * @return array { id: int, path: string }
	 */
	private function make_elpx_attachment( $with_index = true, $valid_zip = true ) {
		$user_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => $user_id,
				'post_title'     => 'Existing ELP',
			)
		);
		wp_set_current_user( $user_id );

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/reprocess-' . $attachment_id . '.elpx';

		if ( $valid_zip ) {
			$zip = new ZipArchive();
			$zip->open( $file_path, ZipArchive::CREATE );
			$zip->addFromString( 'content.xml', '<package></package>' );
			if ( $with_index ) {
				$zip->addFromString( 'index.html', '<html><body>Preview</body></html>' );
			}
			$zip->close();
		} else {
			file_put_contents( $file_path, 'this is not a zip file' ); // phpcs:ignore
		}

		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		return array(
			'id'   => $attachment_id,
			'path' => $file_path,
		);
	}

	/**
	 * Create an attachment backed by a ZIP file with an arbitrary extension.
	 *
	 * @param string $ext             File extension (e.g. 'zip').
	 * @param bool   $with_content_xml Whether the archive contains content.xml (i.e. is a real eXeLearning project).
	 * @param bool   $with_index      Whether the archive includes index.html (previewable).
	 * @return array { id: int, path: string }
	 */
	private function make_zip_attachment( $ext, $with_content_xml = true, $with_index = true ) {
		$user_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => $user_id,
				'post_title'     => 'Existing ZIP',
			)
		);
		wp_set_current_user( $user_id );

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/reprocess-' . $attachment_id . '.' . $ext;

		$zip = new ZipArchive();
		$zip->open( $file_path, ZipArchive::CREATE );
		if ( $with_content_xml ) {
			$zip->addFromString( 'content.xml', '<package></package>' );
		} else {
			$zip->addFromString( 'readme.txt', 'just a backup archive, not eXeLearning' );
		}
		if ( $with_index ) {
			$zip->addFromString( 'index.html', '<html><body>Preview</body></html>' );
		}
		$zip->close();

		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		return array(
			'id'   => $attachment_id,
			'path' => $file_path,
		);
	}

	/**
	 * Absolute path to the extraction directory for a hash.
	 *
	 * @param string $hash Extraction hash.
	 * @return string
	 */
	private function extraction_dir( $hash ) {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';
	}

	/**
	 * Recursively delete a path.
	 *
	 * @param string $dir Path.
	 */
	private function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			unlink( $dir ); // phpcs:ignore
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$this->recursive_delete( $dir . DIRECTORY_SEPARATOR . $file );
		}
		rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Invalid attachment id is rejected.
	 */
	public function test_reprocess_invalid_attachment() {
		$result = $this->reprocessor->reprocess( 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_attachment', $result->get_error_code() );
	}

	/**
	 * A non-attachment post is rejected.
	 */
	public function test_reprocess_non_attachment() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->reprocessor->reprocess( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_attachment', $result->get_error_code() );
	}

	/**
	 * A non-elpx attachment is rejected.
	 */
	public function test_reprocess_non_elpx_file() {
		$attachment_id = $this->factory->attachment->create();
		$upload_dir    = wp_upload_dir();
		$file_path     = $upload_dir['basedir'] . '/not-elp-' . $attachment_id . '.jpg';
		file_put_contents( $file_path, 'fake image' ); // phpcs:ignore
		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		$result = $this->reprocessor->reprocess( $attachment_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_file_type', $result->get_error_code() );
	}

	/**
	 * Missing underlying file is reported.
	 */
	public function test_reprocess_missing_file() {
		$attachment_id = $this->factory->attachment->create();
		update_attached_file( $attachment_id, '/nonexistent/path/file.elpx' );

		$result = $this->reprocessor->reprocess( $attachment_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Reprocessing an existing previewable .elpx extracts and sets metadata.
	 */
	public function test_reprocess_creates_extraction_and_metadata() {
		$fixture = $this->make_elpx_attachment( true );
		$id      = $fixture['id'];

		// Precondition: not extracted yet.
		$this->assertEmpty( get_post_meta( $id, '_exelearning_extracted', true ) );

		$result = $this->reprocessor->reprocess( $id );

		$this->assertIsArray( $result );

		$hash = get_post_meta( $id, '_exelearning_extracted', true );
		$this->assertNotEmpty( $hash );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $hash );
		$this->assertEquals( '1', get_post_meta( $id, '_exelearning_has_preview', true ) );

		$dir = $this->extraction_dir( $hash );
		$this->cleanup_paths[] = $dir;
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . 'index.html' );
	}

	/**
	 * A .elpx without index.html extracts but is marked as not previewable.
	 */
	public function test_reprocess_without_preview() {
		$fixture = $this->make_elpx_attachment( false );
		$id      = $fixture['id'];

		$result = $this->reprocessor->reprocess( $id );

		$this->assertIsArray( $result );

		$hash = get_post_meta( $id, '_exelearning_extracted', true );
		$this->assertNotEmpty( $hash );
		$this->assertEquals( '0', get_post_meta( $id, '_exelearning_has_preview', true ) );

		$this->cleanup_paths[] = $this->extraction_dir( $hash );
	}

	/**
	 * An invalid .elpx returns a clear error and writes no extraction metadata.
	 */
	public function test_reprocess_invalid_file_returns_error() {
		$fixture = $this->make_elpx_attachment( true, false );
		$id      = $fixture['id'];

		$result = $this->reprocessor->reprocess( $id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( get_post_meta( $id, '_exelearning_extracted', true ) );
	}

	/**
	 * Reprocessing is idempotent: running twice leaves a single extraction.
	 */
	public function test_reprocess_is_idempotent() {
		$fixture = $this->make_elpx_attachment( true );
		$id      = $fixture['id'];

		$this->reprocessor->reprocess( $id );
		$first_hash = get_post_meta( $id, '_exelearning_extracted', true );
		$this->cleanup_paths[] = $this->extraction_dir( $first_hash );

		$this->reprocessor->reprocess( $id );
		$second_hash = get_post_meta( $id, '_exelearning_extracted', true );
		$this->cleanup_paths[] = $this->extraction_dir( $second_hash );

		// A fresh directory is used each run...
		$this->assertNotEquals( $first_hash, $second_hash );
		// ...and the previous one is cleaned up (no orphans).
		$this->assertDirectoryDoesNotExist( $this->extraction_dir( $first_hash ) );
		$this->assertDirectoryExists( $this->extraction_dir( $second_hash ) );
	}

	/**
	 * On failure the previously-good extraction and metadata are preserved.
	 */
	public function test_reprocess_failure_preserves_existing_extraction() {
		// First a successful extraction.
		$fixture = $this->make_elpx_attachment( true );
		$id      = $fixture['id'];
		$this->reprocessor->reprocess( $id );
		$good_hash             = get_post_meta( $id, '_exelearning_extracted', true );
		$good_dir              = $this->extraction_dir( $good_hash );
		$this->cleanup_paths[] = $good_dir;
		$this->assertDirectoryExists( $good_dir );

		// Now corrupt the underlying file and reprocess again.
		file_put_contents( $fixture['path'], 'corrupted, not a zip' ); // phpcs:ignore

		$result = $this->reprocessor->reprocess( $id );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Old extraction + metadata untouched.
		$this->assertEquals( $good_hash, get_post_meta( $id, '_exelearning_extracted', true ) );
		$this->assertDirectoryExists( $good_dir );
	}

	/**
	 * After reprocessing, the [exelearning] shortcode renders the preview iframe.
	 */
	public function test_shortcode_renders_preview_after_reprocess() {
		$fixture = $this->make_elpx_attachment( true );
		$id      = $fixture['id'];

		// Before: shortcode falls back to the no-preview/download view.
		$shortcodes = new ExeLearning_Shortcodes();
		$before     = $shortcodes->display_exelearning( array( 'id' => $id ) );
		$this->assertStringNotContainsString( 'exelearning-iframe', $before );

		// Reprocess, then the shortcode renders the iframe.
		$this->reprocessor->reprocess( $id );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $id, '_exelearning_extracted', true ) );

		$after = $shortcodes->display_exelearning( array( 'id' => $id ) );
		$this->assertStringContainsString( 'exelearning-iframe', $after );
	}

	/**
	 * A .zip whose contents validate as eXeLearning is reprocessed.
	 */
	public function test_reprocess_accepts_valid_exelearning_zip() {
		$fixture = $this->make_zip_attachment( 'zip', true, true );
		$id      = $fixture['id'];

		$result = $this->reprocessor->reprocess( $id );

		$this->assertIsArray( $result );

		$hash = get_post_meta( $id, '_exelearning_extracted', true );
		$this->assertNotEmpty( $hash );
		$this->assertEquals( '1', get_post_meta( $id, '_exelearning_has_preview', true ) );

		$this->cleanup_paths[] = get_attached_file( $id );
		$this->cleanup_paths[] = $this->extraction_dir( $hash );
	}

	/**
	 * A reprocessed .zip is renamed to the canonical .elpx so the editor/exporter accept it.
	 */
	public function test_reprocess_renames_valid_zip_to_elpx() {
		$fixture = $this->make_zip_attachment( 'zip', true, true );
		$id      = $fixture['id'];

		$result = $this->reprocessor->reprocess( $id );
		$this->assertIsArray( $result );

		$new_path = get_attached_file( $id );
		$this->cleanup_paths[] = $new_path;
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $id, '_exelearning_extracted', true ) );

		$this->assertStringEndsWith( '.elpx', $new_path );
		$this->assertFileExists( $new_path );
		// The original .zip was moved, not left behind.
		$this->assertFileDoesNotExist( $fixture['path'] );
		// It is now a first-class .elpx everywhere.
		$this->assertTrue( $this->reprocessor->is_exelearning_candidate( $id ) );
	}

	/**
	 * A plain .zip (no content.xml) is rejected and writes no metadata.
	 */
	public function test_reprocess_rejects_plain_zip() {
		$fixture = $this->make_zip_attachment( 'zip', false, false );
		$id      = $fixture['id'];

		$result = $this->reprocessor->reprocess( $id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( get_post_meta( $id, '_exelearning_extracted', true ) );
	}

	/**
	 * is_exelearning_candidate() accepts .elpx and .zip, rejects other types.
	 */
	public function test_is_exelearning_candidate() {
		$elpx = $this->make_elpx_attachment( true );
		$zip  = $this->make_zip_attachment( 'zip', true, true );

		$this->assertTrue( $this->reprocessor->is_exelearning_candidate( $elpx['id'] ) );
		$this->assertTrue( $this->reprocessor->is_exelearning_candidate( $zip['id'] ) );

		$attachment_id = $this->factory->attachment->create();
		$upload_dir    = wp_upload_dir();
		$file_path     = $upload_dir['basedir'] . '/cand-' . $attachment_id . '.jpg';
		file_put_contents( $file_path, 'x' ); // phpcs:ignore
		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		$this->assertFalse( $this->reprocessor->is_exelearning_candidate( $attachment_id ) );
	}

	/**
	 * needs_reprocessing() is true for a valid unprocessed eXeLearning .zip.
	 */
	public function test_needs_reprocessing_true_for_valid_zip() {
		$fixture = $this->make_zip_attachment( 'zip', true, true );

		$this->assertTrue( $this->reprocessor->needs_reprocessing( $fixture['id'] ) );
	}

	/**
	 * needs_reprocessing() is false for a plain .zip (content does not validate).
	 */
	public function test_needs_reprocessing_false_for_plain_zip() {
		$fixture = $this->make_zip_attachment( 'zip', false, false );

		$this->assertFalse( $this->reprocessor->needs_reprocessing( $fixture['id'] ) );
	}

	/**
	 * needs_reprocessing() is true for an unprocessed .elpx attachment.
	 */
	public function test_needs_reprocessing_true_for_unprocessed_elpx() {
		$fixture = $this->make_elpx_attachment( true );

		$this->assertTrue( $this->reprocessor->needs_reprocessing( $fixture['id'] ) );
	}

	/**
	 * needs_reprocessing() is false once an attachment has a valid extraction.
	 */
	public function test_needs_reprocessing_false_after_reprocess() {
		$fixture = $this->make_elpx_attachment( true );
		$id      = $fixture['id'];

		$this->reprocessor->reprocess( $id );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $id, '_exelearning_extracted', true ) );

		$this->assertFalse( $this->reprocessor->needs_reprocessing( $id ) );
	}

	/**
	 * needs_reprocessing() is false for a non-elpx attachment.
	 */
	public function test_needs_reprocessing_false_for_non_elpx() {
		$attachment_id = $this->factory->attachment->create();
		$upload_dir    = wp_upload_dir();
		$file_path     = $upload_dir['basedir'] . '/img-' . $attachment_id . '.png';
		file_put_contents( $file_path, 'x' ); // phpcs:ignore
		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		$this->assertFalse( $this->reprocessor->needs_reprocessing( $attachment_id ) );
	}

	/**
	 * needs_reprocessing() is true when the extraction directory is gone.
	 */
	public function test_needs_reprocessing_true_when_extraction_dir_missing() {
		$fixture = $this->make_elpx_attachment( true );
		$id      = $fixture['id'];

		// Metadata points at a hash whose directory does not exist.
		update_post_meta( $id, '_exelearning_extracted', str_repeat( 'a', 40 ) );

		$this->assertTrue( $this->reprocessor->needs_reprocessing( $id ) );
	}

	/**
	 * get_reprocessable_attachment_ids() finds only unprocessed .elpx files.
	 */
	public function test_get_reprocessable_attachment_ids() {
		$unprocessed = $this->make_elpx_attachment( true );

		$processed = $this->make_elpx_attachment( true );
		$this->reprocessor->reprocess( $processed['id'] );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $processed['id'], '_exelearning_extracted', true ) );

		// A non-elpx attachment that must never be returned.
		$image = $this->factory->attachment->create();
		$upload_dir = wp_upload_dir();
		$img_path   = $upload_dir['basedir'] . '/photo-' . $image . '.jpg';
		file_put_contents( $img_path, 'x' ); // phpcs:ignore
		$this->cleanup_paths[] = $img_path;
		update_attached_file( $image, $img_path );

		// A .zip that IS a valid eXeLearning project must be picked up...
		$valid_zip = $this->make_zip_attachment( 'zip', true, true );
		// ...while a plain backup .zip must be ignored.
		$plain_zip = $this->make_zip_attachment( 'zip', false, false );

		$ids = $this->reprocessor->get_reprocessable_attachment_ids();

		$this->assertContains( $unprocessed['id'], $ids );
		$this->assertContains( $valid_zip['id'], $ids );
		$this->assertNotContains( $processed['id'], $ids );
		$this->assertNotContains( $plain_zip['id'], $ids );
		$this->assertNotContains( $image, $ids );
	}

	/* -------------------------------------------------------------------- */
	/* Stale-hash retirement (SDD-0001)                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Create an extraction directory on disk for a hash.
	 *
	 * @param string $hash Extraction hash.
	 * @return string Directory path.
	 */
	private function make_extraction_dir( $hash ) {
		$dir = $this->extraction_dir( $hash );
		wp_mkdir_p( $dir );
		file_put_contents( $dir . 'index.html', '<html></html>' ); // phpcs:ignore
		$this->cleanup_paths[] = $dir;
		return $dir;
	}

	/**
	 * retire_extraction() persists the obsolete-hash alias before deleting
	 * the old extraction directory.
	 */
	public function test_retire_extraction_registers_alias_then_deletes() {
		$old_hash = sha1( uniqid( 'old', true ) );
		$new_hash = sha1( uniqid( 'new', true ) );

		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, '_exelearning_extracted', $new_hash );
		$old_dir = $this->make_extraction_dir( $old_hash );

		$this->reprocessor->retire_extraction( $attachment_id, $old_hash, $new_hash );

		$this->assertContains( $old_hash, get_post_meta( $attachment_id, '_exelearning_obsolete_hash' ) );
		$this->assertDirectoryDoesNotExist( $old_dir );
	}

	/**
	 * An unchanged hash creates neither an alias nor a deletion (no
	 * self-reference, no data loss).
	 */
	public function test_retire_extraction_unchanged_hash_is_noop() {
		$hash          = sha1( uniqid( 'same', true ) );
		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		$dir = $this->make_extraction_dir( $hash );

		$this->reprocessor->retire_extraction( $attachment_id, $hash, $hash );

		$this->assertSame( array(), get_post_meta( $attachment_id, '_exelearning_obsolete_hash' ) );
		$this->assertDirectoryExists( $dir );
	}

	/**
	 * An empty old hash (first save) is a no-op.
	 */
	public function test_retire_extraction_empty_old_hash_is_noop() {
		$attachment_id = $this->factory->attachment->create();
		$new_hash      = sha1( uniqid( 'new', true ) );
		update_post_meta( $attachment_id, '_exelearning_extracted', $new_hash );

		$this->reprocessor->retire_extraction( $attachment_id, '', $new_hash );

		$this->assertSame( array(), get_post_meta( $attachment_id, '_exelearning_obsolete_hash' ) );
	}

	/**
	 * A hash still current for ANOTHER attachment is neither deleted nor
	 * aliased (legacy shared hashes stay untouched).
	 */
	public function test_retire_extraction_keeps_shared_current_hash() {
		$shared_hash = sha1( uniqid( 'shared', true ) );
		$new_hash    = sha1( uniqid( 'new', true ) );

		$other_id = $this->factory->attachment->create();
		update_post_meta( $other_id, '_exelearning_extracted', $shared_hash );

		$editing_id = $this->factory->attachment->create();
		update_post_meta( $editing_id, '_exelearning_extracted', $new_hash );

		$shared_dir = $this->make_extraction_dir( $shared_hash );

		$this->reprocessor->retire_extraction( $editing_id, $shared_hash, $new_hash );

		$this->assertDirectoryExists( $shared_dir );
		$this->assertSame( array(), get_post_meta( $editing_id, '_exelearning_obsolete_hash' ) );
	}

	/**
	 * reprocess() retires the previous hash: the old directory is replaced,
	 * the old hash becomes an alias, and the proxy redirects it to the new
	 * extraction.
	 */
	public function test_reprocess_retires_previous_hash_and_redirects() {
		$fixture = $this->make_elpx_attachment( true );

		// First processing pass establishes the initial extraction.
		$first = $this->reprocessor->reprocess( $fixture['id'] );
		$this->assertIsArray( $first );
		$this->cleanup_paths[] = $this->extraction_dir( $first['hash'] );

		// Second pass simulates an edit+save: the hash changes.
		$second = $this->reprocessor->reprocess( $fixture['id'] );
		$this->assertIsArray( $second );
		$this->cleanup_paths[] = $this->extraction_dir( $second['hash'] );
		$this->assertNotSame( $first['hash'], $second['hash'] );

		// The retired hash is aliased and its directory is gone.
		$this->assertContains( $first['hash'], get_post_meta( $fixture['id'], '_exelearning_obsolete_hash' ) );
		$this->assertDirectoryDoesNotExist( $this->extraction_dir( $first['hash'] ) );

		// The proxy answers the retired hash with a redirect to the new one.
		$proxy   = new ExeLearning_Content_Proxy();
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/' . $first['hash'] . '/index.html' );
		$request->set_url_params(
			array(
				'hash' => $first['hash'],
				'file' => 'index.html',
			)
		);

		$result = $proxy->serve_content( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 302, $result->get_status() );
		$headers = $result->get_headers();
		$this->assertStringContainsString( $second['hash'], $headers['Location'] );

		// A third pass leaves BOTH retired hashes redirecting to the latest.
		$third = $this->reprocessor->reprocess( $fixture['id'] );
		$this->assertIsArray( $third );
		$this->cleanup_paths[] = $this->extraction_dir( $third['hash'] );

		foreach ( array( $first['hash'], $second['hash'] ) as $retired ) {
			$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/' . $retired . '/index.html' );
			$request->set_url_params(
				array(
					'hash' => $retired,
					'file' => 'index.html',
				)
			);

			$result = $proxy->serve_content( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $result );
			$this->assertSame( 302, $result->get_status() );
			$headers = $result->get_headers();
			$this->assertStringContainsString( $third['hash'], $headers['Location'] );
		}
	}

	/**
	 * A failed reprocess creates no alias, keeps the previous extraction
	 * directory, and leaves the extraction meta unchanged.
	 */
	public function test_failed_reprocess_creates_no_alias() {
		$fixture = $this->make_elpx_attachment( true, false ); // Not a real ZIP.

		$old_hash = sha1( uniqid( 'old', true ) );
		update_post_meta( $fixture['id'], '_exelearning_extracted', $old_hash );
		$old_dir = $this->make_extraction_dir( $old_hash );

		$result = $this->reprocessor->reprocess( $fixture['id'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), get_post_meta( $fixture['id'], '_exelearning_obsolete_hash' ) );
		$this->assertDirectoryExists( $old_dir );
		$this->assertSame( $old_hash, get_post_meta( $fixture['id'], '_exelearning_extracted', true ) );
	}

	/**
	 * Only attachments are candidates; an ordinary post never is.
	 */
	public function test_a_post_is_not_an_exelearning_candidate() {
		$post_id = $this->factory->post->create();

		$this->assertFalse( $this->reprocessor->is_exelearning_candidate( $post_id ) );
		$this->assertFalse( $this->reprocessor->is_exelearning_candidate( 0 ) );
	}

	/**
	 * An attachment with no file on record cannot be classified by extension.
	 */
	public function test_an_attachment_without_a_file_is_not_a_candidate() {
		$attachment_id = $this->factory->attachment->create();
		delete_post_meta( $attachment_id, '_wp_attached_file' );

		$this->assertFalse( $this->reprocessor->is_exelearning_candidate( $attachment_id ) );
	}

	/**
	 * A .zip whose file has disappeared cannot be content-checked, so it is not
	 * eligible even though its extension is accepted.
	 */
	public function test_a_zip_whose_file_is_missing_is_not_eligible() {
		$zip = $this->make_zip_attachment( 'zip' );
		wp_delete_file( $zip['path'] );

		$this->assertTrue( $this->reprocessor->is_exelearning_candidate( $zip['id'] ) );
		$this->assertFalse( $this->reprocessor->is_eligible( $zip['id'] ) );
	}

	/**
	 * The candidate scan returns every eXeLearning attachment — processed or
	 * not — while filtering out plain archives that only look like one.
	 */
	public function test_the_candidate_scan_returns_exelearning_attachments_only() {
		$elpx        = $this->make_elpx_attachment();
		$exe_zip     = $this->make_zip_attachment( 'zip' );
		$plain_zip   = $this->make_zip_attachment( 'zip', false );
		$unrelated   = $this->factory->attachment->create();
		$this->reprocessor->reprocess( $elpx['id'] );

		$ids = $this->reprocessor->get_candidate_attachment_ids();

		$this->assertContains( $elpx['id'], $ids, 'An already-processed file stays a candidate.' );
		$this->assertContains( $exe_zip['id'], $ids );
		$this->assertNotContains( $plain_zip['id'], $ids, 'A plain .zip is not eXeLearning content.' );
		$this->assertNotContains( $unrelated, $ids );

		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $elpx['id'], '_exelearning_extracted', true ) );
	}

	/**
	 * A failed extraction leaves no half-written directory behind.
	 */
	public function test_a_failed_extraction_removes_its_own_directory() {
		$elpx = $this->make_elpx_attachment();

		$created = array();
		add_action(
			'exelearning_before_elpx_extract',
			static function ( $file, $destination ) use ( &$created ) {
				$created[] = $destination;
			},
			10,
			2
		);
		// Force the zip-bomb guard to trip on a perfectly ordinary archive.
		add_filter( 'exelearning_max_extract_bytes', '__return_zero' );

		$result = $this->reprocessor->extract_to_new_dir( $elpx['path'] );

		remove_filter( 'exelearning_max_extract_bytes', '__return_zero' );

		$this->assertWPError( $result );
		$this->assertSame( 'elp_too_large', $result->get_error_code() );
		$this->assertNotEmpty( $created );
		$this->assertDirectoryDoesNotExist( $created[0] );
	}

	/**
	 * Cleaning up an unknown or empty hash is a no-op rather than an error.
	 */
	public function test_cleanup_by_hash_ignores_unknown_hashes() {
		$this->reprocessor->cleanup_by_hash( '' );
		$this->reprocessor->cleanup_by_hash( str_repeat( 'f', 40 ) );

		$this->assertDirectoryDoesNotExist( $this->extraction_dir( str_repeat( 'f', 40 ) ) );
	}


	/**
	 * An unusable uploads directory is reported instead of being mistaken for
	 * an empty extraction.
	 */
	public function test_extraction_reports_an_unusable_uploads_directory() {
		$elpx = $this->make_elpx_attachment();

		// A regular file cannot have children, so every mkdir below it fails.
		$blocker = wp_tempnam( 'uploads-blocker' );
		$broken  = static function ( $dirs ) use ( $blocker ) {
			$dirs['basedir'] = $blocker . '/uploads';
			return $dirs;
		};
		add_filter( 'upload_dir', $broken );

		$result = $this->reprocessor->extract_to_new_dir( $elpx['path'] );

		remove_filter( 'upload_dir', $broken );
		wp_delete_file( $blocker );

		$this->assertWPError( $result );
		$this->assertSame( 'mkdir_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

}
