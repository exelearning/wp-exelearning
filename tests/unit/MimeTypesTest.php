<?php
/**
 * Tests for ExeLearning_Mime_Types class.
 *
 * @package Exelearning
 */

/**
 * Class MimeTypesTest.
 *
 * @covers ExeLearning_Mime_Types
 */
class MimeTypesTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Mime_Types
	 */
	private $mime_types;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->mime_types = new ExeLearning_Mime_Types();
	}

	/**
	 * Test elpx mime type is added.
	 */
	public function test_elpx_mime_type_registered() {
		$mimes = array();
		$mimes = $this->mime_types->add_elp_mime_type( $mimes );

		$this->assertArrayHasKey( 'elpx', $mimes );
		$this->assertEquals( 'application/zip', $mimes['elpx'] );
	}

	/**
	 * Test existing mime types are preserved.
	 */
	public function test_existing_mime_types_preserved() {
		$mimes = array(
			'jpg' => 'image/jpeg',
			'pdf' => 'application/pdf',
		);
		$mimes = $this->mime_types->add_elp_mime_type( $mimes );

		$this->assertArrayHasKey( 'jpg', $mimes );
		$this->assertArrayHasKey( 'pdf', $mimes );
		$this->assertArrayHasKey( 'elpx', $mimes );
		$this->assertEquals( 'image/jpeg', $mimes['jpg'] );
		$this->assertEquals( 'application/pdf', $mimes['pdf'] );
	}

	/**
	 * Test register_mime_types adds the filters.
	 */
	public function test_register_adds_filters() {
		$this->mime_types->register_mime_types();

		$this->assertGreaterThan(
			0,
			has_filter( 'upload_mimes', array( $this->mime_types, 'add_elp_mime_type' ) )
		);

		$this->assertGreaterThan(
			0,
			has_filter( 'wp_check_filetype_and_ext', array( $this->mime_types, 'fix_elpx_filetype' ) )
		);

		$this->assertGreaterThan(
			0,
			has_filter( 'post_mime_types', array( $this->mime_types, 'add_elpx_post_mime_type' ) )
		);
	}

	/**
	 * Test fix_elpx_filetype corrects elpx extension.
	 */
	public function test_fix_elpx_filetype() {
		$data = array(
			'ext'  => '',
			'type' => '',
		);

		$result = $this->mime_types->fix_elpx_filetype(
			$data,
			'/tmp/test.elpx',
			'test.elpx',
			array( 'elpx' => 'application/zip' )
		);

		$this->assertEquals( 'elpx', $result['ext'] );
		$this->assertEquals( 'application/zip', $result['type'] );
	}

	/**
	 * Test fix_elpx_filetype ignores non-elpx files.
	 */
	public function test_fix_elpx_filetype_ignores_other_extensions() {
		$data = array(
			'ext'  => 'pdf',
			'type' => 'application/pdf',
		);

		$result = $this->mime_types->fix_elpx_filetype(
			$data,
			'/tmp/test.pdf',
			'test.pdf',
			array( 'pdf' => 'application/pdf' )
		);

		$this->assertEquals( 'pdf', $result['ext'] );
		$this->assertEquals( 'application/pdf', $result['type'] );
	}

	/**
	 * Test add_elpx_post_mime_type adds eXeLearning to media library filter.
	 */
	public function test_add_elpx_post_mime_type() {
		$post_mime_types = array();
		$result          = $this->mime_types->add_elpx_post_mime_type( $post_mime_types );

		$this->assertArrayHasKey( 'application/zip', $result );
		$this->assertIsArray( $result['application/zip'] );
	}

	/**
	 * Test include_elpx_in_media_library returns query unchanged.
	 */
	public function test_include_elpx_in_media_library() {
		$query = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
		);

		$result = $this->mime_types->include_elpx_in_media_library( $query );

		$this->assertEquals( $query, $result );
	}
}
