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
		$this->assertStringContainsString( 'allow-same-origin', $result );
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
}
