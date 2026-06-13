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
		$this->assertSame( 'allow-scripts allow-popups', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
		$this->assertStringNotContainsString( 'allow-same-origin', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
	}

	/**
	 * Legacy mode restores the same-origin token.
	 */
	public function test_legacy_mode() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, 'legacy' );

		$this->assertSame( 'legacy', ExeLearning_Iframe_Sandbox::mode() );
		$this->assertFalse( ExeLearning_Iframe_Sandbox::is_secure() );
		$this->assertStringContainsString( 'allow-same-origin', ExeLearning_Iframe_Sandbox::sandbox_tokens() );
	}

	/**
	 * Any value other than "legacy" fails safe to secure.
	 */
	public function test_invalid_mode_falls_back_to_secure() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, 'garbage' );

		$this->assertSame( 'secure', ExeLearning_Iframe_Sandbox::mode() );
		$this->assertTrue( ExeLearning_Iframe_Sandbox::is_secure() );
	}
}
