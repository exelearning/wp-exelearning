<?php
/**
 * Tests for ExeLearning_Post_Types class.
 *
 * @package Exelearning
 */

/**
 * Class PostTypesTest.
 *
 * @covers ExeLearning_Post_Types
 */
class PostTypesTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Post_Types
	 */
	private $post_types;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->post_types = new ExeLearning_Post_Types();
	}

	/**
	 * Test register_post_types method exists.
	 */
	public function test_register_post_types_exists() {
		$this->assertTrue( method_exists( $this->post_types, 'register_post_types' ) );
	}

	/**
	 * The plugin deliberately registers no custom post type: ELP packages are
	 * WordPress attachments and their metadata lives in attachment post meta.
	 * This guards that decision — registering one here would silently change
	 * where content is stored.
	 */
	public function test_register_post_types_registers_no_custom_post_type() {
		$before = get_post_types( array(), 'names' );

		$this->post_types->register_post_types();

		$this->assertSame(
			array(),
			array_values( array_diff( get_post_types( array(), 'names' ), $before ) ),
			'register_post_types() must not register any post type.'
		);
		$this->assertFalse( post_type_exists( 'exelearning' ) );
	}
}
