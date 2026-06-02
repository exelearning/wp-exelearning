<?php
/**
 * Tests for ExeLearning_Hooks class.
 *
 * @package Exelearning
 */

/**
 * Class HooksTest.
 *
 * @covers ExeLearning_Hooks
 */
class HooksTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Hooks
	 */
	private $hooks;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->hooks = new ExeLearning_Hooks();
	}

	/**
	 * Test register_hooks method exists and is callable.
	 */
	public function test_register_hooks_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'register_hooks' ) );
	}

	/**
	 * Test register_hooks does not register the legacy public CPT.
	 *
	 * The boilerplate `exelearning` custom post type was removed: the plugin
	 * works with attachments only and must not expose a public post type.
	 */
	public function test_register_hooks_does_not_register_cpt() {
		if ( post_type_exists( 'exelearning' ) ) {
			unregister_post_type( 'exelearning' );
		}

		// register_hooks() must not register any init callback that creates the CPT.
		// (We avoid firing do_action('init') here because that would re-run the
		// whole init chain, including block registration, and trip WP's
		// already-registered incorrect-usage notice.)
		$this->hooks->register_hooks();

		$this->assertFalse( post_type_exists( 'exelearning' ) );
		$this->assertFalse( has_action( 'init', array( $this->hooks, 'register_custom_post_type' ) ) );
	}

	/**
	 * Test register_hooks does not add a the_content filter.
	 */
	public function test_register_hooks_adds_no_content_filter() {
		$this->hooks->register_hooks();

		$this->assertFalse( has_filter( 'the_content', array( $this->hooks, 'modify_content' ) ) );
	}

	/**
	 * Test the removed boilerplate methods no longer exist.
	 */
	public function test_legacy_methods_removed() {
		$this->assertFalse( method_exists( $this->hooks, 'register_custom_post_type' ) );
		$this->assertFalse( method_exists( $this->hooks, 'modify_content' ) );
	}
}
