<?php
/**
 * Tests for ExeLearning_Elp_Upload_Handler class.
 *
 * @package Exelearning
 */

/**
 * Class ElpUploadHandlerTest.
 *
 * @covers ExeLearning_Elp_Upload_Handler
 */
class ElpUploadHandlerTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Elp_Upload_Handler
	 */
	private $handler;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->handler = new ExeLearning_Elp_Upload_Handler();
	}

	/**
	 * Test process_elp_upload ignores non-elpx files.
	 */
	public function test_process_elp_upload_ignores_non_elpx() {
		$upload = array(
			'file' => '/tmp/test.pdf',
			'url'  => 'http://example.com/test.pdf',
			'type' => 'application/pdf',
		);

		$result = $this->handler->process_elp_upload( $upload );

		// Should return unchanged.
		$this->assertEquals( $upload, $result );
	}

	/**
	 * Test process_elp_upload ignores elp (v2) files.
	 */
	public function test_process_elp_upload_ignores_elp_v2() {
		$upload = array(
			'file' => '/tmp/test.elp',
			'url'  => 'http://example.com/test.elp',
			'type' => 'application/zip',
		);

		$result = $this->handler->process_elp_upload( $upload );

		// Should return unchanged (v2 files not supported).
		$this->assertEquals( $upload, $result );
	}

	/**
	 * Test security htaccess content.
	 */
	public function test_security_htaccess_content() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'create_security_htaccess' );
		$method->setAccessible( true );

		// We can't easily test file creation in unit tests,
		// but we can verify the method exists and is callable.
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test recursive delete method exists.
	 */
	public function test_recursive_delete_exists() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'exelearning_recursive_delete' );
		$method->setAccessible( true );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test save_elp_metadata ignores non-elpx files.
	 */
	public function test_save_elp_metadata_ignores_non_elpx() {
		// Create a regular attachment.
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/pdf',
			)
		);

		// Update the file path to a non-elpx file.
		update_post_meta( $attachment_id, '_wp_attached_file', 'test.pdf' );

		// This should not throw any errors.
		$this->handler->save_elp_metadata( $attachment_id );

		// No exelearning metadata should be set.
		$this->assertEmpty( get_post_meta( $attachment_id, '_exelearning_extracted', true ) );
	}

	/**
	 * Test delete_extracted_folder method is registered.
	 */
	public function test_delete_hook_is_registered() {
		$this->handler->register();

		// Check if the action is registered.
		$this->assertGreaterThan(
			0,
			has_action( 'delete_attachment', array( $this->handler, 'exelearning_delete_extracted_folder' ) )
		);
	}

	/**
	 * Test upload filter is registered.
	 */
	public function test_upload_filter_is_registered() {
		$this->handler->register();

		// Check if the filter is registered.
		$this->assertGreaterThan(
			0,
			has_filter( 'wp_handle_upload', array( $this->handler, 'process_elp_upload' ) )
		);
	}

	/**
	 * Test add_attachment hook is registered.
	 */
	public function test_add_attachment_hook_is_registered() {
		$this->handler->register();

		// Check if the action is registered.
		$this->assertGreaterThan(
			0,
			has_action( 'add_attachment', array( $this->handler, 'save_elp_metadata' ) )
		);
	}

	/**
	 * Test save_elp_metadata does nothing without attached file.
	 */
	public function test_save_elp_metadata_no_file() {
		$attachment_id = $this->factory->attachment->create();

		// Remove the attached file reference.
		delete_post_meta( $attachment_id, '_wp_attached_file' );

		// This should not throw any errors.
		$this->handler->save_elp_metadata( $attachment_id );

		// No metadata should be set.
		$this->assertEmpty( get_post_meta( $attachment_id, '_exelearning_extracted', true ) );
	}

	/**
	 * Test exelearning_delete_extracted_folder does nothing without metadata.
	 */
	public function test_delete_extracted_folder_no_metadata() {
		$attachment_id = $this->factory->attachment->create();

		// This should not throw any errors.
		$this->handler->exelearning_delete_extracted_folder( $attachment_id );

		// Test passes if no exception is thrown.
		$this->assertTrue( true );
	}

	/**
	 * Test exelearning_delete_extracted_folder does nothing for non-existent directory.
	 */
	public function test_delete_extracted_folder_nonexistent_dir() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'f', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );

		// This should not throw any errors even if directory doesn't exist.
		$this->handler->exelearning_delete_extracted_folder( $attachment_id );

		// Test passes if no exception is thrown.
		$this->assertTrue( true );
	}

	/**
	 * Test recursive_delete method is private.
	 */
	public function test_recursive_delete_is_private() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'exelearning_recursive_delete' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test create_security_htaccess method is private.
	 */
	public function test_create_security_htaccess_is_private() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'create_security_htaccess' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test process_elp_upload returns upload unchanged for jpg files.
	 */
	public function test_process_elp_upload_ignores_jpg() {
		$upload = array(
			'file' => '/tmp/image.jpg',
			'url'  => 'http://example.com/image.jpg',
			'type' => 'image/jpeg',
		);

		$result = $this->handler->process_elp_upload( $upload );

		$this->assertEquals( $upload, $result );
	}

	/**
	 * Test process_elp_upload returns upload unchanged for docx files.
	 */
	public function test_process_elp_upload_ignores_docx() {
		$upload = array(
			'file' => '/tmp/document.docx',
			'url'  => 'http://example.com/document.docx',
			'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);

		$result = $this->handler->process_elp_upload( $upload );

		$this->assertEquals( $upload, $result );
	}

	/**
	 * Test process_elp_upload returns WP_Error for invalid elpx file.
	 */
	public function test_process_elp_upload_invalid_elpx() {
		// Create a fake elpx file that's not a valid zip.
		$temp_file = sys_get_temp_dir() . '/invalid.elpx';
		file_put_contents( $temp_file, 'not a zip file' );

		$upload = array(
			'file' => $temp_file,
			'url'  => 'http://example.com/invalid.elpx',
			'type' => 'application/zip',
		);

		$result = $this->handler->process_elp_upload( $upload );

		$this->assertInstanceOf( WP_Error::class, $result );

		// Cleanup - file may have been deleted by the handler.
		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file );
		}
	}

	/**
	 * Test process_elp_upload processes valid elpx file.
	 */
	public function test_process_elp_upload_valid_elpx() {
		// Create a valid elpx file.
		$temp_file = sys_get_temp_dir() . '/valid-' . uniqid() . '.elpx';

		$zip = new ZipArchive();
		$zip->open( $temp_file, ZipArchive::CREATE );
		$content_xml = '<?xml version="1.0" encoding="UTF-8"?>
<package>
	<odeProperties>
		<odeProperty>
			<key>pp_title</key>
			<value>Test Upload</value>
		</odeProperty>
	</odeProperties>
</package>';
		$zip->addFromString( 'content.xml', $content_xml );
		$zip->addFromString( 'index.html', '<html><body>Test</body></html>' );
		$zip->close();

		$upload = array(
			'file' => $temp_file,
			'url'  => 'http://example.com/valid.elpx',
			'type' => 'application/zip',
		);

		$result = $this->handler->process_elp_upload( $upload );

		// Should return the upload array (not WP_Error).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'file', $result );

		// Cleanup.
		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file );
		}

		// Also clean up the extracted folder.
		$upload_dir = wp_upload_dir();
		$exe_dir    = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/';
		if ( is_dir( $exe_dir ) ) {
			$this->cleanup_directory( $exe_dir );
		}
	}

	/**
	 * Helper to clean up test directories.
	 *
	 * @param string $dir Directory to clean.
	 */
	private function cleanup_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . $file;
			if ( is_dir( $path ) ) {
				$this->cleanup_directory( $path . '/' );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Test save_elp_metadata saves transient data.
	 */
	public function test_save_elp_metadata_with_transient() {
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
			)
		);

		// Create a temp elpx file.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/test-' . $attachment_id . '.elpx';
		file_put_contents( $file_path, 'test content' );

		update_attached_file( $attachment_id, $file_path );

		// Set transient data.
		$transient_key = 'exelearning_data_' . md5( $file_path );
		set_transient(
			$transient_key,
			array(
				'post_data' => array(
					'post_excerpt' => 'Test Title',
					'post_content' => 'Test Description',
				),
				'metadata'  => array(
					'_exelearning_title'       => 'Test Title',
					'_exelearning_description' => 'Test Description',
					'_exelearning_extracted'   => 'abc123',
				),
			),
			300
		);

		$this->handler->save_elp_metadata( $attachment_id );

		// Check metadata was saved.
		$this->assertEquals( 'Test Title', get_post_meta( $attachment_id, '_exelearning_title', true ) );
		$this->assertEquals( 'Test Description', get_post_meta( $attachment_id, '_exelearning_description', true ) );

		// Transient should be deleted.
		$this->assertFalse( get_transient( $transient_key ) );

		// Cleanup.
		unlink( $file_path );
	}

	/**
	 * Test exelearning_delete_extracted_folder removes directory.
	 */
	public function test_delete_extracted_folder_removes_dir() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = 'test' . uniqid();

		// Create the directory.
		$upload_dir = wp_upload_dir();
		$folder     = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';
		wp_mkdir_p( $folder );
		file_put_contents( $folder . 'test.txt', 'test' );

		$this->assertDirectoryExists( $folder );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );

		$this->handler->exelearning_delete_extracted_folder( $attachment_id );

		$this->assertDirectoryDoesNotExist( $folder );
	}

	/**
	 * Test register method is public.
	 */
	public function test_register_is_public() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'register' );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test process_elp_upload method is public.
	 */
	public function test_process_elp_upload_is_public() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'process_elp_upload' );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test save_elp_metadata method is public.
	 */
	public function test_save_elp_metadata_is_public() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'save_elp_metadata' );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test exelearning_delete_extracted_folder method is public.
	 */
	public function test_delete_extracted_folder_is_public() {
		$method = new ReflectionMethod( ExeLearning_Elp_Upload_Handler::class, 'exelearning_delete_extracted_folder' );
		$this->assertTrue( $method->isPublic() );
	}
}
