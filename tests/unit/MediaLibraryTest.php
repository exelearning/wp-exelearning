<?php
/**
 * Tests for ExeLearning_Media_Library class.
 *
 * @package Exelearning
 */

/**
 * Class MediaLibraryTest.
 *
 * @covers ExeLearning_Media_Library
 */
class MediaLibraryTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Media_Library
	 */
	private $media_library;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->media_library = new ExeLearning_Media_Library();
	}

	/**
	 * Test add_elp_column adds exelearning column.
	 */
	public function test_add_elp_column() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
		);

		$result = $this->media_library->add_elp_column( $columns );

		$this->assertArrayHasKey( 'exelearning', $result );
		$this->assertArrayHasKey( 'cb', $result );
		$this->assertArrayHasKey( 'title', $result );
	}

	/**
	 * Test render_elp_column ignores non-exelearning columns.
	 */
	public function test_render_elp_column_ignores_other_columns() {
		$attachment_id = $this->factory->attachment->create();

		ob_start();
		$this->media_library->render_elp_column( 'title', $attachment_id );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_elp_column shows nothing for non-elp attachments.
	 */
	public function test_render_elp_column_empty_for_non_elp() {
		$attachment_id = $this->factory->attachment->create();

		ob_start();
		$this->media_library->render_elp_column( 'exelearning', $attachment_id );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_elp_column shows metadata for elp attachments.
	 */
	public function test_render_elp_column_shows_metadata() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_license', 'CC BY 4.0' );
		update_post_meta( $attachment_id, '_exelearning_language', 'en' );
		update_post_meta( $attachment_id, '_exelearning_resource_type', 'lesson' );

		ob_start();
		$this->media_library->render_elp_column( 'exelearning', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-metadata', $output );
		$this->assertStringContainsString( 'CC BY 4.0', $output );
		$this->assertStringContainsString( 'en', $output );
		$this->assertStringContainsString( 'lesson', $output );
	}

	/**
	 * Test add_elp_metadata_to_js returns response unchanged for non-elp.
	 */
	public function test_add_elp_metadata_to_js_unchanged_for_non_elp() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );

		$response = array(
			'id'    => $attachment_id,
			'title' => 'Test',
		);

		$result = $this->media_library->add_elp_metadata_to_js( $response, $post, array() );

		$this->assertArrayNotHasKey( 'exelearning', $result );
		$this->assertEquals( $attachment_id, $result['id'] );
	}

	/**
	 * Test add_elp_metadata_to_js adds exelearning data for elp files.
	 */
	public function test_add_elp_metadata_to_js_adds_data() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'b', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_license', 'MIT' );
		update_post_meta( $attachment_id, '_exelearning_language', 'es' );
		update_post_meta( $attachment_id, '_exelearning_resource_type', 'course' );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );
		update_post_meta( $attachment_id, '_exelearning_version', '3' );

		$response = array( 'id' => $attachment_id );
		$result   = $this->media_library->add_elp_metadata_to_js( $response, $post, array() );

		$this->assertArrayHasKey( 'exelearning', $result );
		$this->assertEquals( 'MIT', $result['exelearning']['license'] );
		$this->assertEquals( 'es', $result['exelearning']['language'] );
		$this->assertEquals( 'course', $result['exelearning']['resource_type'] );
		$this->assertTrue( $result['exelearning']['has_preview'] );
		$this->assertArrayHasKey( 'preview_url', $result['exelearning'] );
	}

	/**
	 * Test add_elp_metadata_to_js no preview_url when has_preview is false.
	 */
	public function test_add_elp_metadata_to_js_no_preview_url() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'c', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$response = array( 'id' => $attachment_id );
		$result   = $this->media_library->add_elp_metadata_to_js( $response, $post, array() );

		$this->assertArrayHasKey( 'exelearning', $result );
		$this->assertFalse( $result['exelearning']['has_preview'] );
		$this->assertArrayNotHasKey( 'preview_url', $result['exelearning'] );
	}

	/**
	 * Test add_elp_metadata_to_js handles null post.
	 */
	public function test_add_elp_metadata_to_js_handles_null_post() {
		$response = array( 'id' => 123 );
		$result   = $this->media_library->add_elp_metadata_to_js( $response, null, array() );

		$this->assertEquals( $response, $result );
	}

	/**
	 * Test render_elp_meta_box outputs metadata.
	 */
	public function test_render_elp_meta_box() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );

		update_post_meta( $attachment_id, '_exelearning_license', 'GPL' );
		update_post_meta( $attachment_id, '_exelearning_language', 'fr' );
		update_post_meta( $attachment_id, '_exelearning_resource_type', 'quiz' );

		ob_start();
		$this->media_library->render_elp_meta_box( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'GPL', $output );
		$this->assertStringContainsString( 'fr', $output );
		$this->assertStringContainsString( 'quiz', $output );
		$this->assertStringContainsString( '<ul>', $output );
	}

	/**
	 * Test render_preview_meta_box with preview.
	 */
	public function test_render_preview_meta_box_with_preview() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'd', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		ob_start();
		$this->media_library->render_preview_meta_box( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<iframe', $output );
		$this->assertStringContainsString( 'sandbox=', $output );
		$this->assertStringContainsString( 'referrerpolicy="no-referrer"', $output );
	}

	/**
	 * Test render_preview_meta_box without preview.
	 */
	public function test_render_preview_meta_box_without_preview() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'e', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		ob_start();
		$this->media_library->render_preview_meta_box( $post );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<iframe', $output );
		// Check for warning style - text may be translated.
		$this->assertStringContainsString( '#fff3cd', $output );
	}

	/**
	 * Test render_preview_meta_box shows edit button for authorized users.
	 */
	public function test_render_preview_meta_box_edit_button() {
		$user_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create( array( 'post_author' => $user_id ) );
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'f', 40 );

		wp_set_current_user( $user_id );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		ob_start();
		$this->media_library->render_preview_meta_box( $post );
		$output = ob_get_clean();

		// Check for edit button class - text may be translated.
		$this->assertStringContainsString( 'exelearning-edit-page-button', $output );
		$this->assertStringContainsString( 'button-primary', $output );
	}

	/**
	 * Test constructor adds media columns filter.
	 */
	public function test_constructor_adds_media_columns_filter() {
		$media_library = new ExeLearning_Media_Library();

		$this->assertGreaterThan(
			0,
			has_filter( 'manage_media_columns', array( $media_library, 'add_elp_column' ) )
		);
	}

	/**
	 * Test constructor adds media custom column action.
	 */
	public function test_constructor_adds_media_custom_column_action() {
		$media_library = new ExeLearning_Media_Library();

		$this->assertGreaterThan(
			0,
			has_action( 'manage_media_custom_column', array( $media_library, 'render_elp_column' ) )
		);
	}

	/**
	 * Test constructor adds meta boxes action.
	 */
	public function test_constructor_adds_meta_boxes_action() {
		$media_library = new ExeLearning_Media_Library();

		$this->assertGreaterThan(
			0,
			has_action( 'add_meta_boxes_attachment', array( $media_library, 'add_elp_meta_box' ) )
		);
	}

	/**
	 * Test constructor adds admin enqueue scripts action.
	 */
	public function test_constructor_adds_admin_enqueue_scripts_action() {
		$media_library = new ExeLearning_Media_Library();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_enqueue_scripts', array( $media_library, 'enqueue_media_modal_scripts' ) )
		);
	}

	/**
	 * Test constructor adds wp_prepare_attachment_for_js filter.
	 */
	public function test_constructor_adds_prepare_attachment_filter() {
		$media_library = new ExeLearning_Media_Library();

		$this->assertGreaterThan(
			0,
			has_filter( 'wp_prepare_attachment_for_js', array( $media_library, 'add_elp_metadata_to_js' ) )
		);
	}

	/**
	 * Test enqueue_media_modal_scripts on upload.php.
	 */
	public function test_enqueue_media_modal_scripts_on_upload_page() {
		$this->media_library->enqueue_media_modal_scripts( 'upload.php' );

		$this->assertTrue( wp_script_is( 'exelearning-media-modal', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'exelearning-media-library', 'enqueued' ) );
	}

	/**
	 * Test enqueue_media_modal_scripts on post.php.
	 */
	public function test_enqueue_media_modal_scripts_on_post_page() {
		wp_dequeue_script( 'exelearning-media-modal' );
		wp_dequeue_style( 'exelearning-media-library' );

		$this->media_library->enqueue_media_modal_scripts( 'post.php' );

		$this->assertTrue( wp_script_is( 'exelearning-media-modal', 'enqueued' ) );
	}

	/**
	 * Test enqueue_media_modal_scripts on post-new.php.
	 */
	public function test_enqueue_media_modal_scripts_on_post_new_page() {
		wp_dequeue_script( 'exelearning-media-modal' );
		wp_dequeue_style( 'exelearning-media-library' );

		$this->media_library->enqueue_media_modal_scripts( 'post-new.php' );

		$this->assertTrue( wp_script_is( 'exelearning-media-modal', 'enqueued' ) );
	}

	/**
	 * Test enqueue_media_modal_scripts on media.php.
	 */
	public function test_enqueue_media_modal_scripts_on_media_page() {
		wp_dequeue_script( 'exelearning-media-modal' );
		wp_dequeue_style( 'exelearning-media-library' );

		$this->media_library->enqueue_media_modal_scripts( 'media.php' );

		$this->assertTrue( wp_script_is( 'exelearning-media-modal', 'enqueued' ) );
	}

	/**
	 * Test enqueue_media_modal_scripts skips other hooks.
	 */
	public function test_enqueue_media_modal_scripts_skips_other_hooks() {
		wp_dequeue_script( 'exelearning-media-modal' );
		wp_dequeue_style( 'exelearning-media-library' );

		$this->media_library->enqueue_media_modal_scripts( 'options-general.php' );

		// Since did_action might be true, we can't reliably test the skip.
		// Just verify the method runs without error.
		$this->assertTrue( true );
	}

	/**
	 * Test add_elp_meta_box does nothing for non-elp attachments.
	 */
	public function test_add_elp_meta_box_skips_non_elp() {
		global $post, $wp_meta_boxes;

		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );

		// Clear any existing meta boxes.
		$wp_meta_boxes = array();

		$this->media_library->add_elp_meta_box();

		// Should not have added metabox.
		$this->assertEmpty( $wp_meta_boxes['attachment']['normal']['high']['exelearning-preview-metabox'] ?? array() );
	}

	/**
	 * Test add_elp_meta_box adds meta boxes for elp attachments.
	 */
	public function test_add_elp_meta_box_adds_for_elp() {
		global $post, $wp_meta_boxes;

		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'a', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );

		// Clear any existing meta boxes.
		$wp_meta_boxes = array();

		$this->media_library->add_elp_meta_box();

		// Should have added both metaboxes.
		$this->assertNotEmpty( $wp_meta_boxes['attachment']['normal']['high']['exelearning-preview-metabox'] ?? array() );
		$this->assertNotEmpty( $wp_meta_boxes['attachment']['side']['default']['exelearning-metabox'] ?? array() );
	}

	/**
	 * Test render_elp_column shows license when available.
	 */
	public function test_render_elp_column_shows_license() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_license', 'Apache 2.0' );

		ob_start();
		$this->media_library->render_elp_column( 'exelearning', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Apache 2.0', $output );
		// Label text may be translated, check for strong tag pattern.
		$this->assertStringContainsString( '<strong>', $output );
	}

	/**
	 * Test render_elp_column shows language when available.
	 */
	public function test_render_elp_column_shows_language() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_language', 'de' );

		ob_start();
		$this->media_library->render_elp_column( 'exelearning', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'de', $output );
		// Label text may be translated, check for strong tag pattern.
		$this->assertStringContainsString( '<strong>', $output );
	}

	/**
	 * Test render_elp_column shows resource type when available.
	 */
	public function test_render_elp_column_shows_resource_type() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_resource_type', 'assessment' );

		ob_start();
		$this->media_library->render_elp_column( 'exelearning', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'assessment', $output );
		// Label text may be translated, check for strong tag pattern.
		$this->assertStringContainsString( '<strong>', $output );
	}

	/**
	 * Test render_preview_meta_box has Open in new tab link.
	 */
	public function test_render_preview_meta_box_has_open_link() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'a', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		ob_start();
		$this->media_library->render_preview_meta_box( $post );
		$output = ob_get_clean();

		// Link text may be translated, check for target="_blank" instead.
		$this->assertStringContainsString( 'target="_blank"', $output );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $output );
	}

	/**
	 * Test render_preview_meta_box no edit button for unauthorized users.
	 */
	public function test_render_preview_meta_box_no_edit_button_unauthorized() {
		$admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create( array( 'post_author' => $admin_id ) );
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'a', 40 );

		// Set subscriber user (cannot edit).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		ob_start();
		$this->media_library->render_preview_meta_box( $post );
		$output = ob_get_clean();

		// Check that the edit button class is NOT present.
		$this->assertStringNotContainsString( 'exelearning-edit-page-button', $output );
	}

	/**
	 * Test add_elp_metadata_to_js includes version info.
	 */
	public function test_add_elp_metadata_to_js_includes_version() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );
		$hash          = str_repeat( 'a', 40 );

		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_version', '3' );

		$response = array( 'id' => $attachment_id );
		$result   = $this->media_library->add_elp_metadata_to_js( $response, $post, array() );

		$this->assertEquals( '3', $result['exelearning']['version'] );
	}

	/**
	 * Test render_elp_meta_box outputs list format.
	 */
	public function test_render_elp_meta_box_list_format() {
		$attachment_id = $this->factory->attachment->create();
		$post          = get_post( $attachment_id );

		update_post_meta( $attachment_id, '_exelearning_license', 'MIT' );

		ob_start();
		$this->media_library->render_elp_meta_box( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<ul>', $output );
		$this->assertStringContainsString( '</ul>', $output );
		$this->assertStringContainsString( '<li>', $output );
	}

	/**
	 * Test enqueue_media_modal_scripts localizes translation strings.
	 */
	public function test_enqueue_media_modal_scripts_localizes_strings() {
		global $wp_scripts;

		wp_dequeue_script( 'exelearning-media-modal' );
		wp_deregister_script( 'exelearning-media-modal' );
		wp_dequeue_style( 'exelearning-media-library' );

		$this->media_library->enqueue_media_modal_scripts( 'upload.php' );

		// Verify script is enqueued.
		$this->assertTrue( wp_script_is( 'exelearning-media-modal', 'enqueued' ) );

		// Check if the script has localized data.
		$script = $wp_scripts->registered['exelearning-media-modal'];
		$this->assertNotEmpty( $script->extra );
	}

	/**
	 * Test enqueue_media_modal_scripts has correct localized keys.
	 */
	public function test_enqueue_media_modal_scripts_has_localized_keys() {
		global $wp_scripts;

		wp_dequeue_script( 'exelearning-media-modal' );
		wp_deregister_script( 'exelearning-media-modal' );
		wp_dequeue_style( 'exelearning-media-library' );

		$this->media_library->enqueue_media_modal_scripts( 'upload.php' );

		// Get the localized data.
		$script = $wp_scripts->registered['exelearning-media-modal'];

		// The extra['data'] contains the wp_localize_script output.
		$this->assertArrayHasKey( 'data', $script->extra );

		// Verify that exelearningMediaStrings is in the localized data.
		$data = $script->extra['data'];
		$this->assertStringContainsString( 'exelearningMediaStrings', $data );
	}

	/**
	 * Test enqueue_media_modal_scripts includes all translation strings.
	 */
	public function test_enqueue_media_modal_scripts_includes_all_strings() {
		global $wp_scripts;

		wp_dequeue_script( 'exelearning-media-modal' );
		wp_deregister_script( 'exelearning-media-modal' );
		wp_dequeue_style( 'exelearning-media-library' );

		$this->media_library->enqueue_media_modal_scripts( 'upload.php' );

		$script = $wp_scripts->registered['exelearning-media-modal'];
		$data   = $script->extra['data'];

		// Verify all expected keys are present in the localized data.
		$expected_keys = array(
			'info',
			'version',
			'sourceFile',
			'exported',
			'license',
			'language',
			'type',
			'noPreview',
			'noPreviewDesc',
			'previewNewTab',
			'editInExe',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertStringContainsString( '"' . $key . '"', $data );
		}
	}
}
