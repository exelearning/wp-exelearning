<?php
/**
 * Tests for ExeLearning_Filters class.
 *
 * @package Exelearning
 */

/**
 * Class FiltersTest.
 *
 * @covers ExeLearning_Filters
 */
class FiltersTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Filters
	 */
	private $filters;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->filters = new ExeLearning_Filters();
	}

	/**
	 * Test register_filters adds filter.
	 */
	public function test_register_filters_adds_filter() {
		$this->filters->register_filters();

		$this->assertGreaterThan(
			0,
			has_filter( 'wp_get_attachment_metadata', array( $this->filters, 'filter_attachment_metadata' ) )
		);
	}

	/**
	 * Test filter_attachment_metadata returns metadata unchanged for non-elp.
	 */
	public function test_filter_attachment_metadata_unchanged_for_non_elp() {
		$attachment_id = $this->factory->attachment->create();
		$metadata      = array( 'width' => 100, 'height' => 100 );

		$result = $this->filters->filter_attachment_metadata( $metadata, $attachment_id );

		$this->assertEquals( $metadata, $result );
	}

	/**
	 * Test filter_attachment_metadata handles empty attachment_id.
	 */
	public function test_filter_attachment_metadata_handles_empty_id() {
		$metadata = array( 'test' => 'value' );

		$result = $this->filters->filter_attachment_metadata( $metadata, 0 );

		$this->assertEquals( $metadata, $result );
	}

	/**
	 * Test filter_attachment_metadata handles null attachment_id.
	 */
	public function test_filter_attachment_metadata_handles_null_id() {
		$metadata = array( 'test' => 'value' );

		$result = $this->filters->filter_attachment_metadata( $metadata, null );

		$this->assertEquals( $metadata, $result );
	}

	/**
	 * Test filter_attachment_metadata handles non-numeric attachment_id.
	 */
	public function test_filter_attachment_metadata_handles_non_numeric_id() {
		$metadata = array( 'test' => 'value' );

		$result = $this->filters->filter_attachment_metadata( $metadata, 'invalid' );

		$this->assertEquals( $metadata, $result );
	}

	/**
	 * Test is_elp_file method is private.
	 */
	public function test_is_elp_file_is_private() {
		$method = new ReflectionMethod( ExeLearning_Filters::class, 'is_elp_file' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test is_elp_file returns true for elpx files.
	 */
	public function test_is_elp_file_returns_true_for_elpx() {
		$method = new ReflectionMethod( ExeLearning_Filters::class, 'is_elp_file' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->filters, '/path/to/file.elpx' ) );
	}

	/**
	 * Test is_elp_file returns false for non-elp files.
	 */
	public function test_is_elp_file_returns_false_for_other() {
		$method = new ReflectionMethod( ExeLearning_Filters::class, 'is_elp_file' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $this->filters, '/path/to/file.pdf' ) );
		$this->assertFalse( $method->invoke( $this->filters, '/path/to/file.zip' ) );
	}

	/**
	 * Test is_elp_file handles null input.
	 */
	public function test_is_elp_file_handles_null() {
		$method = new ReflectionMethod( ExeLearning_Filters::class, 'is_elp_file' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $this->filters, null ) );
	}

	/**
	 * Test is_elp_file handles empty string.
	 */
	public function test_is_elp_file_handles_empty() {
		$method = new ReflectionMethod( ExeLearning_Filters::class, 'is_elp_file' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $this->filters, '' ) );
	}

	/**
	 * Test filter_attachment_metadata adds exelearning key for elpx files.
	 */
	public function test_filter_attachment_metadata_adds_key_for_elpx() {
		$attachment_id = $this->factory->attachment->create();

		// Create a temp elpx file.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/test-filter-' . $attachment_id . '.elpx';
		file_put_contents( $file_path, 'test content' );

		update_attached_file( $attachment_id, $file_path );

		$metadata = array( 'width' => 100 );
		$result   = $this->filters->filter_attachment_metadata( $metadata, $attachment_id );

		$this->assertArrayHasKey( 'exelearning', $result );
		$this->assertEquals( 'elp file metadata', $result['exelearning'] );

		unlink( $file_path );
	}

	/**
	 * Test filter_attachment_metadata preserves existing metadata.
	 */
	public function test_filter_attachment_metadata_preserves_existing() {
		$attachment_id = $this->factory->attachment->create();

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/test-preserve-' . $attachment_id . '.elpx';
		file_put_contents( $file_path, 'test content' );

		update_attached_file( $attachment_id, $file_path );

		$metadata = array(
			'width'  => 100,
			'height' => 200,
		);
		$result   = $this->filters->filter_attachment_metadata( $metadata, $attachment_id );

		$this->assertEquals( 100, $result['width'] );
		$this->assertEquals( 200, $result['height'] );
		$this->assertArrayHasKey( 'exelearning', $result );

		unlink( $file_path );
	}

	/**
	 * Test is_elp_file returns true for elp extension.
	 */
	public function test_is_elp_file_returns_false_for_elp() {
		$method = new ReflectionMethod( ExeLearning_Filters::class, 'is_elp_file' );
		$method->setAccessible( true );

		// The function only returns true for elpx, not elp.
		$this->assertFalse( $method->invoke( $this->filters, '/path/to/file.elp' ) );
	}

	/**
	 * Test is_elp_file case insensitive.
	 */
	public function test_is_elp_file_case_insensitive() {
		$method = new ReflectionMethod( ExeLearning_Filters::class, 'is_elp_file' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->filters, '/path/to/FILE.ELPX' ) );
		$this->assertTrue( $method->invoke( $this->filters, '/path/to/file.Elpx' ) );
	}
}
