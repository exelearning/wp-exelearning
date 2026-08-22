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

	public function set_up() {
		parent::set_up();
		$this->reset_scripts();
	}

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
	 * The same-origin admin mode was removed: a leftover option=legacy is ignored and the
	 * iframe still renders secure (no silent downgrade). The escape hatch is off by default.
	 */
	public function test_legacy_option_is_ignored() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, 'legacy' );

		$this->assertFalse( ExeLearning_Iframe_Sandbox::is_unsafe_legacy() );
		$this->assertSame( 'secure', ExeLearning_Iframe_Sandbox::mode() );
		$this->assertTrue( ExeLearning_Iframe_Sandbox::is_secure() );
		$this->assertStringNotContainsString( 'allow-same-origin', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
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
	 * With no option set, the embed policy defaults to strict (DEC-0061): only the maintained
	 * providers are promoted; open is an explicit opt-in.
	 */
	public function test_default_embed_mode_is_strict() {
		$this->assertSame( 'strict', ExeLearning_Iframe_Sandbox::embed_mode() );
		$this->assertSame(
			ExeLearning_Iframe_Sandbox::EMBED_STRICT,
			ExeLearning_Iframe_Sandbox::embed_mode()
		);
	}

	/**
	 * Setting the embed policy to open is honored (explicit opt-in).
	 */
	public function test_embed_mode_open_is_opt_in() {
		update_option( ExeLearning_Iframe_Sandbox::EMBED_OPTION, 'open' );

		$this->assertSame( 'open', ExeLearning_Iframe_Sandbox::embed_mode() );
	}

	/**
	 * The CSP profile defaults to strict and is filterable to the documented-weaker compatible.
	 */
	public function test_csp_profile_defaults_strict_and_is_filterable() {
		$this->assertSame( 'strict', ExeLearning_Iframe_Sandbox::csp_profile() );
		$callback = function () {
			return ExeLearning_Iframe_Sandbox::CSP_COMPATIBLE;
		};
		add_filter( 'exelearning_csp_profile', $callback );
		$this->assertSame( 'compatible', ExeLearning_Iframe_Sandbox::csp_profile() );
		remove_filter( 'exelearning_csp_profile', $callback );
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
	 * The external-media bundle is enqueued: the iframe always renders secure, so external
	 * media is always promoted to the parent.
	 */
	public function test_enqueue_embed_relay_in_secure() {
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();
		$this->assertTrue( wp_script_is( ExeLearning_Iframe_Sandbox::HANDLE_BUNDLE, 'enqueued' ) );
	}

	/**
	 * The bundle carries both halves, so enqueueing them both must not emit the relay's
	 * init twice — two `exeEmbedRelay.init()` calls on one page would install a second
	 * set of listeners and a second drift timer.
	 */
	public function test_relay_init_is_emitted_once() {
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();
		ExeLearning_Iframe_Sandbox::enqueue_media_host();
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();

		$inline = wp_scripts()->get_data( ExeLearning_Iframe_Sandbox::HANDLE_BUNDLE, 'after' );
		$joined = is_array( $inline ) ? implode( "\n", $inline ) : (string) $inline;

		$this->assertSame( 1, substr_count( $joined, 'exeEmbedRelay.init(' ) );
	}


	/**
	 * The media host must be attached ONCE per page, however many callers ask for it.
	 *
	 * It was not guarded at all, and every caller appended another copy of the same
	 * attach loop: the block editor screen shipped 38 of them. Harmless-looking, but it is
	 * 38 scans of the DOM on load and 38 chances for the next person to conclude the
	 * wiring is more complicated than it is.
	 */
	public function test_media_host_attaches_once_however_many_callers_ask() {
		ExeLearning_Iframe_Sandbox::enqueue_media_host();
		ExeLearning_Iframe_Sandbox::enqueue_media_host();
		ExeLearning_Iframe_Sandbox::enqueue_media_host();

		$after = implode( ' ', (array) wp_scripts()->get_data( 'exelearning-external-media', 'after' ) );

		$this->assertSame( 1, substr_count( $after, 'exeMediaHost.attach' ) );
	}

	/**
	 * Idempotency must come from what the page ACTUALLY carries, not from a latch.
	 *
	 * The old guard was a private static set before the line was attached, so it lied in
	 * two directions: a caller that failed to attach still marked the page done and locked
	 * out the real one, and the flag survived from one test to the next inside the same PHP
	 * process, making whichever test ran first the only one that could ever see an init.
	 */
	public function test_relay_init_survives_a_caller_that_ran_before_the_bundle_existed() {
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();

		$after = implode( ' ', (array) wp_scripts()->get_data( 'exelearning-external-media', 'after' ) );

		$this->assertSame( 1, substr_count( $after, 'exeEmbedRelay.init' ) );
	}

	/**
	 * Start each test with an empty script registry.
	 *
	 * WP_UnitTestCase keeps one $wp_scripts for the whole process, so inline data piles up
	 * across tests: whichever test enqueues first makes every later assertion about "is
	 * this on the page?" true for free. Three tests in this file were passing on exactly
	 * that.
	 */
	private function reset_scripts() {
		$GLOBALS['wp_scripts'] = null;
	}
}
