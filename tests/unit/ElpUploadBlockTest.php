<?php
/**
 * Tests for ExeLearning_Elp_Upload_Block class.
 *
 * @package Exelearning
 */

/**
 * Class ElpUploadBlockTest.
 *
 * @covers ExeLearning_Elp_Upload_Block
 */
class ElpUploadBlockTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Elp_Upload_Block
	 */
	private $block;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->block = new ExeLearning_Elp_Upload_Block();
	}

	/**
	 * Test render_block returns empty for missing attachment ID.
	 */
	public function test_render_block_with_empty_attachment() {
		$result = $this->block->render_block( array() );
		$this->assertEmpty( $result );
	}

	/**
	 * Test render_block returns empty for zero attachment ID.
	 */
	public function test_render_block_with_zero_attachment() {
		$result = $this->block->render_block( array( 'attachmentId' => 0 ) );
		$this->assertEmpty( $result );
	}

	/**
	 * Test render_block returns error for attachment without extracted content.
	 */
	public function test_render_block_without_extracted_content() {
		$attachment_id = $this->factory->attachment->create();

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-error', $result );
		// Error message text may be translated.
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test render_block shows download link for no preview.
	 */
	public function test_render_block_with_no_preview() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-no-preview', $result );
		// Download link - check for download attribute or href, text may be translated.
		$this->assertStringContainsString( 'download', $result );
	}

	/**
	 * Test render_block renders iframe with preview.
	 */
	public function test_render_block_with_preview() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'b', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringContainsString( $hash, $result );
	}

	/**
	 * Test block uses proxy URL instead of direct URL.
	 */
	public function test_block_uses_proxy_url() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'c', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		// Should NOT contain direct uploads path.
		$this->assertStringNotContainsString( 'wp-content/uploads/exelearning', $result );
	}

	/**
	 * Test iframe has sandbox attribute.
	 */
	public function test_iframe_has_sandbox() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'd', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'sandbox=', $result );
		$this->assertStringContainsString( 'allow-scripts', $result );
	}

	/**
	 * Test iframe has referrerpolicy.
	 */
	public function test_iframe_has_referrerpolicy() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'e', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'referrerpolicy="no-referrer"', $result );
	}

	/**
	 * Test custom height attribute.
	 */
	public function test_custom_height() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'f', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'height'       => 900,
			)
		);

		$this->assertStringContainsString( '900px', $result );
	}

	/**
	 * Test default height is 600px.
	 */
	public function test_default_height() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( '600px', $result );
	}

	/**
	 * Test alignment class is added.
	 */
	public function test_alignment_class() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'align'        => 'wide',
			)
		);

		$this->assertStringContainsString( 'alignwide', $result );
	}

	/**
	 * Test full alignment class.
	 */
	public function test_full_alignment_class() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'align'        => 'full',
			)
		);

		$this->assertStringContainsString( 'alignfull', $result );
	}

	/**
	 * Test no alignment class when align is none.
	 */
	public function test_no_alignment_class_when_none() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'align'        => 'none',
			)
		);

		$this->assertStringNotContainsString( 'alignnone', $result );
	}

	/**
	 * Test block has wrapper classes.
	 */
	public function test_block_has_wrapper_classes() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'wp-block-exelearning-elp-upload', $result );
		$this->assertStringContainsString( 'exelearning-block-frontend', $result );
	}

	/**
	 * Test iframe has loading lazy attribute.
	 */
	public function test_iframe_has_loading_lazy() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'loading="lazy"', $result );
	}

	/**
	 * Test iframe has title attribute.
	 */
	public function test_iframe_has_title() {
		$attachment_id = $this->factory->attachment->create(
			array( 'post_title' => 'Test ELP Content' )
		);
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'title="Test ELP Content"', $result );
	}

	/**
	 * Test no preview shows download link.
	 */
	public function test_no_preview_shows_download_link() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-download-link', $result );
		$this->assertStringContainsString( 'download', $result );
	}

	/**
	 * Test no preview shows notice message.
	 */
	public function test_no_preview_shows_notice() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-notice', $result );
		// Notice message text may be translated.
		$this->assertStringContainsString( '<p>', $result );
	}

	/**
	 * Test register_block method exists.
	 */
	public function test_register_block_method_exists() {
		$this->assertTrue( method_exists( $this->block, 'register_block' ) );
	}

	/**
	 * Test enqueue_block_scripts method exists.
	 */
	public function test_enqueue_block_scripts_method_exists() {
		$this->assertTrue( method_exists( $this->block, 'enqueue_block_scripts' ) );
	}

	/**
	 * Test enqueue_frontend_styles method exists.
	 */
	public function test_enqueue_frontend_styles_method_exists() {
		$this->assertTrue( method_exists( $this->block, 'enqueue_frontend_styles' ) );
	}

	/**
	 * Test constructor adds init action.
	 */
	public function test_constructor_adds_init_action() {
		$block = new ExeLearning_Elp_Upload_Block();

		$this->assertGreaterThan(
			0,
			has_action( 'init', array( $block, 'register_block' ) )
		);
	}

	/**
	 * Test constructor adds enqueue_block_editor_assets action.
	 */
	public function test_constructor_adds_block_editor_assets_action() {
		$block = new ExeLearning_Elp_Upload_Block();

		$this->assertGreaterThan(
			0,
			has_action( 'enqueue_block_editor_assets', array( $block, 'enqueue_block_scripts' ) )
		);
	}

	/**
	 * Test constructor adds wp_enqueue_scripts action.
	 */
	public function test_constructor_adds_wp_enqueue_scripts_action() {
		$block = new ExeLearning_Elp_Upload_Block();

		$this->assertGreaterThan(
			0,
			has_action( 'wp_enqueue_scripts', array( $block, 'enqueue_frontend_styles' ) )
		);
	}

	/**
	 * Test register_block registers the block type.
	 */
	public function test_register_block_registers_block_type() {
		// Unregister if already registered.
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'exelearning/elp-upload' ) ) {
			unregister_block_type( 'exelearning/elp-upload' );
		}

		$this->block->register_block();

		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'exelearning/elp-upload' )
		);
	}

	/**
	 * Test enqueue_frontend_styles enqueues the frontend style.
	 */
	public function test_enqueue_frontend_styles_enqueues_style() {
		$this->block->enqueue_frontend_styles();

		$this->assertTrue( wp_style_is( 'exelearning-frontend', 'enqueued' ) );
	}

	/**
	 * Test enqueue_block_scripts enqueues the block script.
	 */
	public function test_enqueue_block_scripts_enqueues_script() {
		$this->block->enqueue_block_scripts();

		$this->assertTrue( wp_script_is( 'exelearning-elp-block', 'enqueued' ) );
	}

	/**
	 * Test enqueue_block_scripts enqueues the block editor style.
	 */
	public function test_enqueue_block_scripts_enqueues_editor_style() {
		$this->block->enqueue_block_scripts();

		$this->assertTrue( wp_style_is( 'exelearning-block-editor', 'enqueued' ) );
	}

	/**
	 * Test render_block with string attachment ID.
	 */
	public function test_render_block_with_string_attachment_id() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => (string) $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
	}

	/**
	 * Test render_block with negative attachment ID.
	 */
	public function test_render_block_with_negative_attachment_id() {
		$result = $this->block->render_block( array( 'attachmentId' => -1 ) );

		$this->assertStringContainsString( 'exelearning-error', $result );
	}

	/**
	 * Test render_block with non-existing attachment ID.
	 */
	public function test_render_block_with_nonexistent_attachment() {
		$result = $this->block->render_block( array( 'attachmentId' => 999999 ) );

		$this->assertStringContainsString( 'exelearning-error', $result );
	}

	/**
	 * Test block type has correct attributes.
	 */
	public function test_block_type_has_correct_attributes() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'exelearning/elp-upload' ) ) {
			unregister_block_type( 'exelearning/elp-upload' );
		}

		$this->block->register_block();

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'exelearning/elp-upload' );

		$this->assertArrayHasKey( 'attachmentId', $block_type->attributes );
		$this->assertArrayHasKey( 'height', $block_type->attributes );
		$this->assertArrayHasKey( 'align', $block_type->attributes );
	}

	/**
	 * Test block type has render callback.
	 */
	public function test_block_type_has_render_callback() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'exelearning/elp-upload' ) ) {
			unregister_block_type( 'exelearning/elp-upload' );
		}

		$this->block->register_block();

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'exelearning/elp-upload' );

		$this->assertNotNull( $block_type->render_callback );
		$this->assertIsCallable( $block_type->render_callback );
	}

	/**
	 * Test no preview has wrapper classes.
	 */
	public function test_no_preview_has_wrapper_classes() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'wp-block-exelearning-elp-upload', $result );
	}

	/**
	 * Test no preview with wide alignment.
	 */
	public function test_no_preview_with_wide_alignment() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'align'        => 'wide',
			)
		);

		$this->assertStringContainsString( 'alignwide', $result );
	}

	/**
	 * Test enqueue_block_scripts registers script translations.
	 */
	public function test_enqueue_block_scripts_registers_script_translations() {
		global $wp_scripts;

		// Dequeue and reset scripts.
		wp_dequeue_script( 'exelearning-elp-block' );
		wp_deregister_script( 'exelearning-elp-block' );

		$this->block->enqueue_block_scripts();

		// Verify script is enqueued.
		$this->assertTrue( wp_script_is( 'exelearning-elp-block', 'enqueued' ) );

		// Verify the script has wp-i18n as a dependency.
		$script = $wp_scripts->registered['exelearning-elp-block'];
		$this->assertContains( 'wp-i18n', $script->deps );
	}

	/**
	 * Test enqueue_block_scripts has correct dependencies.
	 */
	public function test_enqueue_block_scripts_has_correct_dependencies() {
		global $wp_scripts;

		wp_dequeue_script( 'exelearning-elp-block' );
		wp_deregister_script( 'exelearning-elp-block' );

		$this->block->enqueue_block_scripts();

		$script = $wp_scripts->registered['exelearning-elp-block'];

		// Check all required dependencies.
		$this->assertContains( 'wp-blocks', $script->deps );
		$this->assertContains( 'wp-element', $script->deps );
		$this->assertContains( 'wp-block-editor', $script->deps );
		$this->assertContains( 'wp-components', $script->deps );
		$this->assertContains( 'wp-i18n', $script->deps );
	}
}
