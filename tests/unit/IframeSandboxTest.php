<?php
/**
 * Tests for ExeLearning_Iframe_Sandbox.
 *
 * @package Exelearning
 */

/**
 * Class IframeSandboxTest.
 *
 * @covers ExeLearning_Iframe_Sandbox
 */
class IframeSandboxTest extends WP_UnitTestCase {

	/**
	 * With no option set, the mode is secure and the tokens omit allow-same-origin.
	 */
	public function test_default_mode_is_secure() {
		$this->assertSame( 'secure', ExeLearning_Iframe_Sandbox::mode() );
		$this->assertTrue( ExeLearning_Iframe_Sandbox::is_secure() );
		$this->assertSame( 'allow-scripts allow-popups allow-forms', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
		$this->assertStringNotContainsString( 'allow-same-origin', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
		// allow-forms is required so the form-based iDevices can submit in the sandbox.
		$this->assertStringContainsString( 'allow-forms', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
	}

	/**
	 * Legacy mode restores the same-origin token.
	 */
	public function test_legacy_mode() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, 'legacy' );

		$this->assertSame( 'legacy', ExeLearning_Iframe_Sandbox::mode() );
		$this->assertFalse( ExeLearning_Iframe_Sandbox::is_secure() );
		$this->assertStringContainsString( 'allow-same-origin', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
		$this->assertStringContainsString( 'allow-forms', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
		$this->assertStringContainsString( 'allow-popups-to-escape-sandbox', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
	}

	/**
	 * Any value other than "legacy" fails safe to secure.
	 */
	public function test_invalid_mode_falls_back_to_secure() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, 'garbage' );

		$this->assertSame( 'secure', ExeLearning_Iframe_Sandbox::mode() );
		$this->assertTrue( ExeLearning_Iframe_Sandbox::is_secure() );
	}

	/**
	 * With no option set, the embed policy defaults to open (DEC-0061): any
	 * cross-origin https iframe is promoted, no host list required.
	 */
	public function test_default_embed_mode_is_open() {
		$this->assertSame( 'open', ExeLearning_Iframe_Sandbox::embed_mode() );
		$this->assertSame(
			ExeLearning_Iframe_Sandbox::EMBED_OPEN,
			ExeLearning_Iframe_Sandbox::embed_mode()
		);
	}

	/**
	 * Setting the embed policy to strict is honored.
	 */
	public function test_embed_mode_strict() {
		update_option( ExeLearning_Iframe_Sandbox::EMBED_OPTION, 'strict' );

		$this->assertSame( 'strict', ExeLearning_Iframe_Sandbox::embed_mode() );
		$this->assertSame(
			ExeLearning_Iframe_Sandbox::EMBED_STRICT,
			ExeLearning_Iframe_Sandbox::embed_mode()
		);
	}

	/**
	 * Any value other than "open" fails safe to strict (toward the more restrictive
	 * policy), so a tampered option never silently weakens the gate.
	 */
	public function test_invalid_embed_mode_falls_back_to_strict() {
		update_option( ExeLearning_Iframe_Sandbox::EMBED_OPTION, 'garbage' );

		$this->assertSame( 'strict', ExeLearning_Iframe_Sandbox::embed_mode() );
	}

	/**
	 * The default whitelist covers the YouTube and Vimeo embed hosts.
	 */
	public function test_embed_whitelist_contains_default_video_hosts() {
		$hosts = ExeLearning_Iframe_Sandbox::embed_whitelist();

		$this->assertContains( 'www.youtube.com', $hosts );
		$this->assertContains( 'youtube-nocookie.com', $hosts );
		$this->assertContains( 'player.vimeo.com', $hosts );
		$this->assertContains( 'www.dailymotion.com', $hosts );
		$this->assertContains( 'mediateca.educa.madrid.org', $hosts );
	}

	/**
	 * The whitelist is filterable and the result is lowercased, trimmed and de-duplicated.
	 */
	public function test_embed_whitelist_is_filterable_and_normalized() {
		$callback = function ( $hosts ) {
			$hosts[] = '  Example.ORG  ';
			$hosts[] = 'player.vimeo.com'; // Duplicate of a default.
			return $hosts;
		};
		add_filter( 'exelearning_embed_whitelist', $callback );
		$hosts = ExeLearning_Iframe_Sandbox::embed_whitelist();
		remove_filter( 'exelearning_embed_whitelist', $callback );

		$this->assertContains( 'example.org', $hosts );
		$this->assertNotContains( '  Example.ORG  ', $hosts );
		$this->assertSame( array_values( array_unique( $hosts ) ), $hosts );
	}

	/**
	 * The embed relay is enqueued in secure mode only.
	 */
	public function test_enqueue_embed_relay_only_in_secure() {
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();
		$this->assertTrue( wp_script_is( ExeLearning_Iframe_Sandbox::HANDLE_RELAY, 'enqueued' ) );

		// Reset, switch to legacy, and confirm it is not enqueued.
		wp_dequeue_script( ExeLearning_Iframe_Sandbox::HANDLE_RELAY );
		wp_deregister_script( ExeLearning_Iframe_Sandbox::HANDLE_RELAY );
		update_option( ExeLearning_Iframe_Sandbox::OPTION, 'legacy' );

		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();
		$this->assertFalse( wp_script_is( ExeLearning_Iframe_Sandbox::HANDLE_RELAY, 'enqueued' ) );
	}
}
