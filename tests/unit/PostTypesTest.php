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
	 * Test register_post_types can be called.
	 */
	public function test_register_post_types_callable() {
		$this->post_types->register_post_types();
		$this->assertTrue( true );
	}
}
