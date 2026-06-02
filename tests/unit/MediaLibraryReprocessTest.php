<?php
/**
 * Tests for the Media Library "Reprocess eXeLearning file" bulk action.
 *
 * @package Exelearning
 */

/**
 * Class MediaLibraryReprocessTest.
 *
 * @covers ExeLearning_Media_Library
 */
class MediaLibraryReprocessTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Media_Library
	 */
	private $media_library;

	/**
	 * Paths to clean up.
	 *
	 * @var string[]
	 */
	private $cleanup_paths = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->media_library = new ExeLearning_Media_Library();
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
	 * Create an attachment backed by a valid previewable .elpx on disk.
	 *
	 * @return int Attachment ID.
	 */
	private function make_elpx_attachment() {
		$user_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => $user_id,
			)
		);
		wp_set_current_user( $user_id );

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/bulk-' . $attachment_id . '.elpx';

		$zip = new ZipArchive();
		$zip->open( $file_path, ZipArchive::CREATE );
		$zip->addFromString( 'content.xml', '<package></package>' );
		$zip->addFromString( 'index.html', '<html></html>' );
		$zip->close();

		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		return $attachment_id;
	}

	/**
	 * Create an attachment backed by a ZIP file with an arbitrary extension.
	 *
	 * @param string $ext              File extension (e.g. 'zip').
	 * @param bool   $with_content_xml Whether the archive is a real eXeLearning project.
	 * @return int Attachment ID.
	 */
	private function make_zip_attachment( $ext, $with_content_xml ) {
		$user_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => $user_id,
			)
		);
		wp_set_current_user( $user_id );

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/bulk-' . $attachment_id . '.' . $ext;

		$zip = new ZipArchive();
		$zip->open( $file_path, ZipArchive::CREATE );
		if ( $with_content_xml ) {
			$zip->addFromString( 'content.xml', '<package></package>' );
			$zip->addFromString( 'index.html', '<html></html>' );
		} else {
			$zip->addFromString( 'readme.txt', 'just a backup archive' );
		}
		$zip->close();

		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		return $attachment_id;
	}

	/**
	 * The reprocess bulk action is added to the media list table.
	 */
	public function test_bulk_action_is_registered() {
		$actions = $this->media_library->register_bulk_reprocess_action( array() );

		$this->assertArrayHasKey( 'exelearning_reprocess', $actions );
	}

	/**
	 * The handler ignores actions other than ours, returning the URL untouched.
	 */
	public function test_handle_bulk_ignores_other_actions() {
		$url    = 'http://example.org/wp-admin/upload.php';
		$result = $this->media_library->handle_bulk_reprocess( $url, 'trash', array( 1, 2 ) );

		$this->assertSame( $url, $result );
	}

	/**
	 * The handler reprocesses selected .elpx attachments and reports the count.
	 */
	public function test_handle_bulk_reprocesses_selected_elpx() {
		$id = $this->make_elpx_attachment();

		$this->assertEmpty( get_post_meta( $id, '_exelearning_extracted', true ) );

		$redirect = $this->media_library->handle_bulk_reprocess(
			'http://example.org/wp-admin/upload.php',
			'exelearning_reprocess',
			array( $id )
		);

		// Attachment is now extracted and previewable.
		$hash = get_post_meta( $id, '_exelearning_extracted', true );
		$this->assertNotEmpty( $hash );
		$this->assertEquals( '1', get_post_meta( $id, '_exelearning_has_preview', true ) );

		$upload_dir            = wp_upload_dir();
		$this->cleanup_paths[] = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';

		// Redirect carries a processed count for the admin notice.
		$query = wp_parse_url( $redirect, PHP_URL_QUERY );
		parse_str( (string) $query, $args );
		$this->assertEquals( 1, (int) $args['exe_reprocessed'] );
	}

	/**
	 * Non-elpx selections are skipped, not errored.
	 */
	public function test_handle_bulk_skips_non_elpx() {
		$image      = $this->factory->attachment->create();
		$upload_dir = wp_upload_dir();
		$img_path   = $upload_dir['basedir'] . '/skip-' . $image . '.jpg';
		file_put_contents( $img_path, 'x' ); // phpcs:ignore
		$this->cleanup_paths[] = $img_path;
		update_attached_file( $image, $img_path );

		$redirect = $this->media_library->handle_bulk_reprocess(
			'http://example.org/wp-admin/upload.php',
			'exelearning_reprocess',
			array( $image )
		);

		$query = wp_parse_url( $redirect, PHP_URL_QUERY );
		parse_str( (string) $query, $args );

		$this->assertEquals( 0, (int) $args['exe_reprocessed'] );
		$this->assertEquals( 1, (int) $args['exe_skipped'] );
	}

	/**
	 * A .zip whose contents are a valid eXeLearning project is reprocessed.
	 */
	public function test_handle_bulk_reprocesses_valid_zip() {
		$id = $this->make_zip_attachment( 'zip', true );

		$redirect = $this->media_library->handle_bulk_reprocess(
			'http://example.org/wp-admin/upload.php',
			'exelearning_reprocess',
			array( $id )
		);

		$hash = get_post_meta( $id, '_exelearning_extracted', true );
		$this->assertNotEmpty( $hash );

		$upload_dir            = wp_upload_dir();
		$this->cleanup_paths[] = get_attached_file( $id ); // Renamed to .elpx by reprocessing.
		$this->cleanup_paths[] = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';

		$query = wp_parse_url( $redirect, PHP_URL_QUERY );
		parse_str( (string) $query, $args );
		$this->assertEquals( 1, (int) $args['exe_reprocessed'] );
	}

	/**
	 * A plain backup .zip (no content.xml) is skipped, not failed.
	 */
	public function test_handle_bulk_skips_plain_zip() {
		$id = $this->make_zip_attachment( 'zip', false );

		$redirect = $this->media_library->handle_bulk_reprocess(
			'http://example.org/wp-admin/upload.php',
			'exelearning_reprocess',
			array( $id )
		);

		$this->assertEmpty( get_post_meta( $id, '_exelearning_extracted', true ) );

		$query = wp_parse_url( $redirect, PHP_URL_QUERY );
		parse_str( (string) $query, $args );
		$this->assertEquals( 0, (int) $args['exe_reprocessed'] );
		$this->assertEquals( 1, (int) $args['exe_skipped'] );
		$this->assertEquals( 0, (int) $args['exe_failed'] );
	}

	/**
	 * The admin notice reports reprocessed/skipped counts as a success notice.
	 */
	public function test_admin_notice_renders_success_counts() {
		$_REQUEST['exe_reprocessed'] = '2';
		$_REQUEST['exe_skipped']     = '1';
		$_REQUEST['exe_failed']      = '0';

		ob_start();
		$this->media_library->render_reprocess_admin_notice();
		$output = ob_get_clean();

		unset( $_REQUEST['exe_reprocessed'], $_REQUEST['exe_skipped'], $_REQUEST['exe_failed'] );

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( '2 eXeLearning files reprocessed.', $output );
		$this->assertStringContainsString( 'skipped', $output );
	}

	/**
	 * Failures turn the notice into a warning.
	 */
	public function test_admin_notice_warns_on_failures() {
		$_REQUEST['exe_reprocessed'] = '0';
		$_REQUEST['exe_skipped']     = '0';
		$_REQUEST['exe_failed']      = '1';

		ob_start();
		$this->media_library->render_reprocess_admin_notice();
		$output = ob_get_clean();

		unset( $_REQUEST['exe_reprocessed'], $_REQUEST['exe_skipped'], $_REQUEST['exe_failed'] );

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'could not be reprocessed', $output );
	}

	/**
	 * The notice stays silent when our query args are absent.
	 */
	public function test_admin_notice_silent_without_params() {
		unset( $_REQUEST['exe_reprocessed'], $_REQUEST['exe_skipped'], $_REQUEST['exe_failed'] );

		ob_start();
		$this->media_library->render_reprocess_admin_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * The notice stays silent when every count is zero (nothing to report).
	 */
	public function test_admin_notice_silent_when_all_zero() {
		$_REQUEST['exe_reprocessed'] = '0';
		$_REQUEST['exe_skipped']     = '0';
		$_REQUEST['exe_failed']      = '0';

		ob_start();
		$this->media_library->render_reprocess_admin_notice();
		$output = ob_get_clean();

		unset( $_REQUEST['exe_reprocessed'], $_REQUEST['exe_skipped'], $_REQUEST['exe_failed'] );

		$this->assertEmpty( $output );
	}
}
