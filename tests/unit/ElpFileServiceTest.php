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

	/**
	 * Test is_unsafe_zip_entry flags traversal, absolute paths and stream wrappers.
	 *
	 * @dataProvider provide_unsafe_entries
	 *
	 * @param string $name     Archive entry name.
	 * @param bool   $expected Whether it should be flagged unsafe.
	 */
	public function test_is_unsafe_zip_entry( $name, $expected ) {
		$this->assertSame( $expected, ExeLearning_Elp_File_Service::is_unsafe_zip_entry( $name ) );
	}

	/**
	 * Data provider for unsafe/safe archive entries.
	 *
	 * @return array[]
	 */
	public function provide_unsafe_entries() {
		return array(
			'normal file'        => array( 'index.html', false ),
			'nested file'        => array( 'assets/css/style.css', false ),
			'directory'          => array( 'assets/', false ),
			'parent traversal'   => array( '../evil.php', true ),
			'nested traversal'   => array( 'a/../../evil.php', true ),
			'absolute path'      => array( '/etc/passwd', true ),
			'backslash path'     => array( '..\\evil.php', true ),
			'stream wrapper'     => array( 'phar://evil', true ),
			'empty'              => array( '', true ),
		);
	}

	/**
	 * Test extract() rejects a zip-slip (path traversal) archive.
	 */
	public function test_extract_rejects_zip_slip() {
		$zip_path = wp_tempnam( 'slip.elpx' );
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'content.xml', '<package></package>' );
		$zip->addFromString( '../../evil.php', '<?php echo "pwned"; ?>' );
		$zip->close();

		$dest   = trailingslashit( get_temp_dir() ) . 'exe-slip-' . uniqid() . '/';
		$result = $this->service->extract( $zip_path, $dest );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'elp_unsafe_entry', $result->get_error_code() );

		// The traversal target must not have been written outside the destination.
		$this->assertFileDoesNotExist( dirname( rtrim( $dest, '/' ), 2 ) . '/evil.php' );

		wp_delete_file( $zip_path );
	}

	/**
	 * Test extract() rejects an archive with an absolute-path entry.
	 */
	public function test_extract_rejects_absolute_path_entry() {
		$zip_path = wp_tempnam( 'abs.elpx' );
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'content.xml', '<package></package>' );
		$zip->addFromString( '/tmp/exe-abs-evil.txt', 'evil' );
		$zip->close();

		$dest   = trailingslashit( get_temp_dir() ) . 'exe-abs-' . uniqid() . '/';
		$result = $this->service->extract( $zip_path, $dest );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'elp_unsafe_entry', $result->get_error_code() );

		wp_delete_file( $zip_path );
	}

	/**
	 * Test extract() writes a valid archive's files inside the destination.
	 */
	public function test_extract_valid_archive() {
		$dest   = trailingslashit( get_temp_dir() ) . 'exe-ok-' . uniqid() . '/';
		$result = $this->service->extract( self::$test_elp_v3, $dest );

		$this->assertTrue( $result );
		$this->assertFileExists( $dest . 'index.html' );
		$this->assertFileExists( $dest . 'content.xml' );
	}

	/**
	 * Test extract() enforces the file-count cap (zip-bomb guard).
	 */
	public function test_extract_enforces_file_count_cap() {
		$cap = function () {
			return 2;
		};
		add_filter( 'exelearning_max_extract_files', $cap );

		$zip_path = wp_tempnam( 'many.elpx' );
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		for ( $i = 0; $i < 5; $i++ ) {
			$zip->addFromString( "file{$i}.txt", 'x' );
		}
		$zip->close();

		$dest   = trailingslashit( get_temp_dir() ) . 'exe-many-' . uniqid() . '/';
		$result = $this->service->extract( $zip_path, $dest );

		remove_filter( 'exelearning_max_extract_files', $cap );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'elp_too_many_files', $result->get_error_code() );

		wp_delete_file( $zip_path );
	}

	/**
	 * Test metadata values from content.xml are sanitized (no active markup),
	 * since they are later surfaced unescaped in admin JS / post meta.
	 */
	public function test_metadata_values_are_sanitized() {
		$zip_path = wp_tempnam( 'mal.elpx' );
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString(
			'content.xml',
			'<?xml version="1.0" encoding="UTF-8"?><package><odeProperties>'
			. '<odeProperty><key>pp_title</key><value>T&lt;script&gt;alert(1)&lt;/script&gt;</value></odeProperty>'
			. '<odeProperty><key>license</key><value>L&lt;img src=x onerror=alert(1)&gt;</value></odeProperty>'
			. '</odeProperties></package>'
		);
		$zip->close();

		$result = $this->service->validate_elp_file( $zip_path );

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<', $this->service->get_title() );
		$this->assertStringNotContainsString( '<', $this->service->get_license() );

		wp_delete_file( $zip_path );
	}

	/**
	 * Test is_forbidden_archive_entry flags server config and executable entries.
	 *
	 * @dataProvider provide_forbidden_entries
	 *
	 * @param string $name     Archive entry name.
	 * @param bool   $expected Whether it should be flagged forbidden.
	 */
	public function test_is_forbidden_archive_entry( $name, $expected ) {
		$this->assertSame( $expected, ExeLearning_Elp_File_Service::is_forbidden_archive_entry( $name ) );
	}

	/**
	 * Data provider for forbidden/allowed archive entries.
	 *
	 * @return array[]
	 */
	public function provide_forbidden_entries() {
		return array(
			// Forbidden server-configuration files.
			'htaccess'           => array( '.htaccess', true ),
			'nested htaccess'    => array( 'folder/.htaccess', true ),
			'user ini'           => array( '.user.ini', true ),
			'web config'         => array( 'web.config', true ),
			// Forbidden server-executable extensions (case-insensitive).
			'php script'         => array( 'shell.php', true ),
			'phtml uppercase'    => array( 'assets/shell.PHTML', true ),
			'phar payload'       => array( 'assets/payload.phar', true ),
			'cgi script'         => array( 'assets/run.cgi', true ),
			// Safe eXeLearning-like assets.
			'content xml'        => array( 'content.xml', false ),
			'index html'         => array( 'index.html', false ),
			'app js'             => array( 'assets/app.js', false ),
			'style css'          => array( 'assets/style.css', false ),
			'image svg'          => array( 'assets/image.svg', false ),
			'readme txt'         => array( 'assets/readme.txt', false ),
		);
	}

	/**
	 * Test extract() rejects an archive containing a forbidden server-config entry.
	 *
	 * Uses inert text contents only; no executable code is included.
	 */
	public function test_extract_rejects_forbidden_entry() {
		$zip_path = wp_tempnam( 'forbidden.elpx' );
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'content.xml', '<package></package>' );
		$zip->addFromString( 'index.html', '<html><body>ok</body></html>' );
		$zip->addFromString( '.htaccess', "AddType application/x-httpd-php .txt\n" );
		$zip->addFromString( 'payload.txt', 'inert text payload' );
		$zip->close();

		$dest   = trailingslashit( get_temp_dir() ) . 'exe-forbidden-' . uniqid() . '/';
		$result = $this->service->extract( $zip_path, $dest );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'elp_forbidden_entry', $result->get_error_code() );

		// The forbidden entry and its companion payload must not be left behind.
		$this->assertFileDoesNotExist( $dest . '.htaccess' );
		$this->assertFileDoesNotExist( $dest . 'payload.txt' );

		wp_delete_file( $zip_path );
	}
}
