<?php
/**
 * Tests for ExeLearning_Admin_Upload class.
 *
 * @package Exelearning
 */

/**
 * Class AdminUploadTest.
 *
 * @covers ExeLearning_Admin_Upload
 */
class AdminUploadTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Admin_Upload
	 */
	private $upload;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->upload = new ExeLearning_Admin_Upload();
	}

	/**
	 * Test constructor registers action.
	 */
	public function test_constructor_registers_action() {
		$upload = new ExeLearning_Admin_Upload();

		$this->assertGreaterThan(
			0,
			has_action( 'admin_post_exelearning_upload', array( $upload, 'handle_upload' ) )
		);
	}

	/**
	 * Test handle_upload method exists.
	 */
	public function test_handle_upload_exists() {
		$this->assertTrue( method_exists( $this->upload, 'handle_upload' ) );
	}

	/**
	 * Test handle_upload is public.
	 */
	public function test_handle_upload_is_public() {
		$method = new ReflectionMethod( ExeLearning_Admin_Upload::class, 'handle_upload' );
		$this->assertTrue( $method->isPublic() );
	}
}
