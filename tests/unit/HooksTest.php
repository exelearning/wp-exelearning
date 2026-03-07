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
	 * Test register_hooks adds init action.
	 */
	public function test_register_hooks_adds_init_action() {
		$this->hooks->register_hooks();

		$this->assertGreaterThan(
			0,
			has_action( 'init', array( $this->hooks, 'register_custom_post_type' ) )
		);
	}

	/**
	 * Test register_hooks adds content filter.
	 */
	public function test_register_hooks_adds_content_filter() {
		$this->hooks->register_hooks();

		$this->assertGreaterThan(
			0,
			has_filter( 'the_content', array( $this->hooks, 'modify_content' ) )
		);
	}

	/**
	 * Test modify_content returns content unchanged.
	 */
	public function test_modify_content_returns_unchanged() {
		$content = '<p>Test content</p>';

		$result = $this->hooks->modify_content( $content );

		$this->assertEquals( $content, $result );
	}

	/**
	 * Test modify_content handles empty content.
	 */
	public function test_modify_content_handles_empty() {
		$result = $this->hooks->modify_content( '' );

		$this->assertEquals( '', $result );
	}

	/**
	 * Test register_custom_post_type method exists.
	 */
	public function test_register_custom_post_type_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'register_custom_post_type' ) );
	}

	/**
	 * Test register_hooks method exists.
	 */
	public function test_register_hooks_exists() {
		$this->assertTrue( method_exists( $this->hooks, 'register_hooks' ) );
	}

	/**
	 * Test register_custom_post_type registers the post type.
	 */
	public function test_register_custom_post_type_registers_post_type() {
		// Remove post type if already registered.
		if ( post_type_exists( 'exelearning' ) ) {
			unregister_post_type( 'exelearning' );
		}

		$this->hooks->register_custom_post_type();

		$this->assertTrue( post_type_exists( 'exelearning' ) );
	}

	/**
	 * Test post type has correct label.
	 */
	public function test_post_type_has_correct_label() {
		// Unregister and re-register to test.
		if ( post_type_exists( 'exelearning' ) ) {
			unregister_post_type( 'exelearning' );
		}

		$this->hooks->register_custom_post_type();

		$post_type = get_post_type_object( 'exelearning' );
		$this->assertEquals( 'eXeLearning', $post_type->label );
	}

	/**
	 * Test post type is public.
	 */
	public function test_post_type_is_public() {
		if ( post_type_exists( 'exelearning' ) ) {
			unregister_post_type( 'exelearning' );
		}

		$this->hooks->register_custom_post_type();

		$post_type = get_post_type_object( 'exelearning' );
		$this->assertTrue( $post_type->public );
	}

	/**
	 * Test post type shows in menu.
	 */
	public function test_post_type_shows_in_menu() {
		if ( post_type_exists( 'exelearning' ) ) {
			unregister_post_type( 'exelearning' );
		}

		$this->hooks->register_custom_post_type();

		$post_type = get_post_type_object( 'exelearning' );
		$this->assertTrue( $post_type->show_in_menu );
	}

	/**
	 * Test modify_content with HTML content.
	 */
	public function test_modify_content_with_html() {
		$content = '<div><p>Some HTML content</p></div>';
		$result  = $this->hooks->modify_content( $content );

		$this->assertEquals( $content, $result );
	}

	/**
	 * Test modify_content with special characters.
	 */
	public function test_modify_content_with_special_chars() {
		$content = 'Content with <script>alert("test")</script>';
		$result  = $this->hooks->modify_content( $content );

		$this->assertEquals( $content, $result );
	}
}
