<?php
/**
 * Tests for ExeLearning_Shortcodes class.
 *
 * @package Exelearning
 */

/**
 * Class ShortcodesTest.
 *
 * @covers ExeLearning_Shortcodes
 */
class ShortcodesTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->shortcodes = new ExeLearning_Shortcodes();
	}

	/**
	 * Test shortcode is registered.
	 */
	public function test_shortcode_is_registered() {
		$this->shortcodes->register_shortcodes();
		$this->assertTrue( shortcode_exists( 'exelearning' ) );
	}

	/**
	 * Test display_exelearning returns error for invalid ID.
	 */
	public function test_display_exelearning_with_invalid_id() {
		$result = $this->shortcodes->display_exelearning( array( 'id' => 0 ) );

		$this->assertStringContainsString( 'exelearning-error', $result );
		// Check for error wrapper - text may be translated.
		$this->assertStringContainsString( '<p>', $result );
	}

	/**
	 * Test display_exelearning returns error for non-existent post.
	 */
	public function test_display_exelearning_with_nonexistent_post() {
		$result = $this->shortcodes->display_exelearning( array( 'id' => 999999 ) );

		$this->assertStringContainsString( 'exelearning-error', $result );
		// Check for error wrapper - text may be translated.
		$this->assertStringContainsString( '<p>', $result );
	}

	/**
	 * Test display_exelearning returns error for non-attachment post.
	 */
	public function test_display_exelearning_with_non_attachment() {
		// Create a regular post (not an attachment).
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $post_id ) );

		$this->assertStringContainsString( 'exelearning-error', $result );
	}

	/**
	 * Test display_exelearning shows no-preview for attachment without preview.
	 */
	public function test_display_exelearning_no_preview() {
		// Create an attachment.
		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, '_exelearning_extracted', 'somehash' );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '0' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-no-preview', $result );
		$this->assertStringContainsString( 'download', $result );
	}

	/**
	 * Test display_exelearning renders iframe when preview is available.
	 */
	public function test_display_exelearning_renders_iframe() {
		// Create an attachment with preview.
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringContainsString( 'exelearning-iframe', $result );
	}

	/**
	 * Test iframe has sandbox attribute for security.
	 */
	public function test_iframe_has_sandbox_attribute() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'b', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'sandbox=', $result );
		$this->assertStringContainsString( 'allow-scripts', $result );
		// allow-same-origin is required for the eXeLearning viewer (a same-origin
		// app) to render inside the iframe.
		$this->assertStringContainsString( 'allow-same-origin', $result );
		// allow-modals is intentionally NOT granted so the preview cannot raise
		// "Leave site?" dialogs.
		$this->assertStringNotContainsString( 'allow-modals', $result );
	}

	/**
	 * Test iframe has referrerpolicy attribute for security.
	 */
	public function test_iframe_has_referrerpolicy() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'c', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'referrerpolicy="no-referrer"', $result );
	}

	/**
	 * Test custom height attribute is respected.
	 */
	public function test_custom_height_attribute() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'd', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'     => $attachment_id,
				'height' => 800,
			)
		);

		$this->assertStringContainsString( '800px', $result );
	}

	/**
	 * Test default height is 600px.
	 */
	public function test_default_height() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'e', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( '600px', $result );
	}

	/**
	 * Test iframe uses proxy URL.
	 */
	public function test_iframe_uses_proxy_url() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'f', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringContainsString( $hash, $result );
	}

	/**
	 * Test toolbar contains download button.
	 */
	public function test_toolbar_has_download_button() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-toolbar', $result );
		$this->assertStringContainsString( 'download', $result );
	}

	/**
	 * Test toolbar contains fullscreen button.
	 */
	public function test_toolbar_has_fullscreen_button() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-fullscreen-btn', $result );
	}

	/**
	 * Test display_exelearning with empty attributes.
	 */
	public function test_display_exelearning_empty_attributes() {
		$result = $this->shortcodes->display_exelearning( array() );

		$this->assertStringContainsString( 'exelearning-error', $result );
	}

	/**
	 * Test display_exelearning with string ID.
	 */
	public function test_display_exelearning_with_string_id() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => (string) $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
	}

	/**
	 * Test display_exelearning with negative ID.
	 */
	public function test_display_exelearning_with_negative_id() {
		$result = $this->shortcodes->display_exelearning( array( 'id' => -5 ) );

		$this->assertStringContainsString( 'exelearning-error', $result );
	}

	/**
	 * Test iframe has allow-popups in sandbox.
	 */
	public function test_iframe_sandbox_allows_popups() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'allow-popups', $result );
	}

	/**
	 * Test wrapper has exelearning-shortcode class.
	 */
	public function test_wrapper_has_shortcode_class() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-shortcode', $result );
	}

	/**
	 * Test preview wrapper has exelearning-preview class.
	 */
	public function test_preview_wrapper_has_class() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'exelearning-preview', $result );
	}

	/**
	 * Test register_shortcodes method exists.
	 */
	public function test_register_shortcodes_exists() {
		$this->assertTrue( method_exists( $this->shortcodes, 'register_shortcodes' ) );
	}

	/**
	 * Test display_exelearning method exists.
	 */
	public function test_display_exelearning_exists() {
		$this->assertTrue( method_exists( $this->shortcodes, 'display_exelearning' ) );
	}

	/**
	 * Create an attachment with a previewable extraction directory.
	 *
	 * @param string $hash            Extraction hash (40 hex chars).
	 * @param bool   $with_screenshot Whether to also write a screenshot.png fixture.
	 * @return int Attachment ID.
	 */
	private function create_previewable_attachment( $hash, $with_screenshot = false ) {
		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		if ( $with_screenshot ) {
			$upload_dir = wp_upload_dir();
			$dir        = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash;
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/screenshot.png', 'PNG' ); // phpcs:ignore
		}

		return $attachment_id;
	}

	/**
	 * Test teacher_mode is off by default (no activation script injected).
	 */
	public function test_teacher_mode_off_by_default() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '1', 40 ) );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'mode-teacher', $result );
		$this->assertStringNotContainsString( 'exeTeacherMode', $result );
	}

	/**
	 * Test teacher_mode="1" injects the teacher-mode activation script.
	 */
	public function test_teacher_mode_activation() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '2', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'           => $attachment_id,
				'teacher_mode' => '1',
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringContainsString( 'mode-teacher', $result );
		$this->assertStringContainsString( 'exeTeacherMode', $result );
	}

	/**
	 * Test screenshot="only" renders just the image and no iframe.
	 */
	public function test_screenshot_only_renders_image_without_iframe() {
		$hash          = str_repeat( '3', 40 );
		$attachment_id = $this->create_previewable_attachment( $hash, true );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'screenshot' => 'only',
			)
		);

		$this->assertStringContainsString( '<img', $result );
		$this->assertStringContainsString( 'screenshot.png', $result );
		$this->assertStringContainsString( $hash, $result );
		$this->assertStringNotContainsString( '<iframe', $result );
	}

	/**
	 * Test screenshot="poster" renders the image and wires click-to-load to the proxy iframe.
	 */
	public function test_screenshot_poster_renders_image_and_proxy_wiring() {
		$hash          = str_repeat( '4', 40 );
		$attachment_id = $this->create_previewable_attachment( $hash, true );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'screenshot' => 'poster',
			)
		);

		$this->assertStringContainsString( 'screenshot.png', $result );
		$this->assertStringContainsString( 'exelearning-poster', $result );
		// The proxy URL must be available so the embed can load on click.
		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
	}

	/**
	 * Test screenshot="only" falls back to the iframe embed when no screenshot exists.
	 */
	public function test_screenshot_only_falls_back_without_screenshot() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '5', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'screenshot' => 'only',
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'screenshot.png', $result );
	}

	/**
	 * Test screenshot="poster" falls back to the iframe embed when no screenshot exists.
	 */
	public function test_screenshot_poster_falls_back_without_screenshot() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '6', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'screenshot' => 'poster',
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exelearning-poster', $result );
	}

	/**
	 * Test screenshot="no" (default) keeps the current iframe behavior.
	 */
	public function test_screenshot_no_keeps_iframe() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '7', 40 ), true );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'screenshot' => 'no',
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exelearning-poster', $result );
	}
}
