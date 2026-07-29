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
		$this->reset_assets();
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
	 * By default the block does NOT offer the teacher layer selector: the
	 * package keeps teacher-only content hidden and no ?exe-teacher parameter is
	 * appended to the iframe src until the author enables the control.
	 */
	public function test_block_hides_teacher_selector_by_default() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'd', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exe-teacher', $result );
		$this->assertStringNotContainsString( 'teacher-mode-toggler-wrapper', $result );
	}

	/**
	 * teacherModeVisible=true offers the selector through the package's
	 * ?exe-teacher=1 URL parameter, without injecting any CSS/JS.
	 */
	public function test_block_offers_teacher_selector_when_enabled() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'd', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block(
			array(
				'attachmentId'       => $attachment_id,
				'teacherModeVisible' => true,
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringContainsString( 'exe-teacher=1', $result );
		$this->assertStringNotContainsString( 'teacher-mode-toggler-wrapper', $result );
	}

	/**
	 * teacherModeVisible=false hides the selector: no ?exe-teacher parameter is
	 * appended to the iframe src.
	 */
	public function test_block_hides_teacher_selector_when_disabled() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'e', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block(
			array(
				'attachmentId'       => $attachment_id,
				'teacherModeVisible' => false,
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exe-teacher', $result );
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
	 * By default (secure mode) the block iframe is opaque-origin (no allow-same-origin).
	 */
	public function test_block_sandbox_secure_default() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'e', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringNotContainsString( 'allow-same-origin', $result );
	}

	/**
	 * The same-origin admin mode was removed: a leftover option=legacy is ignored, so the
	 * block iframe stays opaque (no allow-same-origin). A security regression guard.
	 */
	public function test_block_ignores_legacy_option_and_stays_opaque() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, ExeLearning_Iframe_Sandbox::MODE_LEGACY );

		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'f', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringNotContainsString( 'allow-same-origin', $result );
	}

	/**
	 * In secure mode the teacher selector is offered on the iframe src via ?exe-teacher=1
	 * (read by the package from its own URL), with no contentDocument injection and no
	 * legacy exe-teacher-toggler parameter.
	 */
	public function test_block_secure_offers_selector_via_query() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, ExeLearning_Iframe_Sandbox::MODE_SECURE );

		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->block->render_block(
			array(
				'attachmentId'       => $attachment_id,
				'teacherModeVisible' => true,
			)
		);

		$this->assertStringContainsString( 'exe-teacher=1', $result );
		$this->assertStringNotContainsString( 'exe-teacher-toggler', $result );
		$this->assertStringNotContainsString( 'contentDocument', $result );
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
	 * Test no preview falls back to the legacy download link by default.
	 * (Multi-format download button is opt-in.)
	 */
	public function test_no_preview_shows_download_link() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-download-link', $result );
		$this->assertStringNotContainsString( 'data-format=', $result );
		$this->assertStringContainsString( 'download', $result );
	}

	/**
	 * Test no preview with the multi-format download button explicitly enabled.
	 */
	public function test_no_preview_with_download_enabled_renders_split_button() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'showDownload' => true,
			)
		);

		$this->assertStringContainsString( 'exelearning-download', $result );
		$this->assertStringContainsString( 'data-format="elpx"', $result );
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

	/**
	 * The editor must load the shared download orchestrator so the edit-mode
	 * download toolbar can reuse window.wpExeDownload (single export pipeline).
	 */
	public function test_enqueue_block_scripts_enqueues_download_script() {
		$this->block->enqueue_block_scripts();

		$this->assertTrue( wp_script_is( 'exelearning-download', 'enqueued' ) );
	}

	/**
	 * The block script must depend on the download handle so window.wpExeDownload
	 * is defined before the edit-mode toolbar runs.
	 */
	public function test_enqueue_block_scripts_depends_on_download() {
		global $wp_scripts;

		wp_dequeue_script( 'exelearning-elp-block' );
		wp_deregister_script( 'exelearning-elp-block' );

		$this->block->enqueue_block_scripts();

		$script = $wp_scripts->registered['exelearning-elp-block'];
		$this->assertContains( 'exelearning-download', $script->deps );
	}

	/**
	 * The download config (export base, editor URL, i18n) must be localized for
	 * the editor so edit-mode exports can reach the export bootstrap endpoint.
	 */
	public function test_enqueue_block_scripts_localizes_download_config() {
		global $wp_scripts;

		wp_dequeue_script( 'exelearning-download' );
		wp_deregister_script( 'exelearning-download' );

		$this->block->enqueue_block_scripts();

		$data = $wp_scripts->get_data( 'exelearning-download', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'wpExeDownloadConfig', $data );
		// editorInstalled drives whether the edit-mode toolbar disables the
		// client-export formats, so it must be present in the localized config.
		$this->assertStringContainsString( 'editorInstalled', $data );
	}

	/**
	 * The editor must load the frontend stylesheet so the .exelearning-download
	 * split-button is styled inside the edit canvas.
	 */
	public function test_enqueue_block_scripts_enqueues_frontend_style() {
		$this->block->enqueue_block_scripts();

		$this->assertTrue( wp_style_is( 'exelearning-frontend', 'enqueued' ) );
	}

	/**
	 * Create a previewable attachment for block render tests.
	 *
	 * @param string $hash Extraction hash (40 hex chars).
	 * @return int Attachment ID.
	 */
	private function create_previewable_attachment( $hash ) {
		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		return $attachment_id;
	}

	/**
	 * The block does NOT render the fullscreen button by default (opt-in).
	 */
	public function test_block_hides_fullscreen_button_by_default() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
	}

	/**
	 * fullscreen=false hides the block fullscreen button and its click handler.
	 */
	public function test_block_hides_fullscreen_button_when_disabled() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'b', 40 ) );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'fullscreen'   => false,
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
		$this->assertStringNotContainsString( 'requestFullscreen', $result );
	}

	/**
	 * The block fullscreen button exposes an accessible name and a hidden icon.
	 */
	public function test_block_fullscreen_button_is_accessible() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'c', 40 ) );

		$result = $this->block->render_block(
			array(
				'attachmentId' => $attachment_id,
				'fullscreen'   => true,
			)
		);

		$this->assertMatchesRegularExpression(
			'/exelearning-fullscreen-btn"[^>]*aria-label="/',
			$result
		);
		$this->assertStringContainsString( 'aria-hidden="true"', $result );
	}

	/**
	 * The block iframe carries the shared .exelearning-iframe class so the
	 * fullscreen script can target it.
	 */
	public function test_block_iframe_has_shared_class() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'd', 40 ) );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-iframe', $result );
	}

	/**
	 * The preview iframe is wrapped in a loader that covers the blank frame
	 * while the package downloads and lays out.
	 */
	public function test_block_preview_has_loading_spinner() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'f', 40 ) );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-embed-loader', $result );
		$this->assertStringContainsString( 'exelearning-embed-loader__spinner', $result );
		// Announced to assistive tech rather than silently spinning.
		$this->assertStringContainsString( 'role="status"', $result );
		$this->assertStringContainsString( 'aria-live="polite"', $result );
		// The loader ships as one enqueued asset, so no copy of its behavior is
		// inlined into the block. Asserted against its own distinctive tokens
		// rather than "contains no <script>": the opt-in fullscreen button still
		// prints an inline script of its own.
		$this->assertStringNotContainsString( 'exeLoaderBound', $result );
		$this->assertStringNotContainsString( 'IntersectionObserver', $result );
	}

	/**
	 * Without JavaScript the spinner must never appear: the `is-loading` class
	 * that reveals it is added by the enqueued script, never rendered server-side.
	 */
	public function test_block_spinner_is_not_shown_without_javascript() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'g', 40 ) );

		$result = $this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertStringNotContainsString( 'is-loading', $result );
	}

	/**
	 * The loader is registered on the frontend hook but not shipped from it: a page
	 * with no eXeLearning block has nothing for it to bind.
	 */
	public function test_block_registers_loader_without_enqueueing_it() {
		// WP_UnitTestCase keeps one $wp_scripts for the whole process, and the tests
		// above render previews, which now enqueue the loader. Start from a known
		// state or this asserts the leftovers of whatever ran first.
		wp_dequeue_script( 'exelearning-embed-loader' );

		$this->block->enqueue_frontend_styles();

		$this->assertTrue( wp_script_is( 'exelearning-embed-loader', 'registered' ) );
		$this->assertFalse( wp_script_is( 'exelearning-embed-loader', 'enqueued' ) );
	}

	/**
	 * Rendering a preview is what pulls the loader in, so it ships exactly on the
	 * pages that carry a wrapper for it to find.
	 */
	public function test_block_enqueues_loader_when_a_preview_renders() {
		wp_dequeue_script( 'exelearning-embed-loader' );
		$this->block->enqueue_frontend_styles();
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'h', 40 ) );

		$this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertTrue( wp_script_is( 'exelearning-embed-loader', 'enqueued' ) );
	}

	/**
	 * A block with no preview renders no wrapper, so it must not drag the loader
	 * onto the page either.
	 */
	public function test_block_without_preview_does_not_enqueue_loader() {
		wp_dequeue_script( 'exelearning-embed-loader' );
		$this->block->enqueue_frontend_styles();
		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, '_exelearning_extracted', str_repeat( 'i', 40 ) );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertFalse( wp_script_is( 'exelearning-embed-loader', 'enqueued' ) );
	}

	/**
	 * Rendering the block enqueues the Dashicons font used by the toolbar icons.
	 */
	public function test_block_render_enqueues_dashicons() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'e', 40 ) );

		$this->block->render_block( array( 'attachmentId' => $attachment_id ) );

		$this->assertTrue( wp_style_is( 'dashicons', 'enqueued' ) );
	}

	/**
	 * Start each test with empty script and style registries.
	 *
	 * WP_UnitTestCase keeps one $wp_scripts and one $wp_styles for the whole process, so
	 * registrations and inline data pile up across tests: whichever test enqueues first
	 * makes every later assertion about "is this on the page?" true for free, in file
	 * order, whether or not the code under test did anything. This file asserts against
	 * both registries, so both have to be dropped.
	 *
	 * WordPress rebuilds each one lazily on next use -- wp_scripts() and wp_styles()
	 * re-register the defaults, dashicons included -- so nothing has to be restored here.
	 */
	private function reset_assets() {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}
}
