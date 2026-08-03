<?php
/**
 * Tests for the upload-backed halves of ExeLearning_REST_API: /create and the
 * transactional /save flow.
 *
 * Both endpoints hand the raw $_FILES entry to wp_handle_upload(), which refuses
 * anything that did not arrive through a real multipart POST — a condition no
 * CLI test can satisfy. WordPress' documented escape hatch for that is
 * $overrides['upload_error_handler']: _wp_handle_upload() delegates to it and
 * returns whatever it produces. These tests install a handler that puts the file
 * where WordPress would have put it, so the endpoints receive the same success
 * array a real upload yields and every plugin-side step after it runs for real.
 *
 * @package Exelearning
 */

/**
 * Class RestApiUploadTest.
 *
 * @covers ExeLearning_REST_API
 */
class RestApiUploadTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_REST_API
	 */
	private $rest_api;

	/**
	 * Files and directories to remove on tear down.
	 *
	 * @var string[]
	 */
	private $cleanup_paths = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->rest_api      = new ExeLearning_REST_API();
		$this->cleanup_paths = array();

		add_filter( 'wp_handle_upload_overrides', array( $this, 'install_upload_completer' ) );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		remove_filter( 'wp_handle_upload_overrides', array( $this, 'install_upload_completer' ) );
		unset( $_FILES['file'] );

		foreach ( $this->cleanup_paths as $path ) {
			$this->recursive_delete( $path );
		}

		parent::tear_down();
	}

	/**
	 * Point wp_handle_upload() at the test's own completion handler.
	 *
	 * @param array $overrides wp_handle_upload() overrides.
	 * @return array
	 */
	public function install_upload_completer( $overrides ) {
		$overrides['upload_error_handler'] = array( $this, 'place_uploaded_file' );
		return $overrides;
	}

	/**
	 * Finish an upload that is_uploaded_file() rejected under the CLI runner.
	 *
	 * Stores the file in the uploads directory under a unique name and returns
	 * the same shape wp_handle_upload() returns on success.
	 *
	 * @param array  $file    Uploaded file entry, passed by reference by WordPress.
	 * @param string $message Error message WordPress was about to return.
	 * @return array Upload result.
	 */
	public function place_uploaded_file( &$file, $message ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array( 'error' => $message );
		}

		$name     = wp_unique_filename( $uploads['path'], $file['name'] );
		$new_file = trailingslashit( $uploads['path'] ) . $name;
		if ( ! copy( $file['tmp_name'], $new_file ) ) { // phpcs:ignore
			return array( 'error' => $message );
		}
		$this->cleanup_paths[] = $new_file;

		return array(
			'file' => $new_file,
			'url'  => trailingslashit( $uploads['url'] ) . $name,
			'type' => $file['type'],
		);
	}

	// ------------------------------------------------------------------
	// /create
	// ------------------------------------------------------------------

	/**
	 * A valid upload creates an attachment, extracts it and reports the URLs
	 * the editor needs to continue working on the new project.
	 */
	public function test_create_stores_extracts_and_describes_the_new_project() {
		$this->stage_upload( 'My Project.elpx', $this->build_elpx( 'Created project' ) );

		$response = $this->rest_api->create_elp_file( new WP_REST_Request( 'POST', '/exelearning/v1/create' ) );

		$this->assertNotWPError( $response );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$attachment_id = $data['attachmentId'];
		$this->assertSame( 'attachment', get_post( $attachment_id )->post_type );
		$this->assertSame( 'My-Project', get_post( $attachment_id )->post_title, 'The title comes from the sanitized filename.' );
		$this->assertSame( 'elpx', pathinfo( get_attached_file( $attachment_id ), PATHINFO_EXTENSION ) );

		$hash = get_post_meta( $attachment_id, '_exelearning_extracted', true );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $hash );
		$this->assertDirectoryExists( $this->extraction_dir( $hash ) );
		$this->assertSame( 'Created project', get_post_meta( $attachment_id, '_exelearning_title', true ) );

		$this->assertStringContainsString( 'page=exelearning-editor', $data['editUrl'] );
		$this->assertStringContainsString( 'attachment_id=' . $attachment_id, $data['editUrl'] );
		$this->assertStringContainsString( '.elpx', $data['url'] );

		$this->cleanup_paths[] = get_attached_file( $attachment_id );
		$this->cleanup_paths[] = $this->extraction_dir( $hash );
	}

	/**
	 * An upload whose name carries a foreign extension is normalized to .elpx
	 * rather than stored under a type the plugin cannot edit.
	 */
	public function test_create_normalizes_the_uploaded_extension_to_elpx() {
		$this->stage_upload( 'project.zip', $this->build_elpx() );

		$response = $this->rest_api->create_elp_file( new WP_REST_Request( 'POST', '/exelearning/v1/create' ) );

		$this->assertNotWPError( $response );
		$attachment_id = $response->get_data()['attachmentId'];
		$path          = get_attached_file( $attachment_id );

		$this->assertStringEndsWith( '.elpx', $path );
		$this->assertSame( 'project', get_post( $attachment_id )->post_title );

		$this->cleanup_paths[] = $path;
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $attachment_id, '_exelearning_extracted', true ) );
	}

	/**
	 * When the uploaded archive is not an eXeLearning project the endpoint
	 * reports the failure and leaves no attachment behind.
	 */
	public function test_create_rolls_back_the_attachment_when_processing_fails() {
		$before = $this->attachment_ids();
		$this->stage_upload( 'broken.elpx', 'this is not a zip archive at all' );

		$result = $this->rest_api->create_elp_file( new WP_REST_Request( 'POST', '/exelearning/v1/create' ) );

		$this->assertWPError( $result );
		$this->assertSame( $before, $this->attachment_ids(), 'A failed create must not leave an orphan attachment.' );
	}

	/**
	 * Uploading requires the upload_files capability.
	 */
	public function test_create_permission_callback_requires_upload_files() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->rest_api->check_upload_permission() );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'author' ) ) );
		$this->assertTrue( $this->rest_api->check_upload_permission() );
	}

	// ------------------------------------------------------------------
	// /save
	// ------------------------------------------------------------------

	/**
	 * A successful save replaces the file on disk, points the metadata at a
	 * fresh extraction, retires the previous one and announces the commit.
	 */
	public function test_save_replaces_the_file_and_commits_a_new_extraction() {
		$existing = $this->make_existing_elpx_attachment( 'Before' );
		$old_hash = get_post_meta( $existing['id'], '_exelearning_extracted', true );

		$new_bytes = $this->build_elpx( 'After' );
		$this->stage_upload( 'whatever.elpx', $new_bytes );

		$fired = array();
		add_action(
			'exelearning_after_elpx_save',
			static function ( $id, $new_hash, $previous ) use ( &$fired ) {
				$fired[] = compact( 'id', 'new_hash', 'previous' );
			},
			10,
			3
		);

		$response = $this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( $existing['id'], $data['attachment_id'] );

		$new_hash = get_post_meta( $existing['id'], '_exelearning_extracted', true );
		$this->assertNotSame( $old_hash, $new_hash );
		$this->assertSame( $new_bytes, file_get_contents( $existing['path'] ), 'The original .elpx must hold the new bytes.' );
		$this->assertSame( 'After', get_post_meta( $existing['id'], '_exelearning_title', true ) );
		$this->assertFileExists( $this->extraction_dir( $new_hash ) . '/index.html' );
		$this->assertSame(
			ExeLearning_Content_Proxy::get_proxy_url( $new_hash ),
			$data['preview_url'],
			'The response must point at the freshly committed extraction.'
		);

		$this->assertCount( 1, $fired );
		$this->assertSame(
			array(
				'id'       => $existing['id'],
				'new_hash' => $new_hash,
				'previous' => $old_hash,
			),
			$fired[0]
		);

		$this->cleanup_paths[] = $this->extraction_dir( $new_hash );
	}

	/**
	 * The superseded extraction stays reachable: its hash is recorded as an
	 * alias of the attachment so already-published URLs can be redirected.
	 */
	public function test_save_retires_the_previous_extraction_as_an_alias() {
		$existing = $this->make_existing_elpx_attachment();
		$old_hash = get_post_meta( $existing['id'], '_exelearning_extracted', true );

		$this->stage_upload( 'update.elpx', $this->build_elpx( 'Updated' ) );
		$this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) );

		$aliases = new ExeLearning_Content_Hash_Aliases();
		$this->assertSame( $existing['id'], $aliases->resolve( $old_hash ) );

		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $existing['id'], '_exelearning_extracted', true ) );
	}

	/**
	 * `exelearning_before_elpx_save` runs with the attachment and the path of
	 * the file that is about to be replaced.
	 */
	public function test_save_announces_the_replacement_before_writing() {
		$existing = $this->make_existing_elpx_attachment();
		$this->stage_upload( 'update.elpx', $this->build_elpx() );

		$seen = array();
		add_action(
			'exelearning_before_elpx_save',
			static function ( $id, $path ) use ( &$seen ) {
				$seen[] = array( $id, $path, file_get_contents( $path ) );
			},
			10,
			2
		);

		$this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) );

		$this->assertCount( 1, $seen );
		$this->assertSame( $existing['id'], $seen[0][0] );
		$this->assertSame( $existing['path'], $seen[0][1] );
		$this->assertSame( $existing['bytes'], $seen[0][2], 'The hook must observe the file before it is overwritten.' );

		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $existing['id'], '_exelearning_extracted', true ) );
	}

	/**
	 * A save whose payload cannot be validated leaves the stored file, the
	 * metadata and the live extraction exactly as they were.
	 */
	public function test_save_leaves_everything_untouched_when_the_new_content_is_invalid() {
		$existing = $this->make_existing_elpx_attachment( 'Original' );
		$old_hash = get_post_meta( $existing['id'], '_exelearning_extracted', true );

		$this->stage_upload( 'broken.elpx', 'not a zip' );

		$result = $this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) );

		$this->assertWPError( $result );
		$this->assertSame( $existing['bytes'], file_get_contents( $existing['path'] ) );
		$this->assertSame( $old_hash, get_post_meta( $existing['id'], '_exelearning_extracted', true ) );
		$this->assertSame( 'Original', get_post_meta( $existing['id'], '_exelearning_title', true ) );
		$this->assertDirectoryExists( $this->extraction_dir( $old_hash ) );
	}

	/**
	 * A copy that does not reproduce the announced size is treated as a failed
	 * save, and the extraction staged for it is cleaned up.
	 */
	public function test_save_rejects_a_truncated_copy_and_cleans_up_its_extraction() {
		$existing = $this->make_existing_elpx_attachment();
		$old_hash = get_post_meta( $existing['id'], '_exelearning_extracted', true );

		$this->stage_upload( 'update.elpx', $this->build_elpx() );
		// Announce a size the copied file will never have.
		$_FILES['file']['size'] = 999999;

		$staged = array();
		add_action(
			'exelearning_after_elpx_extract',
			static function ( $file, $destination ) use ( &$staged ) {
				$staged[] = $destination;
			},
			10,
			2
		);

		$result = $this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'copy_truncated', $result->get_error_code() );
		$this->assertSame( $old_hash, get_post_meta( $existing['id'], '_exelearning_extracted', true ) );
		$this->assertNotEmpty( $staged );
		$this->assertDirectoryDoesNotExist( $staged[0] );
	}

	/**
	 * A second save for the same attachment is refused while the first still
	 * holds the lock, so two POSTs cannot race on the same file.
	 */
	public function test_concurrent_saves_on_the_same_attachment_are_refused() {
		$existing = $this->make_existing_elpx_attachment();

		$acquire = new ReflectionMethod( ExeLearning_REST_API::class, 'acquire_save_lock' );
		$acquire->setAccessible( true );
		$this->assertTrue( $acquire->invoke( $this->rest_api, $existing['id'] ) );

		$this->stage_upload( 'update.elpx', $this->build_elpx() );
		$result = $this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) );

		$this->assertWPError( $result );
		$this->assertSame( 'save_in_progress', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		$release = new ReflectionMethod( ExeLearning_REST_API::class, 'release_save_lock' );
		$release->setAccessible( true );
		$release->invoke( $this->rest_api, $existing['id'] );
	}

	/**
	 * The lock releases once the save finishes, so the next save is accepted.
	 */
	public function test_the_save_lock_is_released_after_a_successful_save() {
		$existing = $this->make_existing_elpx_attachment();

		$this->stage_upload( 'first.elpx', $this->build_elpx( 'First' ) );
		$this->assertNotWPError( $this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) ) );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $existing['id'], '_exelearning_extracted', true ) );

		$this->stage_upload( 'second.elpx', $this->build_elpx( 'Second' ) );
		$this->assertNotWPError( $this->rest_api->save_elp_file( $this->save_request( $existing['id'] ) ) );

		$this->assertSame( 'Second', get_post_meta( $existing['id'], '_exelearning_title', true ) );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $existing['id'], '_exelearning_extracted', true ) );
	}

	/**
	 * With a persistent object cache the lock uses the atomic wp_cache_add()
	 * path instead of a transient, with the same acquire/release semantics.
	 */
	public function test_the_save_lock_uses_the_object_cache_when_one_is_persistent() {
		$acquire = new ReflectionMethod( ExeLearning_REST_API::class, 'acquire_save_lock' );
		$acquire->setAccessible( true );
		$release = new ReflectionMethod( ExeLearning_REST_API::class, 'release_save_lock' );
		$release->setAccessible( true );

		wp_using_ext_object_cache( true );
		try {
			$this->assertTrue( $acquire->invoke( $this->rest_api, 4242 ) );
			$this->assertFalse( $acquire->invoke( $this->rest_api, 4242 ), 'The lock must not be re-entrant.' );
			$this->assertFalse( get_transient( 'exe_save_lock_4242' ), 'No transient is written on the object-cache path.' );

			$release->invoke( $this->rest_api, 4242 );
			$this->assertTrue( $acquire->invoke( $this->rest_api, 4242 ) );
			$release->invoke( $this->rest_api, 4242 );
		} finally {
			wp_using_ext_object_cache( false );
		}
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Build a minimal but valid .elpx archive.
	 *
	 * @param string $title Project title stored in content.xml.
	 * @return string Raw archive bytes.
	 */
	private function build_elpx( $title = 'Test project' ) {
		$path = wp_tempnam( 'fixture.elpx' );
		wp_delete_file( $path );

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addFromString(
			'content.xml',
			'<?xml version="1.0" encoding="UTF-8"?><package><odeProperties>'
			. '<odeProperty><key>pp_title</key><value>' . $title . '</value></odeProperty>'
			. '<odeProperty><key>pp_description</key><value>' . $title . ' description</value></odeProperty>'
			. '</odeProperties></package>'
		);
		$zip->addFromString( 'index.html', '<html><body>' . $title . '</body></html>' );
		$zip->close();

		$bytes = file_get_contents( $path ); // phpcs:ignore
		wp_delete_file( $path );

		return $bytes;
	}

	/**
	 * Put raw bytes into $_FILES as if they had just been uploaded.
	 *
	 * @param string $name  Client-supplied filename.
	 * @param string $bytes File contents.
	 */
	private function stage_upload( $name, $bytes ) {
		$tmp = wp_tempnam( 'upload' );
		file_put_contents( $tmp, $bytes ); // phpcs:ignore
		$this->cleanup_paths[] = $tmp;

		$_FILES['file'] = array(
			'name'     => $name,
			'type'     => 'application/zip',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * Create an attachment backed by an already-extracted .elpx file.
	 *
	 * @param string $title Project title stored in the archive.
	 * @return array { id: int, path: string, bytes: string }
	 */
	private function make_existing_elpx_attachment( $title = 'Existing' ) {
		$bytes = $this->build_elpx( $title );

		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => get_current_user_id(),
			)
		);

		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'existing-' . $attachment_id . '.elpx';
		file_put_contents( $path, $bytes ); // phpcs:ignore
		update_attached_file( $attachment_id, $path );
		$this->cleanup_paths[] = $path;

		$reprocessor = new ExeLearning_Reprocessor();
		$this->assertNotWPError( $reprocessor->reprocess( $attachment_id ) );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $attachment_id, '_exelearning_extracted', true ) );

		return array(
			'id'    => $attachment_id,
			'path'  => $path,
			'bytes' => $bytes,
		);
	}

	/**
	 * Every attachment currently in the library.
	 *
	 * @return int[] Attachment IDs.
	 */
	private function attachment_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
	}

	/**
	 * Build a save request for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return WP_REST_Request
	 */
	private function save_request( $attachment_id ) {
		$request = new WP_REST_Request( 'POST', '/exelearning/v1/save/' . $attachment_id );
		$request->set_param( 'id', $attachment_id );
		return $request;
	}

	/**
	 * Absolute path of an extraction directory.
	 *
	 * @param string $hash Extraction hash.
	 * @return string
	 */
	private function extraction_dir( $hash ) {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash;
	}

	/**
	 * Recursively remove a file or directory tree.
	 *
	 * @param string $path Path to remove.
	 */
	private function recursive_delete( $path ) {
		if ( ! file_exists( $path ) ) {
			return;
		}
		if ( ! is_dir( $path ) ) {
			wp_delete_file( $path );
			return;
		}
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$this->recursive_delete( $path . '/' . $entry );
		}
		rmdir( $path ); // phpcs:ignore
	}

	/**
	 * If the new bytes cannot be written over the original file the save fails
	 * and the extraction staged for it is removed, so a failed save leaves no
	 * orphaned directory behind.
	 */
	public function test_save_cleans_up_when_the_original_file_cannot_be_written() {
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => get_current_user_id(),
			)
		);

		// A directory passes the "exists and is .elpx" checks but can never be
		// overwritten by a file copy.
		$upload_dir = wp_upload_dir();
		$target     = trailingslashit( $upload_dir['basedir'] ) . 'unwritable-' . $attachment_id . '.elpx';
		wp_mkdir_p( $target );
		update_attached_file( $attachment_id, $target );
		$this->cleanup_paths[] = $target;

		$this->stage_upload( 'update.elpx', $this->build_elpx() );

		$staged = array();
		add_action(
			'exelearning_after_elpx_extract',
			static function ( $file, $destination ) use ( &$staged ) {
				$staged[] = $destination;
			},
			10,
			2
		);

		$result = $this->rest_api->save_elp_file( $this->save_request( $attachment_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'move_failed', $result->get_error_code() );
		$this->assertNotEmpty( $staged );
		$this->assertDirectoryDoesNotExist( $staged[0] );
		$this->assertSame( '', get_post_meta( $attachment_id, '_exelearning_extracted', true ) );
	}

}
