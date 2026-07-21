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
	 * Test the toolbar does not render the fullscreen button by default (opt-in).
	 */
	public function test_toolbar_has_no_fullscreen_button_by_default() {
		$attachment_id = $this->factory->attachment->create();
		$hash          = str_repeat( 'a', 40 );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
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
	 * By default the teacher layer selector is NOT available: the package keeps
	 * teacher-only content hidden and no ?exe-teacher parameter is appended.
	 */
	public function test_teacher_selector_hidden_by_default() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '1', 40 ) );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exe-teacher', $result );
		$this->assertStringNotContainsString( 'mode-teacher', $result );
		$this->assertStringNotContainsString( 'exeTeacherMode', $result );
		$this->assertStringNotContainsString( 'teacher-mode-toggler-wrapper', $result );
	}

	/**
	 * teacher_mode_visible="1" makes the selector available, carried on the
	 * iframe src via ?exe-teacher=1, and no CSS/JS is injected into the package.
	 */
	public function test_teacher_selector_available_when_visibility_on() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '1', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'                   => $attachment_id,
				'teacher_mode_visible' => '1',
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringContainsString( 'exe-teacher=1', $result );
		$this->assertStringNotContainsString( 'mode-teacher', $result );
		$this->assertStringNotContainsString( 'exeTeacherMode', $result );
		$this->assertStringNotContainsString( 'teacher-mode-toggler-wrapper', $result );
	}

	/**
	 * teacher_mode_visible="0" hides the selector: no ?exe-teacher parameter is
	 * appended and nothing is injected into the package.
	 */
	public function test_teacher_selector_hidden_when_visibility_off() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '2', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'                   => $attachment_id,
				'teacher_mode_visible' => '0',
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exe-teacher', $result );
		$this->assertStringNotContainsString( 'teacher-mode-toggler-wrapper', $result );
	}

	/**
	 * teacher_mode="1" offers the selector through ?exe-teacher=1 even when
	 * teacher_mode_visible is off (it no longer auto-reveals via injected JS).
	 */
	public function test_teacher_mode_attr_offers_selector() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( '3', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'                   => $attachment_id,
				'teacher_mode'         => '1',
				'teacher_mode_visible' => '0',
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringContainsString( 'exe-teacher=1', $result );
		$this->assertStringNotContainsString( 'mode-teacher', $result );
		$this->assertStringNotContainsString( 'exeTeacherMode', $result );
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

	// ---------------------------------------------------------------------
	// Fullscreen attribute.
	// ---------------------------------------------------------------------

	/**
	 * Render a previewable embed with a given fullscreen attribute and assert
	 * whether the fullscreen button is present, and that the raw value is never
	 * leaked into the markup.
	 *
	 * @param mixed $value    Shortcode fullscreen attribute value.
	 * @param bool  $expected Whether the fullscreen button should render.
	 */
	private function assert_fullscreen_rendered( $value, $expected ) {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'fullscreen' => $value,
			)
		);

		$this->assertStringContainsString( '<iframe', $result );
		if ( $expected ) {
			$this->assertStringContainsString( 'exelearning-fullscreen-btn', $result );
		} else {
			$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
		}
		// Unknown/unsafe values must never be echoed into the output.
		$this->assertStringNotContainsString( 'alert(1)', $result );
	}

	/**
	 * The default embed does NOT render the fullscreen button (opt-in).
	 */
	public function test_fullscreen_hidden_by_default() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( '<iframe', $result );
		$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
	}

	/**
	 * fullscreen="1" renders the fullscreen button.
	 */
	public function test_fullscreen_value_1() {
		$this->assert_fullscreen_rendered( '1', true );
	}

	/**
	 * fullscreen="true" renders the fullscreen button.
	 */
	public function test_fullscreen_value_true() {
		$this->assert_fullscreen_rendered( 'true', true );
	}

	/**
	 * fullscreen="yes" renders the fullscreen button.
	 */
	public function test_fullscreen_value_yes() {
		$this->assert_fullscreen_rendered( 'yes', true );
	}

	/**
	 * fullscreen="on" renders the fullscreen button.
	 */
	public function test_fullscreen_value_on() {
		$this->assert_fullscreen_rendered( 'on', true );
	}

	/**
	 * fullscreen="ON" (mixed case) renders the fullscreen button.
	 */
	public function test_fullscreen_value_mixed_case() {
		$this->assert_fullscreen_rendered( 'ON', true );
	}

	/**
	 * fullscreen="0" hides the fullscreen button.
	 */
	public function test_fullscreen_value_0() {
		$this->assert_fullscreen_rendered( '0', false );
	}

	/**
	 * fullscreen="false" hides the fullscreen button.
	 */
	public function test_fullscreen_value_false() {
		$this->assert_fullscreen_rendered( 'false', false );
	}

	/**
	 * fullscreen="no" hides the fullscreen button.
	 */
	public function test_fullscreen_value_no() {
		$this->assert_fullscreen_rendered( 'no', false );
	}

	/**
	 * fullscreen="off" hides the fullscreen button.
	 */
	public function test_fullscreen_value_off() {
		$this->assert_fullscreen_rendered( 'off', false );
	}

	/**
	 * Unknown fullscreen values fall back to the (hidden) default safely.
	 */
	public function test_fullscreen_unknown_value_defaults_to_hidden() {
		$this->assert_fullscreen_rendered( 'unexpected', false );
	}

	/**
	 * A script payload as fullscreen value must not be injected and must fall
	 * back to the hidden default.
	 */
	public function test_fullscreen_script_payload_is_safe() {
		$this->assert_fullscreen_rendered( '<script>alert(1)</script>', false );
	}

	/**
	 * When fullscreen is disabled no fullscreen behavior script is emitted, but
	 * poster click-to-load still works.
	 */
	public function test_fullscreen_off_keeps_poster_without_fullscreen_script() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'b', 40 ), true );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'screenshot' => 'poster',
				'fullscreen' => '0',
			)
		);

		$this->assertStringContainsString( 'exelearning-poster', $result );
		$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
		$this->assertStringNotContainsString( 'requestFullscreen', $result );
		// Poster wiring must still be present.
		$this->assertStringContainsString( 'data-src', $result );
	}

	/**
	 * screenshot="poster" + fullscreen="1" must activate the deferred preview
	 * before requesting fullscreen, so the button never expands a hidden,
	 * srcless iframe when pressed before the poster is clicked.
	 */
	public function test_fullscreen_with_poster_activates_preview_first() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'c', 40 ), true );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'screenshot' => 'poster',
				'fullscreen' => '1',
			)
		);

		$this->assertStringContainsString( 'exelearning-poster', $result );
		$this->assertStringContainsString( 'exelearning-fullscreen-btn', $result );
		// The shared activation helper is defined and invoked from fullscreen.
		$this->assertStringContainsString( 'function activatePreview()', $result );

		$click_pos    = strpos( $result, 'btn.addEventListener' );
		$activate_pos = strpos( $result, 'activatePreview();' );
		$request_pos  = strpos( $result, 'if (iframe.requestFullscreen)' );

		$this->assertNotFalse( $click_pos, 'Fullscreen click handler must be wired.' );
		$this->assertNotFalse( $activate_pos, 'Fullscreen must invoke activatePreview().' );
		$this->assertNotFalse( $request_pos, 'Fullscreen must still request fullscreen.' );
		// activatePreview() runs inside the click handler, before requesting fullscreen.
		$this->assertGreaterThan( $click_pos, $activate_pos );
		$this->assertLessThan( $request_pos, $activate_pos );
	}

	// ---------------------------------------------------------------------
	// Height sanitization / percentages.
	// ---------------------------------------------------------------------

	/**
	 * Render a previewable embed with a given height attribute.
	 *
	 * @param mixed $height Shortcode height attribute value.
	 * @return string Rendered HTML.
	 */
	private function render_with_height( $height ) {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		return $this->shortcodes->display_exelearning(
			array(
				'id'     => $attachment_id,
				'height' => $height,
			)
		);
	}

	/**
	 * height="800" is normalized to "800px".
	 */
	public function test_height_bare_pixels_get_px_suffix() {
		$this->assertStringContainsString( 'height: 800px', $this->render_with_height( '800' ) );
	}

	/**
	 * height="800px" is kept as "800px".
	 */
	public function test_height_pixels_with_suffix_preserved() {
		$this->assertStringContainsString( 'height: 800px', $this->render_with_height( '800px' ) );
	}

	/**
	 * height="75%" is preserved as a percentage.
	 */
	public function test_height_percentage_75() {
		$this->assertStringContainsString( 'height: 75%', $this->render_with_height( '75%' ) );
	}

	/**
	 * height="100%" is preserved as a percentage.
	 */
	public function test_height_percentage_100() {
		$this->assertStringContainsString( 'height: 100%', $this->render_with_height( '100%' ) );
	}

	/**
	 * height="0" falls back to the default 600px.
	 */
	public function test_height_zero_falls_back() {
		$result = $this->render_with_height( '0' );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'height: 0px', $result );
	}

	/**
	 * height="-1" falls back to the default 600px.
	 */
	public function test_height_negative_falls_back() {
		$result = $this->render_with_height( -1 );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'height: -1', $result );
	}

	/**
	 * A javascript: URL as height is rejected and never leaked.
	 */
	public function test_height_javascript_rejected() {
		$result = $this->render_with_height( 'javascript:alert(1)' );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'javascript:', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result );
	}

	/**
	 * A CSS-injection height is rejected and never leaked.
	 */
	public function test_height_css_injection_rejected() {
		$result = $this->render_with_height( '100%;background:red' );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'background:red', $result );
		$this->assertStringNotContainsString( '100%;background', $result );
	}

	/**
	 * A script payload as height is rejected and never leaked.
	 */
	public function test_height_script_payload_rejected() {
		$result = $this->render_with_height( '<script>alert(1)</script>' );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result );
	}

	/**
	 * calc() heights are out of scope and rejected.
	 */
	public function test_height_calc_rejected() {
		$result = $this->render_with_height( 'calc(100% - 20px)' );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'calc(', $result );
	}

	/**
	 * var() heights are out of scope and rejected.
	 */
	public function test_height_var_rejected() {
		$result = $this->render_with_height( 'var(--x)' );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'var(--x)', $result );
	}

	/**
	 * Viewport units are out of scope and rejected.
	 */
	public function test_height_viewport_units_rejected() {
		$result = $this->render_with_height( '100vh' );
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( '100vh', $result );
	}

	// ---------------------------------------------------------------------
	// Width sanitization / percentages.
	// ---------------------------------------------------------------------

	/**
	 * Render a previewable embed with a given width attribute.
	 *
	 * @param mixed $width Shortcode width attribute value.
	 * @return string Rendered HTML.
	 */
	private function render_with_width( $width ) {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		return $this->shortcodes->display_exelearning(
			array(
				'id'    => $attachment_id,
				'width' => $width,
			)
		);
	}

	/**
	 * The default width is 100% (applied to the embed box).
	 */
	public function test_default_width_is_full() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertStringContainsString( 'width: 100%; max-width: 100%', $result );
	}

	/**
	 * width="800" is normalized to "800px" on the embed box.
	 */
	public function test_width_bare_pixels_get_px_suffix() {
		$this->assertStringContainsString( 'width: 800px', $this->render_with_width( '800' ) );
	}

	/**
	 * width="75%" is preserved as a percentage.
	 */
	public function test_width_percentage() {
		$this->assertStringContainsString( 'width: 75%', $this->render_with_width( '75%' ) );
	}

	/**
	 * width="0" falls back to the default 100%.
	 */
	public function test_width_zero_falls_back() {
		$result = $this->render_with_width( '0' );
		$this->assertStringContainsString( 'width: 100%; max-width: 100%', $result );
		$this->assertStringNotContainsString( 'width: 0', $result );
	}

	/**
	 * A javascript: URL as width is rejected and never leaked.
	 */
	public function test_width_javascript_rejected() {
		$result = $this->render_with_width( 'javascript:alert(1)' );
		$this->assertStringContainsString( 'width: 100%; max-width: 100%', $result );
		$this->assertStringNotContainsString( 'javascript:', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result );
	}

	// ---------------------------------------------------------------------
	// Toolbar HTML / accessibility.
	// ---------------------------------------------------------------------

	/**
	 * The default embed's Download and Fullscreen controls both use the shared
	 * .exelearning-toolbar-btn class.
	 */
	public function test_download_and_fullscreen_share_toolbar_btn_class() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'fullscreen' => '1',
			)
		);

		$this->assertGreaterThanOrEqual(
			2,
			substr_count( $result, 'exelearning-toolbar-btn' )
		);
	}

	/**
	 * The fullscreen control is a real button with type="button".
	 */
	public function test_fullscreen_button_has_type_button() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'fullscreen' => '1',
			)
		);

		$this->assertMatchesRegularExpression(
			'/<button type="button"[^>]*exelearning-fullscreen-btn/',
			$result
		);
	}

	/**
	 * The Download control exposes an accessible name via aria-label.
	 */
	public function test_download_fallback_has_accessible_name() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		$this->assertMatchesRegularExpression(
			'/<a[^>]*exelearning-toolbar-btn[^>]*aria-label="/',
			$result
		);
	}

	/**
	 * The Fullscreen control exposes an accessible name via aria-label.
	 */
	public function test_fullscreen_button_has_accessible_name() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'fullscreen' => '1',
			)
		);

		$this->assertMatchesRegularExpression(
			'/exelearning-fullscreen-btn"[^>]*aria-label="/',
			$result
		);
	}

	/**
	 * Decorative dashicon spans are hidden from assistive technology.
	 */
	public function test_toolbar_icons_are_aria_hidden() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'         => $attachment_id,
				'fullscreen' => '1',
			)
		);

		$this->assertStringContainsString(
			'<span class="dashicons dashicons-download" aria-hidden="true">',
			$result
		);
		$this->assertStringContainsString(
			'<span class="dashicons dashicons-fullscreen-alt" aria-hidden="true">',
			$result
		);
	}

	/**
	 * show_download="1" and fullscreen="1" render both controls.
	 */
	public function test_show_download_and_fullscreen_render_both() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'            => $attachment_id,
				'show_download' => '1',
				'fullscreen'    => '1',
			)
		);

		$this->assertStringContainsString( 'class="exelearning-download"', $result );
		$this->assertStringContainsString( 'exelearning-fullscreen-btn', $result );
	}

	/**
	 * show_download="1" and fullscreen="0" render Download but not Fullscreen.
	 */
	public function test_show_download_without_fullscreen() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'            => $attachment_id,
				'show_download' => '1',
				'fullscreen'    => '0',
			)
		);

		$this->assertStringContainsString( 'class="exelearning-download"', $result );
		$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
	}

	/**
	 * show_download="0" keeps the fallback download control alongside fullscreen.
	 */
	public function test_fallback_download_with_fullscreen() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$result = $this->shortcodes->display_exelearning(
			array(
				'id'            => $attachment_id,
				'show_download' => '0',
				'fullscreen'    => '1',
			)
		);

		$this->assertMatchesRegularExpression(
			'/<a[^>]*exelearning-toolbar-btn[^>]*download/',
			$result
		);
		$this->assertStringContainsString( 'exelearning-fullscreen-btn', $result );
	}

	/**
	 * The final rendered HTML remains filterable through
	 * exelearning_shortcode_output.
	 */
	public function test_shortcode_output_is_filterable() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$callback = static function ( $html ) {
			return '<!--exe-wrapped-->' . $html;
		};
		add_filter( 'exelearning_shortcode_output', $callback );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		remove_filter( 'exelearning_shortcode_output', $callback );

		$this->assertStringContainsString( '<!--exe-wrapped-->', $result );
	}

	/**
	 * Attributes remain filterable through exelearning_shortcode_atts, but the
	 * renderer re-sanitizes the filtered values (height + fullscreen).
	 */
	public function test_shortcode_atts_are_resanitized_after_filter() {
		$attachment_id = $this->create_previewable_attachment( str_repeat( 'a', 40 ) );

		$callback = static function ( $atts ) {
			$atts['height']     = 'javascript:alert(1)';
			$atts['fullscreen'] = '0';
			return $atts;
		};
		add_filter( 'exelearning_shortcode_atts', $callback );

		$result = $this->shortcodes->display_exelearning( array( 'id' => $attachment_id ) );

		remove_filter( 'exelearning_shortcode_atts', $callback );

		// Malicious height from the filter is re-sanitized to the safe default.
		$this->assertStringContainsString( 'height: 600px', $result );
		$this->assertStringNotContainsString( 'javascript:', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result );
		// Fullscreen toggled off through the filter is honored.
		$this->assertStringNotContainsString( 'exelearning-fullscreen-btn', $result );
	}
}
