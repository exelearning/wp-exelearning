<?php
/**
 * Tests for ExeLearning_Elp_File_Service class.
 *
 * @package Exelearning
 */

/**
 * Class ElpFileServiceParserTest.
 *
 * @covers ExeLearning_Elp_File_Service
 */
class ElpFileServiceParserTest extends WP_UnitTestCase {

	/**
	 * Path to test fixtures.
	 *
	 * @var string
	 */
	private static $fixtures_path;

	/**
	 * Path to generated test ELP v3 file.
	 *
	 * @var string
	 */
	private static $test_elp_v3;

	/**
	 * Path to generated test ELP v2 file.
	 *
	 * @var string
	 */
	private static $test_elp_v2;

	/**
	 * Set up test fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$fixtures_path = dirname( __DIR__ ) . '/fixtures';
		if ( ! is_dir( self::$fixtures_path ) ) {
			mkdir( self::$fixtures_path, 0755, true );
		}

		// Create test ELP v3 file.
		self::$test_elp_v3 = self::$fixtures_path . '/test-v3.elpx';
		self::create_test_elp_v3( self::$test_elp_v3 );

		// Create test ELP v2 file (for rejection tests).
		self::$test_elp_v2 = self::$fixtures_path . '/test-v2.elpx';
		self::create_test_elp_v2( self::$test_elp_v2 );
	}

	/**
	 * Clean up test fixtures.
	 */
	public static function tear_down_after_class() {
		parent::tear_down_after_class();

		if ( file_exists( self::$test_elp_v3 ) ) {
			unlink( self::$test_elp_v3 );
		}
		if ( file_exists( self::$test_elp_v2 ) ) {
			unlink( self::$test_elp_v2 );
		}
	}

	/**
	 * Create a test ELP v3 file.
	 *
	 * @param string $path File path.
	 */
	private static function create_test_elp_v3( $path ) {
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$content_xml = '<?xml version="1.0" encoding="UTF-8"?>
<package>
	<odeProperties>
		<odeProperty>
			<key>pp_title</key>
			<value>Test ELP Title</value>
		</odeProperty>
		<odeProperty>
			<key>pp_description</key>
			<value>Test ELP Description</value>
		</odeProperty>
		<odeProperty>
			<key>pp_author</key>
			<value>Test Author</value>
		</odeProperty>
		<odeProperty>
			<key>license</key>
			<value>CC-BY-SA</value>
		</odeProperty>
		<odeProperty>
			<key>lom_general_language</key>
			<value>en</value>
		</odeProperty>
		<odeProperty>
			<key>pp_learningResourceType</key>
			<value>lesson</value>
		</odeProperty>
	</odeProperties>
</package>';

		$zip->addFromString( 'content.xml', $content_xml );

		$index_html = '<!DOCTYPE html>
<html>
<head><title>Test ELP</title></head>
<body><h1>Test Content</h1></body>
</html>';
		$zip->addFromString( 'index.html', $index_html );

		$zip->close();
	}

	/**
	 * Create a test ELP v2 file (contentv3.xml, no content.xml).
	 *
	 * @param string $path File path.
	 */
	private static function create_test_elp_v2( $path ) {
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$content_xml = '<?xml version="1.0" encoding="UTF-8"?>
<pickle>
	<dictionary>
		<string role="key" value="_title"/>
		<unicode value="Test V2 Title"/>
	</dictionary>
</pickle>';

		$zip->addFromString( 'contentv3.xml', $content_xml );

		$zip->close();
	}

	/**
	 * Helper: parse a file with the service.
	 *
	 * @param string $path File path.
	 * @return ExeLearning_Elp_File_Service
	 */
	private function parse_file( $path ) {
		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->parse( $path );
		$this->assertTrue( $result );
		return $service;
	}

	/**
	 * Test parsing a v3 ELP file.
	 */
	public function test_parse_v3_file() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$this->assertEquals( 3, $service->get_version() );
	}

	/**
	 * Test parsing a v2 ELP file returns error.
	 */
	public function test_parse_v2_file_returns_error() {
		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->parse( self::$test_elp_v2 );

		$this->assertWPError( $result );
		$this->assertEquals( 'elp_v2_not_supported', $result->get_error_code() );
	}

	/**
	 * Test v2 error message mentions older version.
	 */
	public function test_parse_v2_error_message() {
		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->parse( self::$test_elp_v2 );

		$this->assertWPError( $result );
		$this->assertSame( 'elp_v2_not_supported', $result->get_error_code() );
	}

	/**
	 * Test get_title returns correct title.
	 */
	public function test_get_title() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$this->assertEquals( 'Test ELP Title', $service->get_title() );
	}

	/**
	 * Test get_description returns correct description.
	 */
	public function test_get_description() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$this->assertEquals( 'Test ELP Description', $service->get_description() );
	}

	/**
	 * Test get_author returns correct author.
	 */
	public function test_get_author() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$this->assertEquals( 'Test Author', $service->get_author() );
	}

	/**
	 * Test get_license returns correct license.
	 */
	public function test_get_license() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$this->assertEquals( 'CC-BY-SA', $service->get_license() );
	}

	/**
	 * Test get_language returns correct language.
	 */
	public function test_get_language() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$this->assertEquals( 'en', $service->get_language() );
	}

	/**
	 * Test get_learning_resource_type returns correct type.
	 */
	public function test_get_learning_resource_type() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$this->assertEquals( 'lesson', $service->get_learning_resource_type() );
	}

	/**
	 * Test to_array returns expected structure.
	 */
	public function test_to_array() {
		$service = $this->parse_file( self::$test_elp_v3 );
		$array   = $service->to_array();

		$this->assertIsArray( $array );
		$this->assertArrayHasKey( 'version', $array );
		$this->assertArrayHasKey( 'title', $array );
		$this->assertArrayHasKey( 'description', $array );
		$this->assertArrayHasKey( 'author', $array );
		$this->assertArrayHasKey( 'license', $array );
		$this->assertArrayHasKey( 'language', $array );
		$this->assertArrayHasKey( 'learningResourceType', $array );
	}

	/**
	 * Test validate_elp_file returns valid status for v3.
	 */
	public function test_validate_elp_file_v3() {
		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->validate_elp_file( self::$test_elp_v3 );

		$this->assertIsArray( $result );
		$this->assertEquals( 'valid', $result['status'] );
		$this->assertEquals( 3, $result['version'] );
		$this->assertIsArray( $result['data'] );
	}

	/**
	 * Test validate_elp_file rejects v2 file.
	 */
	public function test_validate_elp_file_rejects_v2() {
		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->validate_elp_file( self::$test_elp_v2 );

		$this->assertWPError( $result );
		$this->assertEquals( 'elp_v2_not_supported', $result->get_error_code() );
	}

	/**
	 * Test extract extracts files to destination.
	 */
	public function test_extract() {
		$service     = new ExeLearning_Elp_File_Service();
		$extract_dir = self::$fixtures_path . '/extracted-test';

		$result = $service->extract( self::$test_elp_v3, $extract_dir );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $extract_dir );
		$this->assertFileExists( $extract_dir . '/index.html' );
		$this->assertFileExists( $extract_dir . '/content.xml' );

		// Cleanup.
		unlink( $extract_dir . '/index.html' );
		unlink( $extract_dir . '/content.xml' );
		rmdir( $extract_dir );
	}

	/**
	 * Test extract creates directory if not exists.
	 */
	public function test_extract_creates_directory() {
		$service     = new ExeLearning_Elp_File_Service();
		$extract_dir = self::$fixtures_path . '/new-directory/nested';

		$result = $service->extract( self::$test_elp_v3, $extract_dir );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $extract_dir );

		// Cleanup.
		unlink( $extract_dir . '/index.html' );
		unlink( $extract_dir . '/content.xml' );
		rmdir( $extract_dir );
		rmdir( dirname( $extract_dir ) );
	}

	/**
	 * Test parsing nonexistent file returns WP_Error.
	 */
	public function test_parse_nonexistent_file() {
		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->parse( '/nonexistent/file.elpx' );

		$this->assertWPError( $result );
		$this->assertEquals( 'elp_not_found', $result->get_error_code() );
	}

	/**
	 * Test parsing non-zip file returns WP_Error.
	 */
	public function test_parse_non_zip_file() {
		$text_file = self::$fixtures_path . '/not-a-zip.elpx';
		file_put_contents( $text_file, 'This is not a zip file' );

		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->parse( $text_file );

		$this->assertWPError( $result );
		$this->assertEquals( 'elp_not_zip', $result->get_error_code() );

		unlink( $text_file );
	}

	/**
	 * Test parsing zip without content.xml or contentv3.xml returns elp_invalid.
	 */
	public function test_parse_invalid_elp_no_content() {
		$invalid_elp = self::$fixtures_path . '/invalid-no-content.elpx';
		$zip         = new ZipArchive();
		$zip->open( $invalid_elp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'random.txt', 'Random content' );
		$zip->close();

		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->parse( $invalid_elp );

		$this->assertWPError( $result );
		$this->assertEquals( 'elp_invalid', $result->get_error_code() );

		unlink( $invalid_elp );
	}

	/**
	 * Test parsing zip with invalid XML returns WP_Error.
	 */
	public function test_parse_invalid_xml() {
		$invalid_elp = self::$fixtures_path . '/invalid-xml.elpx';
		$zip         = new ZipArchive();
		$zip->open( $invalid_elp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'content.xml', 'Not valid XML <unclosed' );
		$zip->addFromString( 'index.html', '<html></html>' );
		$zip->close();

		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->parse( $invalid_elp );

		$this->assertWPError( $result );
		$this->assertEquals( 'elp_xml_error', $result->get_error_code() );

		unlink( $invalid_elp );
	}

	/**
	 * Test v3 file without odeProperties returns empty metadata.
	 */
	public function test_v3_without_properties() {
		$path = self::$fixtures_path . '/test-v3-no-props.elpx';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$content_xml = '<?xml version="1.0" encoding="UTF-8"?>
<package>
	<empty/>
</package>';

		$zip->addFromString( 'content.xml', $content_xml );
		$zip->addFromString( 'index.html', '<html></html>' );
		$zip->close();

		$service = $this->parse_file( $path );
		$this->assertEquals( '', $service->get_title() );
		$this->assertEquals( 3, $service->get_version() );

		unlink( $path );
	}

	/**
	 * Test extract returns WP_Error for invalid file.
	 */
	public function test_extract_invalid_file() {
		$service = new ExeLearning_Elp_File_Service();
		$result  = $service->extract( '/nonexistent/file.elpx', self::$fixtures_path . '/out' );

		$this->assertWPError( $result );
		$this->assertEquals( 'elp_open_failed', $result->get_error_code() );
	}
}
