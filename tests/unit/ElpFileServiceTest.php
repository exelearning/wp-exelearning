<?php
/**
 * Tests for ExeLearning_Elp_File_Service validate_elp_file method.
 *
 * @package Exelearning
 */

/**
 * Class ElpFileServiceTest.
 *
 * @covers ExeLearning_Elp_File_Service
 */
class ElpFileServiceTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Elp_File_Service
	 */
	private $service;

	/**
	 * Test fixture path for v3 ELP.
	 *
	 * @var string
	 */
	private static $test_elp_v3;

	/**
	 * Test fixture path for v2 ELP.
	 *
	 * @var string
	 */
	private static $test_elp_v2;

	/**
	 * Set up class fixtures.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$test_elp_v3 = wp_tempnam( 'test_v3.elpx' );
		self::$test_elp_v2 = wp_tempnam( 'test_v2.elpx' );

		self::create_test_elp_v3( self::$test_elp_v3 );
		self::create_test_elp_v2( self::$test_elp_v2 );
	}

	/**
	 * Tear down class fixtures.
	 */
	public static function tear_down_after_class() {
		parent::tear_down_after_class();
		if ( file_exists( self::$test_elp_v3 ) ) {
			wp_delete_file( self::$test_elp_v3 );
		}
		if ( file_exists( self::$test_elp_v2 ) ) {
			wp_delete_file( self::$test_elp_v2 );
		}
	}

	/**
	 * Create a valid v3 ELP test file.
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
			<value>Test V3 Title</value>
		</odeProperty>
		<odeProperty>
			<key>pp_description</key>
			<value>Test V3 Description</value>
		</odeProperty>
		<odeProperty>
			<key>pp_author</key>
			<value>Test Author</value>
		</odeProperty>
	</odeProperties>
</package>';

		$zip->addFromString( 'content.xml', $content_xml );
		$zip->addFromString( 'index.html', '<html><body>Test v3</body></html>' );
		$zip->close();
	}

	/**
	 * Create a valid v2 ELP test file.
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
		<string role="key" value="_description"/>
		<unicode value="Test V2 Description"/>
		<string role="key" value="_author"/>
		<unicode value="Test V2 Author"/>
		<string role="key" value="license"/>
		<unicode value="GPL"/>
		<string role="key" value="_lang"/>
		<unicode value="es"/>
	</dictionary>
</pickle>';

		$zip->addFromString( 'contentv3.xml', $content_xml );
		$zip->close();
	}

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->service = new ExeLearning_Elp_File_Service();
	}

	/**
	 * Test service class can be instantiated.
	 */
	public function test_service_instantiation() {
		$this->assertInstanceOf( ExeLearning_Elp_File_Service::class, $this->service );
	}

	/**
	 * Test validate_elp_file returns error for non-existent file.
	 */
	public function test_validate_elp_file_nonexistent() {
		$result = $this->service->validate_elp_file( '/nonexistent/path/file.elpx' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'elp_not_found', $result->get_error_code() );
	}

	/**
	 * Test validate_elp_file returns error for invalid file.
	 */
	public function test_validate_elp_file_invalid() {
		$temp_file = wp_tempnam( 'invalid.elpx' );
		file_put_contents( $temp_file, 'not a valid elp file' );

		$result = $this->service->validate_elp_file( $temp_file );

		$this->assertInstanceOf( WP_Error::class, $result );

		wp_delete_file( $temp_file );
	}

	/**
	 * Test validate_elp_file with invalid zip file.
	 */
	public function test_validate_elp_file_invalid_zip() {
		$temp_file = wp_tempnam( 'notazip.elpx' );
		file_put_contents( $temp_file, 'PK' . str_repeat( 'x', 100 ) );

		$result = $this->service->validate_elp_file( $temp_file );

		$this->assertInstanceOf( WP_Error::class, $result );

		wp_delete_file( $temp_file );
	}

	/**
	 * Test validate_elp_file with valid v3 file returns valid status.
	 */
	public function test_validate_elp_file_v3_valid_status() {
		$result = $this->service->validate_elp_file( self::$test_elp_v3 );

		$this->assertIsArray( $result );
		$this->assertEquals( 'valid', $result['status'] );
		$this->assertEquals( 3, $result['version'] );
	}

	/**
	 * Test validate_elp_file with v3 file returns data array.
	 */
	public function test_validate_elp_file_v3_returns_data() {
		$result = $this->service->validate_elp_file( self::$test_elp_v3 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertIsArray( $result['data'] );
	}

	/**
	 * Test validate_elp_file rejects v2 file.
	 */
	public function test_validate_elp_file_v2_rejected() {
		$result = $this->service->validate_elp_file( self::$test_elp_v2 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'elp_v2_not_supported', $result->get_error_code() );
	}

	/**
	 * Test validate_elp_file method exists.
	 */
	public function test_validate_elp_file_method_exists() {
		$this->assertTrue( method_exists( $this->service, 'validate_elp_file' ) );
	}

	/**
	 * Test validate_elp_file error message for invalid file.
	 */
	public function test_validate_elp_file_error_message() {
		$result = $this->service->validate_elp_file( '/nonexistent/file.elpx' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message() );
	}

	/**
	 * Test validate_elp_file with empty path.
	 */
	public function test_validate_elp_file_empty_path() {
		$result = $this->service->validate_elp_file( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test validate_elp_file returns error when XML content cannot be read from ZIP.
	 *
	 * This test uses a partial mock of the service to inject a mock ZipArchive.
	 */
	public function test_validate_elp_file_read_failed() {
		// Mock ZipArchive to return false on getFromName.
		$zip_mock = $this->getMockBuilder( 'ZipArchive' )
			->onlyMethods( array( 'open', 'locateName', 'getFromName', 'close' ) )
			->getMock();

		$zip_mock->expects( $this->once() )
			->method( 'open' )
			->willReturn( true );

		$zip_mock->expects( $this->once() )
			->method( 'locateName' )
			->with( 'content.xml' )
			->willReturn( 0 ); // Found.

		$zip_mock->expects( $this->once() )
			->method( 'getFromName' )
			->with( 'content.xml' )
			->willReturn( false ); // Simulate read failure.

		$zip_mock->expects( $this->once() )
			->method( 'close' )
			->willReturn( true );

		// Partially mock the service to return our mock ZipArchive.
		$service_mock = $this->getMockBuilder( ExeLearning_Elp_File_Service::class )
			->onlyMethods( array( 'get_zip_instance' ) )
			->getMock();

		$service_mock->expects( $this->once() )
			->method( 'get_zip_instance' )
			->willReturn( $zip_mock );

		// We need a real zip file for the initial mime_content_type check.
		$temp_file = wp_tempnam( 'test.elpx' );
		$zip       = new ZipArchive();
		$zip->open( $temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'dummy.txt', 'dummy' );
		$zip->close();

		$result = $service_mock->validate_elp_file( $temp_file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'elp_read_failed', $result->get_error_code() );

		wp_delete_file( $temp_file );
	}
}
