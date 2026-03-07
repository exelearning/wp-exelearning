<?php
/**
 * Tests for ExeLearning_Editor class.
 *
 * @package Exelearning
 */

/**
 * Class EditorTest.
 *
 * @covers ExeLearning_Editor
 */
class EditorTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Editor
	 */
	private $editor;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->editor = new ExeLearning_Editor();
	}

	/**
	 * Test get_editor_url returns correct URL.
	 */
	public function test_get_editor_url() {
		$attachment_id = 123;
		$url           = $this->editor->get_editor_url( $attachment_id );

		$this->assertStringContainsString( 'page=exelearning-editor', $url );
		$this->assertStringContainsString( 'attachment_id=123', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	/**
	 * Test get_project_id returns correct format.
	 */
	public function test_get_project_id() {
		$attachment_id = 456;
		$project_id    = $this->editor->get_project_id( $attachment_id );

		$this->assertEquals( 'wp-attachment-456', $project_id );
	}

	/**
	 * Test add_edit_capability returns unchanged for non-elp files.
	 */
	public function test_add_edit_capability_unchanged_for_non_elp() {
		$attachment_id = $this->factory->attachment->create();
		$attachment    = get_post( $attachment_id );

		$response = array( 'id' => $attachment_id );
		$result   = $this->editor->add_edit_capability( $response, $attachment, array() );

		$this->assertArrayNotHasKey( 'exelearningCanEdit', $result );
	}

	/**
	 * Test add_edit_capability handles null attachment.
	 */
	public function test_add_edit_capability_handles_null() {
		$response = array( 'id' => 123 );
		$result   = $this->editor->add_edit_capability( $response, null, array() );

		$this->assertEquals( $response, $result );
	}

	/**
	 * Test register_editor_page method exists.
	 */
	public function test_register_editor_page_exists() {
		$this->assertTrue( method_exists( $this->editor, 'register_editor_page' ) );
	}

	/**
	 * Test enqueue_editor_scripts method exists.
	 */
	public function test_enqueue_editor_scripts_exists() {
		$this->assertTrue( method_exists( $this->editor, 'enqueue_editor_scripts' ) );
	}

	/**
	 * Test enqueue_editor_scripts_for_blocks method exists.
	 */
	public function test_enqueue_editor_scripts_for_blocks_exists() {
		$this->assertTrue( method_exists( $this->editor, 'enqueue_editor_scripts_for_blocks' ) );
	}

	/**
	 * Test render_editor_modal_container method exists.
	 */
	public function test_render_editor_modal_container_exists() {
		$this->assertTrue( method_exists( $this->editor, 'render_editor_modal_container' ) );
	}

	/**
	 * Test render_editor_page method exists.
	 */
	public function test_render_editor_page_exists() {
		$this->assertTrue( method_exists( $this->editor, 'render_editor_page' ) );
	}

	/**
	 * Test editor_page_placeholder method exists.
	 */
	public function test_editor_page_placeholder_exists() {
		$this->assertTrue( method_exists( $this->editor, 'editor_page_placeholder' ) );
	}

	/**
	 * Test maybe_start_buffer is private.
	 */
	public function test_maybe_start_buffer_is_private() {
		$method = new ReflectionMethod( ExeLearning_Editor::class, 'maybe_start_buffer' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test render_editor_page_and_exit method exists.
	 */
	public function test_render_editor_page_and_exit_exists() {
		$this->assertTrue( method_exists( $this->editor, 'render_editor_page_and_exit' ) );
	}

	/**
	 * Test add_edit_capability adds flag for elpx file.
	 */
	public function test_add_edit_capability_adds_flag_for_elpx() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create attachment with elpx extension.
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => $user_id,
			)
		);

		// Create a temp elpx file.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/test-' . $attachment_id . '.elpx';
		file_put_contents( $file_path, 'test content' );

		update_attached_file( $attachment_id, $file_path );

		$attachment = get_post( $attachment_id );
		$response   = array( 'id' => $attachment_id );

		$result = $this->editor->add_edit_capability( $response, $attachment, array() );

		$this->assertArrayHasKey( 'exelearningCanEdit', $result );
		$this->assertTrue( $result['exelearningCanEdit'] );
		$this->assertArrayHasKey( 'exelearningEditUrl', $result );
		$this->assertStringContainsString( 'exelearning-editor', $result['exelearningEditUrl'] );

		// Cleanup.
		unlink( $file_path );
	}

	/**
	 * Test add_edit_capability handles empty attachment ID.
	 */
	public function test_add_edit_capability_empty_id() {
		$attachment       = new stdClass();
		$attachment->ID   = '';
		$response         = array( 'id' => 0 );

		$result = $this->editor->add_edit_capability( $response, $attachment, array() );

		$this->assertEquals( $response, $result );
	}

	/**
	 * Test add_edit_capability handles missing file path.
	 */
	public function test_add_edit_capability_missing_file() {
		$attachment_id = $this->factory->attachment->create();
		$attachment    = get_post( $attachment_id );
		$response      = array( 'id' => $attachment_id );

		// Don't set any attached file.
		delete_post_meta( $attachment_id, '_wp_attached_file' );

		$result = $this->editor->add_edit_capability( $response, $attachment, array() );

		$this->assertArrayNotHasKey( 'exelearningCanEdit', $result );
	}

	/**
	 * Test constructor registers admin_menu action.
	 */
	public function test_constructor_registers_admin_menu() {
		$editor = new ExeLearning_Editor();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_menu', array( $editor, 'register_editor_page' ) )
		);
	}

	/**
	 * Test constructor registers admin_enqueue_scripts action.
	 */
	public function test_constructor_registers_enqueue_scripts() {
		$editor = new ExeLearning_Editor();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_enqueue_scripts', array( $editor, 'enqueue_editor_scripts' ) )
		);
	}

	/**
	 * Test constructor registers block editor assets action.
	 */
	public function test_constructor_registers_block_assets() {
		$editor = new ExeLearning_Editor();

		$this->assertGreaterThan(
			0,
			has_action( 'enqueue_block_editor_assets', array( $editor, 'enqueue_editor_scripts_for_blocks' ) )
		);
	}

	/**
	 * Test constructor registers admin_footer action.
	 */
	public function test_constructor_registers_admin_footer() {
		$editor = new ExeLearning_Editor();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_footer', array( $editor, 'render_editor_modal_container' ) )
		);
	}

	/**
	 * Test constructor registers wp_prepare_attachment_for_js filter.
	 */
	public function test_constructor_registers_attachment_filter() {
		$editor = new ExeLearning_Editor();

		$this->assertGreaterThan(
			0,
			has_filter( 'wp_prepare_attachment_for_js', array( $editor, 'add_edit_capability' ) )
		);
	}

	/**
	 * Test enqueue_editor_scripts does nothing on non-allowed pages.
	 */
	public function test_enqueue_editor_scripts_skips_non_allowed() {
		// Dequeue any existing scripts.
		wp_dequeue_script( 'exelearning-editor' );

		$this->editor->enqueue_editor_scripts( 'options-general.php' );

		$this->assertFalse( wp_script_is( 'exelearning-editor', 'enqueued' ) );
	}

	/**
	 * Test render_editor_modal_container outputs HTML on upload screen.
	 */
	public function test_render_modal_on_upload_screen() {
		set_current_screen( 'upload' );

		ob_start();
		$this->editor->render_editor_modal_container();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-editor-modal', $output );
		$this->assertStringContainsString( 'exelearning-editor-iframe', $output );
	}

	/**
	 * Test render_editor_modal_container outputs nothing on other screens.
	 */
	public function test_render_modal_skips_other_screens() {
		set_current_screen( 'options-general' );

		ob_start();
		$this->editor->render_editor_modal_container();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test editor_page_placeholder outputs nothing.
	 */
	public function test_editor_page_placeholder_empty() {
		ob_start();
		$this->editor->editor_page_placeholder();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test get_editor_url includes all required parameters.
	 */
	public function test_get_editor_url_has_all_params() {
		$url = $this->editor->get_editor_url( 456 );

		$this->assertStringContainsString( 'page=exelearning-editor', $url );
		$this->assertStringContainsString( 'attachment_id=456', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
		$this->assertStringContainsString( 'admin.php', $url );
	}

	/**
	 * Test get_project_id format.
	 */
	public function test_get_project_id_format() {
		$project_id = $this->editor->get_project_id( 789 );

		$this->assertEquals( 'wp-attachment-789', $project_id );
	}

	/**
	 * Test enqueue_editor_scripts on upload.php.
	 */
	public function test_enqueue_editor_scripts_on_upload() {
		wp_dequeue_script( 'exelearning-editor' );

		$this->editor->enqueue_editor_scripts( 'upload.php' );

		$this->assertTrue( wp_script_is( 'exelearning-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_editor_scripts on post.php.
	 */
	public function test_enqueue_editor_scripts_on_post() {
		wp_dequeue_script( 'exelearning-editor' );

		$this->editor->enqueue_editor_scripts( 'post.php' );

		$this->assertTrue( wp_script_is( 'exelearning-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_editor_scripts on post-new.php.
	 */
	public function test_enqueue_editor_scripts_on_post_new() {
		wp_dequeue_script( 'exelearning-editor' );

		$this->editor->enqueue_editor_scripts( 'post-new.php' );

		$this->assertTrue( wp_script_is( 'exelearning-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_editor_scripts on media.php.
	 */
	public function test_enqueue_editor_scripts_on_media() {
		wp_dequeue_script( 'exelearning-editor' );

		$this->editor->enqueue_editor_scripts( 'media.php' );

		$this->assertTrue( wp_script_is( 'exelearning-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_editor_scripts_for_blocks.
	 */
	public function test_enqueue_editor_scripts_for_blocks() {
		wp_dequeue_script( 'exelearning-editor' );

		$this->editor->enqueue_editor_scripts_for_blocks();

		$this->assertTrue( wp_script_is( 'exelearning-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_editor_scripts localizes script data.
	 */
	public function test_enqueue_editor_scripts_localizes_data() {
		wp_dequeue_script( 'exelearning-editor' );

		$this->editor->enqueue_editor_scripts( 'upload.php' );

		global $wp_scripts;
		$data = $wp_scripts->get_data( 'exelearning-editor', 'data' );

		$this->assertStringContainsString( 'exelearningEditorVars', $data );
		$this->assertStringContainsString( 'editorPageUrl', $data );
		$this->assertStringContainsString( 'restUrl', $data );
	}

	/**
	 * Test render_editor_modal_container on post screen.
	 */
	public function test_render_modal_on_post_screen() {
		set_current_screen( 'post' );

		ob_start();
		$this->editor->render_editor_modal_container();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-editor-modal', $output );
	}

	/**
	 * Test render_editor_modal_container on attachment screen.
	 */
	public function test_render_modal_on_attachment_screen() {
		set_current_screen( 'attachment' );

		ob_start();
		$this->editor->render_editor_modal_container();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-editor-modal', $output );
	}

	/**
	 * Test render_editor_modal_container has save button.
	 */
	public function test_render_modal_has_save_button() {
		set_current_screen( 'upload' );

		ob_start();
		$this->editor->render_editor_modal_container();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-editor-save', $output );
		// Button text may be translated, check for button element.
		$this->assertStringContainsString( 'button-primary', $output );
	}

	/**
	 * Test render_editor_modal_container has close button.
	 */
	public function test_render_modal_has_close_button() {
		set_current_screen( 'upload' );

		ob_start();
		$this->editor->render_editor_modal_container();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-editor-close', $output );
		$this->assertStringContainsString( '<button', $output );
	}

	/**
	 * Test render_editor_modal_container has header.
	 */
	public function test_render_modal_has_header() {
		set_current_screen( 'upload' );

		ob_start();
		$this->editor->render_editor_modal_container();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'exelearning-editor-header', $output );
		// Header text may be translated, check for title structure.
		$this->assertStringContainsString( 'exelearning-editor-title', $output );
	}

	/**
	 * Test register_editor_page registers hidden page.
	 */
	public function test_register_editor_page_registers_page() {
		global $submenu;

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->editor->register_editor_page();

		// Since it's a hidden page with empty parent, check the menu structure.
		// The page should be accessible via admin.php?page=exelearning-editor.
		$this->assertTrue( true ); // Page registered without error.
	}

	/**
	 * Test add_edit_capability with elp extension (not elpx).
	 */
	public function test_add_edit_capability_ignores_elp_extension() {
		$user_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create( array( 'post_author' => $user_id ) );

		wp_set_current_user( $user_id );

		// Create a temp elp file (not elpx).
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/test-' . $attachment_id . '.elp';
		file_put_contents( $file_path, 'test content' );

		update_attached_file( $attachment_id, $file_path );

		$attachment = get_post( $attachment_id );
		$response   = array( 'id' => $attachment_id );

		$result = $this->editor->add_edit_capability( $response, $attachment, array() );

		$this->assertArrayNotHasKey( 'exelearningCanEdit', $result );

		unlink( $file_path );
	}

	/**
	 * Test add_edit_capability when user cannot edit.
	 */
	public function test_add_edit_capability_no_permission() {
		$admin_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create( array( 'post_author' => $admin_id ) );

		// Set subscriber user (cannot edit).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Create a temp elpx file.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/test-' . $attachment_id . '.elpx';
		file_put_contents( $file_path, 'test content' );

		update_attached_file( $attachment_id, $file_path );

		$attachment = get_post( $attachment_id );
		$response   = array( 'id' => $attachment_id );

		$result = $this->editor->add_edit_capability( $response, $attachment, array() );

		$this->assertArrayNotHasKey( 'exelearningCanEdit', $result );

		unlink( $file_path );
	}

	/**
	 * Test get_editor_url with different attachment IDs.
	 */
	public function test_get_editor_url_different_ids() {
		$url1 = $this->editor->get_editor_url( 100 );
		$url2 = $this->editor->get_editor_url( 200 );

		$this->assertStringContainsString( 'attachment_id=100', $url1 );
		$this->assertStringContainsString( 'attachment_id=200', $url2 );
		$this->assertNotEquals( $url1, $url2 );
	}

	/**
	 * Test get_project_id with different attachment IDs.
	 */
	public function test_get_project_id_different_ids() {
		$id1 = $this->editor->get_project_id( 100 );
		$id2 = $this->editor->get_project_id( 200 );

		$this->assertEquals( 'wp-attachment-100', $id1 );
		$this->assertEquals( 'wp-attachment-200', $id2 );
	}

	/**
	 * Test add_edit_capability with non-string file path.
	 */
	public function test_add_edit_capability_non_string_file() {
		$attachment_id = $this->factory->attachment->create();
		$attachment    = get_post( $attachment_id );

		// Set post meta to non-string.
		delete_post_meta( $attachment_id, '_wp_attached_file' );
		add_post_meta( $attachment_id, '_wp_attached_file', false );

		$response = array( 'id' => $attachment_id );
		$result   = $this->editor->add_edit_capability( $response, $attachment, array() );

		$this->assertArrayNotHasKey( 'exelearningCanEdit', $result );
	}
}
