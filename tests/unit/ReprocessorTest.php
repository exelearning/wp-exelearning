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
}
