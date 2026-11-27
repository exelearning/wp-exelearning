<?php
/**
 * Tests for ExeLearning_Content_Proxy class.
 *
 * @package Exelearning
 */

/**
 * Class ContentProxyTest.
 *
 * @covers ExeLearning_Content_Proxy
 * @covers ExeLearning_Content_Url_Rewriter
 */
class ContentProxyTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Content_Proxy
	 */
	private $proxy;

	/**
	 * URL rewriter the proxy delegates to.
	 *
	 * @var ExeLearning_Content_Url_Rewriter
	 */
	private $rewriter;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->proxy    = new ExeLearning_Content_Proxy();
		$this->rewriter = new ExeLearning_Content_Url_Rewriter();
	}

	/**
	 * Drop the request a test may have simulated.
	 *
	 * REQUEST_URI goes back to the empty value the test library boots with:
	 * core reads it unguarded, so it must not be left unset for later tests.
	 */
	public function tear_down() {
		$_GET = array();
		$this->reset__SERVER();
		// Back to the suite's plain permalinks: the rewrite global outlives a test.
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Simulate the URL the browser requested the current document with.
	 *
	 * @param string $uri Request URI, e.g. the path-based proxy URL of a page.
	 */
	private function request( $uri ) {
		$_SERVER['REQUEST_URI'] = $uri;
	}

	/**
	 * Path-based proxy request URI for a package file.
	 *
	 * @param string $file File inside the package, already URL-encoded.
	 * @return string Request URI.
	 */
	private function page_uri( $file ) {
		return '/wp-json/exelearning/v1/content/' . str_repeat( 'a', 40 ) . '/' . $file;
	}

	/**
	 * Test get_proxy_url returns correct URL for valid hash.
	 */
	public function test_get_proxy_url_returns_correct_url() {
		$hash = str_repeat( 'a', 40 );
		$url  = ExeLearning_Content_Proxy::get_proxy_url( $hash );

		$this->assertStringContainsString( 'exelearning/v1/content/', $url );
		$this->assertStringContainsString( $hash, $url );
		$this->assertStringContainsString( 'index.html', $url );
	}

	/**
	 * Test get_proxy_url with custom file parameter.
	 */
	public function test_get_proxy_url_with_custom_file() {
		$hash = str_repeat( 'b', 40 );
		$url  = ExeLearning_Content_Proxy::get_proxy_url( $hash, 'styles/main.css' );

		$this->assertStringContainsString( $hash, $url );
		$this->assertStringContainsString( 'styles/main.css', $url );
	}

	/**
	 * Test get_proxy_url returns null for empty hash.
	 */
	public function test_get_proxy_url_returns_null_for_empty_hash() {
		$this->assertNull( ExeLearning_Content_Proxy::get_proxy_url( '' ) );
		$this->assertNull( ExeLearning_Content_Proxy::get_proxy_url( null ) );
	}

	/**
	 * Test sanitize_path blocks directory traversal attempts.
	 */
	public function test_sanitize_path_blocks_directory_traversal() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		// These should return null (blocked).
		$this->assertNull( $method->invoke( $this->proxy, '../../../etc/passwd' ) );
		$this->assertNull( $method->invoke( $this->proxy, 'folder/../../../secret' ) );
		$this->assertNull( $method->invoke( $this->proxy, '..\\..\\windows\\system32' ) );
	}

	/**
	 * Test sanitize_path removes null bytes.
	 */
	public function test_sanitize_path_removes_null_bytes() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, "file\0.php" );
		$this->assertStringNotContainsString( "\0", $result );
		$this->assertEquals( 'file.php', $result );
	}

	/**
	 * Test sanitize_path normalizes slashes.
	 */
	public function test_sanitize_path_normalizes_slashes() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'folder\\subfolder\\file.html' );
		$this->assertEquals( 'folder/subfolder/file.html', $result );
	}

	/**
	 * Test sanitize_path returns index.html for empty path.
	 */
	public function test_sanitize_path_returns_index_for_empty() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$this->assertEquals( 'index.html', $method->invoke( $this->proxy, '' ) );
		$this->assertEquals( 'index.html', $method->invoke( $this->proxy, '/' ) );
		$this->assertEquals( 'index.html', $method->invoke( $this->proxy, './' ) );
	}

	/**
	 * Test sanitize_path handles URL encoded paths.
	 */
	public function test_sanitize_path_decodes_url_encoding() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'folder%2Ffile%2Ehtml' );
		$this->assertEquals( 'folder/file.html', $result );
	}

	/**
	 * Test sanitize_path rejects encoded directory traversal.
	 */
	public function test_sanitize_path_rejects_encoded_traversal() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		// %2e%2e = ..
		$this->assertNull( $method->invoke( $this->proxy, '%2e%2e/%2e%2e/secret' ) );
	}

	/**
	 * Test serve_content rejects invalid hash format.
	 */
	public function test_serve_content_rejects_invalid_hash() {
		// Create a mock REST request with invalid hash.
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/invalid-hash/index.html' );
		$request->set_param( 'hash', 'invalid-hash' );
		$request->set_param( 'file', 'index.html' );

		$result = $this->proxy->serve_content( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test serve_content rejects hash that is too short.
	 */
	public function test_serve_content_rejects_short_hash() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/abc123/index.html' );
		$request->set_param( 'hash', 'abc123' );

		$result = $this->proxy->serve_content( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test serve_content rejects hash with invalid characters.
	 */
	public function test_serve_content_rejects_hash_with_invalid_chars() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/test/index.html' );
		// Hash with special characters.
		$request->set_param( 'hash', str_repeat( 'g', 40 ) ); // 'g' is not hex.

		$result = $this->proxy->serve_content( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test serve_content returns file not found for non-existent file.
	 */
	public function test_serve_content_returns_not_found_for_missing_file() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/test/index.html' );
		$request->set_param( 'hash', str_repeat( 'a', 40 ) );
		$request->set_param( 'file', 'index.html' );

		$result = $this->proxy->serve_content( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test MIME types mapping includes common extensions.
	 */
	public function test_mime_types_mapping() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'text/html', $mime_types['html'] );
		$this->assertEquals( 'text/css', $mime_types['css'] );
		$this->assertEquals( 'application/javascript', $mime_types['js'] );
		$this->assertEquals( 'image/png', $mime_types['png'] );
		$this->assertEquals( 'image/jpeg', $mime_types['jpg'] );
		$this->assertEquals( 'application/pdf', $mime_types['pdf'] );
	}

	/**
	 * Test serve_content rejects directory traversal in file path.
	 */
	public function test_serve_content_rejects_traversal_in_file() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/test/file.html' );
		$request->set_param( 'hash', str_repeat( 'a', 40 ) );
		$request->set_param( 'file', '../../../etc/passwd' );

		$result = $this->proxy->serve_content( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	/**
	 * Test serve_content defaults to index.html when file is empty.
	 */
	public function test_serve_content_defaults_to_index() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/test/' );
		$request->set_param( 'hash', str_repeat( 'a', 40 ) );
		$request->set_param( 'file', '' );

		$result = $this->proxy->serve_content( $request );

		// Should return file_not_found (not invalid_path) because it defaults to index.html.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test sanitize_path handles current directory references.
	 */
	public function test_sanitize_path_handles_current_dir() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, './folder/./file.html' );
		$this->assertEquals( 'folder/file.html', $result );
	}

	/**
	 * Test sanitize_path handles multiple consecutive slashes.
	 */
	public function test_sanitize_path_handles_multiple_slashes() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'folder//subfolder///file.html' );
		$this->assertEquals( 'folder/subfolder/file.html', $result );
	}

	/**
	 * Test MIME types includes video formats.
	 */
	public function test_mime_types_includes_video() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'video/mp4', $mime_types['mp4'] );
		$this->assertEquals( 'video/webm', $mime_types['webm'] );
		$this->assertEquals( 'video/ogg', $mime_types['ogv'] );
	}

	/**
	 * Test MIME types includes audio formats.
	 */
	public function test_mime_types_includes_audio() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'audio/mpeg', $mime_types['mp3'] );
		$this->assertEquals( 'audio/ogg', $mime_types['ogg'] );
		$this->assertEquals( 'audio/wav', $mime_types['wav'] );
	}

	/**
	 * Test MIME types includes font formats.
	 */
	public function test_mime_types_includes_fonts() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'font/woff', $mime_types['woff'] );
		$this->assertEquals( 'font/woff2', $mime_types['woff2'] );
		$this->assertEquals( 'font/ttf', $mime_types['ttf'] );
	}

	/**
	 * Test MIME types includes image formats.
	 */
	public function test_mime_types_includes_images() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'image/gif', $mime_types['gif'] );
		$this->assertEquals( 'image/svg+xml', $mime_types['svg'] );
		$this->assertEquals( 'image/webp', $mime_types['webp'] );
		$this->assertEquals( 'image/x-icon', $mime_types['ico'] );
	}

	/**
	 * Test MIME types includes document formats.
	 */
	public function test_mime_types_includes_documents() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'application/json', $mime_types['json'] );
		$this->assertEquals( 'application/xml', $mime_types['xml'] );
		$this->assertEquals( 'application/zip', $mime_types['zip'] );
		$this->assertEquals( 'text/plain', $mime_types['txt'] );
	}

	/**
	 * Test constructor sets base_path correctly.
	 */
	public function test_constructor_sets_base_path() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'base_path' );
		$property->setAccessible( true );
		$base_path = $property->getValue( $this->proxy );

		$upload_dir = wp_upload_dir();
		$expected   = trailingslashit( $upload_dir['basedir'] ) . 'exelearning';

		$this->assertEquals( $expected, $base_path );
	}

	/**
	 * Test get_proxy_url is static method.
	 */
	public function test_get_proxy_url_is_static() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'get_proxy_url' );
		$this->assertTrue( $method->isStatic() );
	}

	/**
	 * Test serve_content is public.
	 */
	public function test_serve_content_is_public() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'serve_content' );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test send_headers method is private.
	 */
	public function test_send_headers_is_private() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'send_headers' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test sanitize_path method is private.
	 */
	public function test_sanitize_path_is_private() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test serve_content with null hash.
	 */
	public function test_serve_content_with_null_hash() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/' );
		$request->set_param( 'hash', null );

		$result = $this->proxy->serve_content( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test sanitize_path with complex traversal attempt.
	 */
	public function test_sanitize_path_complex_traversal() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( $this->proxy, 'valid/../../../etc/passwd' ) );
		$this->assertNull( $method->invoke( $this->proxy, './..\\..\\windows' ) );
		$this->assertNull( $method->invoke( $this->proxy, 'folder/..\\..\\secret' ) );
	}

	/**
	 * Test sanitize_path with valid nested path.
	 */
	public function test_sanitize_path_valid_nested() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'assets/css/style.css' );
		$this->assertEquals( 'assets/css/style.css', $result );
	}

	/**
	 * Test sanitize_path with file in root.
	 */
	public function test_sanitize_path_root_file() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'index.html' );
		$this->assertEquals( 'index.html', $result );
	}

	/**
	 * Test get_proxy_url returns correct format.
	 */
	public function test_get_proxy_url_format() {
		$hash = str_repeat( 'c', 40 );
		$url  = ExeLearning_Content_Proxy::get_proxy_url( $hash, 'page1.html' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'exelearning/v1/content', $url );
		$this->assertStringContainsString( $hash, $url );
		$this->assertStringContainsString( 'page1.html', $url );
	}

	/**
	 * Test sanitize_path handles leading slash.
	 */
	public function test_sanitize_path_handles_leading_slash() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, '/folder/file.html' );
		$this->assertEquals( 'folder/file.html', $result );
	}

	/**
	 * Test sanitize_path handles trailing slash.
	 */
	public function test_sanitize_path_handles_trailing_slash() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'folder/' );
		$this->assertEquals( 'folder', $result );
	}

	/**
	 * Test sanitize_path with double encoded path.
	 */
	public function test_sanitize_path_double_encoded() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		// %252e%252e = double encoded ..
		$result = $method->invoke( $this->proxy, '%252e%252e/secret' );
		// After one decode: %2e%2e/secret
		// This should not be decoded twice, so it's treated literally.
		$this->assertNotNull( $result );
	}

	/**
	 * Test get_proxy_url with special characters in file.
	 */
	public function test_get_proxy_url_special_chars() {
		$hash = str_repeat( 'd', 40 );
		$url  = ExeLearning_Content_Proxy::get_proxy_url( $hash, 'page 1.html' );

		$this->assertStringContainsString( $hash, $url );
	}

	/**
	 * Test serve_content with uppercase hash.
	 */
	public function test_serve_content_uppercase_hash() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/test/index.html' );
		$request->set_param( 'hash', strtoupper( str_repeat( 'a', 40 ) ) );
		$request->set_param( 'file', 'index.html' );

		$result = $this->proxy->serve_content( $request );

		// Should accept uppercase hash (case insensitive).
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test serve_content with empty hash string.
	 */
	public function test_serve_content_empty_hash_string() {
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/' );
		$request->set_param( 'hash', '' );

		$result = $this->proxy->serve_content( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test MIME types includes htm extension.
	 */
	public function test_mime_types_includes_htm() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'text/html', $mime_types['htm'] );
	}

	/**
	 * Test MIME types includes jpeg extension.
	 */
	public function test_mime_types_includes_jpeg() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'image/jpeg', $mime_types['jpeg'] );
	}

	/**
	 * Test MIME types includes eot font.
	 */
	public function test_mime_types_includes_eot() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'application/vnd.ms-fontobject', $mime_types['eot'] );
	}

	/**
	 * Test MIME types includes otf font.
	 */
	public function test_mime_types_includes_otf() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'font/otf', $mime_types['otf'] );
	}

	/**
	 * Test sanitize_path with spaces.
	 */
	public function test_sanitize_path_with_spaces() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'folder name/file name.html' );
		$this->assertEquals( 'folder name/file name.html', $result );
	}

	/**
	 * Test sanitize_path with unicode.
	 */
	public function test_sanitize_path_with_unicode() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'carpeta/archivo.html' );
		$this->assertEquals( 'carpeta/archivo.html', $result );
	}

	/**
	 * Test sanitize_path allows filenames starting with dots.
	 */
	public function test_sanitize_path_allows_dotted_filenames() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		// ..valid is a filename, not a traversal attempt.
		$result = $method->invoke( $this->proxy, 'folder/..valid/file' );
		$this->assertEquals( 'folder/..valid/file', $result );
	}

	/**
	 * Test sanitize_path with only dots.
	 */
	public function test_sanitize_path_only_dots() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'sanitize_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, '..' );
		$this->assertNull( $result );
	}

	/**
	 * Test resolve_relative_path with empty base directory.
	 */
	public function test_resolve_relative_path_empty_base() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, '', 'file.html' );
		$this->assertEquals( 'file.html', $result );
	}

	/**
	 * Test resolve_relative_path with parent directory traversal.
	 */
	public function test_resolve_relative_path_parent_traversal() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, 'html/css', '../images/logo.png' );
		$this->assertEquals( 'html/images/logo.png', $result );
	}

	/**
	 * Test resolve_relative_path with multiple parent traversals.
	 */
	public function test_resolve_relative_path_multiple_traversals() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, 'a/b/c', '../../d/file.txt' );
		$this->assertEquals( 'a/d/file.txt', $result );
	}

	/**
	 * Test resolve_relative_path with current directory references.
	 */
	public function test_resolve_relative_path_current_dir() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, 'folder', './file.html' );
		$this->assertEquals( 'folder/file.html', $result );
	}

	/**
	 * Test resolve_relative_path with leading dot-slash in empty base.
	 */
	public function test_resolve_relative_path_empty_base_with_dotslash() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, '', './file.html' );
		$this->assertEquals( 'file.html', $result );
	}

	/**
	 * Test resolve_relative_path with nested path.
	 */
	public function test_resolve_relative_path_nested() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, 'html', 'css/style.css' );
		$this->assertEquals( 'html/css/style.css', $result );
	}

	/**
	 * Test rewrite_relative_urls with basic HTML.
	 */
	public function test_rewrite_relative_urls_basic() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="images/logo.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'uploads/exelearning/', $result );
		$this->assertStringContainsString( 'images/logo.png', $result );
	}

	/**
	 * Test rewrite_relative_urls preserves absolute URLs.
	 */
	public function test_rewrite_relative_urls_preserves_absolute() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="https://example.com/image.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls preserves data URLs.
	 */
	public function test_rewrite_relative_urls_preserves_data_urls() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="data:image/png;base64,ABC123">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls handles href attributes.
	 */
	public function test_rewrite_relative_urls_handles_href() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<link href="css/style.css" rel="stylesheet">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'uploads/exelearning/', $result );
		$this->assertStringContainsString( 'css/style.css', $result );
	}

	/**
	 * Test rewrite_relative_urls handles poster attributes.
	 */
	public function test_rewrite_relative_urls_handles_poster() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<video poster="thumbnails/video.jpg"></video>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'uploads/exelearning/', $result );
		$this->assertStringContainsString( 'thumbnails/video.jpg', $result );
	}

	/**
	 * Test rewrite_relative_urls handles inline style url().
	 */
	public function test_rewrite_relative_urls_handles_inline_style() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<div style="background: url(images/bg.png)"></div>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'uploads/exelearning/', $result );
		$this->assertStringContainsString( 'images/bg.png', $result );
	}

	/**
	 * Test rewrite_relative_urls with file in subdirectory.
	 */
	public function test_rewrite_relative_urls_from_subdirectory() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="../images/logo.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, 'html/page.html' );

		$this->assertStringContainsString( 'images/logo.png', $result );
	}

	/**
	 * Test rewrite_relative_urls preserves javascript: links.
	 */
	public function test_rewrite_relative_urls_preserves_javascript() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<a href="javascript:void(0)">Click</a>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls preserves hash links.
	 */
	public function test_rewrite_relative_urls_preserves_hash() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<a href="#section">Section</a>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Links between package pages stay relative on path-based proxy URLs.
	 *
	 * The package JS only appends its mode parameters (exe-teacher,
	 * exe-presentation) to relative links: an absolute href loses them on
	 * navigation.
	 */
	public function test_rewrite_relative_urls_keeps_page_links_relative_with_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );

		$this->request( $this->page_uri( 'index.html' ) );
		$from_index = $method->invoke( $this->rewriter, '<a href="html/page-2.html">Next</a>', $hash, 'index.html' );
		$this->request( $this->page_uri( 'html/page-2.html' ) );
		$from_page  = $method->invoke( $this->rewriter, '<a href="../index.html#top">Home</a><a href="page-3.html">3</a>', $hash, 'html/page-2.html' );

		$this->assertSame( '<a href="html/page-2.html">Next</a>', $from_index );
		$this->assertSame( '<a href="../index.html#top">Home</a><a href="page-3.html">3</a>', $from_page );
	}

	/**
	 * Assets keep their absolute URLs on path-based proxy URLs.
	 */
	public function test_rewrite_relative_urls_keeps_absolute_assets_with_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$this->request( $this->page_uri( 'index.html' ) );
		$result = $method->invoke( $this->rewriter, '<link href="css/style.css"><img src="html/frame.html">', str_repeat( 'a', 40 ), 'index.html' );

		$this->assertStringContainsString( 'uploads/exelearning/' . str_repeat( 'a', 40 ) . '/css/style.css', $result );
		$this->assertStringContainsString( 'exelearning/v1/content/' . str_repeat( 'a', 40 ) . '/html/frame.html', $result );
	}

	/**
	 * Page links stay absolute with plain permalinks, where a relative href
	 * cannot resolve against the ?rest_route= URL.
	 */
	public function test_rewrite_relative_urls_keeps_page_links_absolute_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, '<a href="html/page-2.html">Next</a>', str_repeat( 'a', 40 ), 'index.html' );

		$this->assertStringContainsString( 'rest_route=', $result );
		$this->assertStringContainsString( 'html/page-2.html', $result );
	}

	/**
	 * Leading dots that belong to a file or folder name survive at the root.
	 */
	public function test_rewrite_relative_urls_keeps_dot_names_at_root() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );

		$result = $method->invoke( $this->rewriter, '<img src=".hidden/x.png"><link href="./.theme.css"><img src="../up.png">', $hash, 'index.html' );

		$this->assertStringContainsString( $hash . '/.hidden/x.png"', $result );
		$this->assertStringContainsString( $hash . '/.theme.css"', $result );
		$this->assertStringContainsString( $hash . '/up.png"', $result );
	}

	/**
	 * Only the path is normalized: slashes in a query or fragment survive,
	 * and so does a trailing directory slash.
	 */
	public function test_resolve_relative_path_keeps_query_fragment_and_trailing_slash() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$this->assertSame( 'viewer.html?file=https://example.com/a.pdf', $method->invoke( $this->rewriter, '', './viewer.html?file=https://example.com/a.pdf' ) );
		$this->assertSame( 'html/app.html#/route/', $method->invoke( $this->rewriter, 'html', 'app.html#/route/' ) );
		$this->assertSame( 'media/', $method->invoke( $this->rewriter, '', 'media/' ) );
	}

	/**
	 * A query-only href in a document requested through ?rest_route= on a
	 * pretty-permalink site points at the current page with its own query,
	 * and the decoded file name is encoded again.
	 */
	public function test_rewrite_relative_urls_points_query_only_href_at_current_page_for_rest_route_requests() {
		$this->set_permalink_structure( '/%postname%/' );
		$hash = str_repeat( 'a', 40 );
		$this->request( '/index.php?rest_route=/exelearning/v1/content/' . $hash . '/html/a%26b.html' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, '<a href="?exe-presentation=0">Leave</a>', $hash, 'html/a&b.html' );

		$this->assertStringContainsString( 'exelearning/v1/content/' . $hash . '/html/a%26b.html?exe-presentation=0"', $result );
	}

	/**
	 * A query-only href stays relative on path-based proxy URLs.
	 */
	public function test_rewrite_relative_urls_keeps_query_only_href_with_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$this->request( $this->page_uri( 'html/page-2.html' ) );
		$html   = '<a href="?exe-presentation=0">Leave</a>';
		$result = $method->invoke( $this->rewriter, $html, str_repeat( 'a', 40 ), 'html/page-2.html' );

		$this->assertSame( $html, $result );
	}

	/**
	 * With plain permalinks a query-only href points at the current page
	 * through the proxy, keeping its routing argument.
	 */
	public function test_rewrite_relative_urls_points_query_only_href_at_current_page_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );

		$result = $method->invoke( $this->rewriter, '<a href="?exe-presentation=0">Leave</a>', $hash, 'html/page-2.html' );

		$this->assertStringContainsString( 'rest_route=', $result );
		$this->assertStringContainsString( $hash . '/html/page-2.html&#038;exe-presentation=0"', $result );
		$this->assertStringNotContainsString( 'uploads/exelearning', $result );
	}

	/**
	 * A document requested through ?rest_route= (e.g. a URL stored before
	 * pretty permalinks were enabled) keeps absolute page links: a relative
	 * href would resolve against the site root.
	 */
	public function test_rewrite_relative_urls_keeps_page_links_absolute_for_rest_route_requests() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->request( '/index.php?rest_route=/exelearning/v1/content/' . str_repeat( 'a', 40 ) . '/index.html' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->rewriter, '<a href="html/page-2.html">Next</a>', str_repeat( 'a', 40 ), 'index.html' );

		$this->assertStringContainsString( 'exelearning/v1/content/' . str_repeat( 'a', 40 ) . '/html/page-2.html', $result );
	}

	/**
	 * A page link climbing above the package root stays absolute, so it is
	 * clamped to the root instead of leaving the hash directory.
	 */
	public function test_rewrite_relative_urls_clamps_page_links_above_root() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$this->request( $this->page_uri( 'index.html' ) );
		foreach ( array( '../index.html', 'html/../../index.html' ) as $href ) {
			$result = $method->invoke( $this->rewriter, '<a href="' . $href . '">Home</a>', str_repeat( 'a', 40 ), 'index.html' );

			$this->assertStringContainsString( 'exelearning/v1/content/' . str_repeat( 'a', 40 ) . '/index.html"', $result, $href );
		}
	}

	/**
	 * A <base> tag is rewritten like any href, so page links resolving against
	 * it must stay absolute.
	 */
	public function test_rewrite_relative_urls_keeps_page_links_absolute_with_base_tag() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$this->request( $this->page_uri( 'index.html' ) );
		$result = $method->invoke( $this->rewriter, '<base href="./"><a href="html/page-2.html">Next</a>', str_repeat( 'a', 40 ), 'index.html' );

		$this->assertStringContainsString( 'exelearning/v1/content/' . str_repeat( 'a', 40 ) . '/html/page-2.html', $result );
	}

	/**
	 * The route also serves URLs that are not the canonical one of the file
	 * (no file, trailing slash, doubled slash): relative links would resolve
	 * against the wrong directory there, so page links stay absolute.
	 */
	public function test_rewrite_relative_urls_keeps_page_links_absolute_for_non_canonical_requests() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );
		$base = '/wp-json/exelearning/v1/content/' . $hash;

		foreach ( array( $base, $base . '/', $base . '/index.html/', $base . '//index.html' ) as $uri ) {
			$this->request( $uri );
			$result = $method->invoke( $this->rewriter, '<a href="html/page-2.html">Next</a>', $hash, 'index.html' );

			$this->assertStringContainsString( 'exelearning/v1/content/' . $hash . '/html/page-2.html"', $result, $uri );
		}
	}

	/**
	 * A path can end like the canonical URL without being it: an encoded slash
	 * is a single segment for the browser, and a rest_route argument routes the
	 * request whatever the path says. Relative links would leave the proxy.
	 */
	public function test_rewrite_relative_urls_keeps_page_links_absolute_for_lookalike_paths() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );
		$uris = array(
			$this->page_uri( 'html%2Fpage-2.html' ),
			'/x/' . $hash . '/html/page-2.html?rest_route=/exelearning/v1/content/' . $hash . '/html/page-2.html',
		);

		foreach ( $uris as $uri ) {
			$this->request( $uri );
			$result = $method->invoke( $this->rewriter, '<a href="page-3.html">3</a>', $hash, 'html/page-2.html' );

			$this->assertStringContainsString( 'exelearning/v1/content/' . $hash . '/html/page-3.html"', $result, $uri );
		}
	}

	/**
	 * What counts is the URL in the browser: a server that rewrites /wp-json/
	 * into ?rest_route= internally, or a rest_url filter adding a query
	 * argument, still leaves a path-based document URL.
	 */
	public function test_rewrite_relative_urls_keeps_page_links_relative_whatever_the_server_routing() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );
		$html = '<a href="page-3.html">3</a>';
		$this->request( $this->page_uri( 'html/a%26b.html' ) . '?exe-teacher=1' );

		$_GET['rest_route'] = '/exelearning/v1/content/' . $hash . '/html/a&b.html';
		$this->assertSame( $html, $method->invoke( $this->rewriter, $html, $hash, 'html/a&b.html' ) );
		unset( $_GET['rest_route'] );

		$add_lang = static function ( $url ) {
			return add_query_arg( 'lang', 'es', $url );
		};
		add_filter( 'rest_url', $add_lang );
		$result = $method->invoke( $this->rewriter, $html, $hash, 'html/a&b.html' );
		remove_filter( 'rest_url', $add_lang );

		$this->assertSame( $html, $result );
	}

	/**
	 * The package JS cannot add its mode parameters to absolute page links, so
	 * the proxy carries the ones of the current request onto them.
	 */
	public function test_rewrite_relative_urls_carries_mode_params_on_absolute_page_links() {
		$this->set_permalink_structure( '' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash                     = str_repeat( 'a', 40 );
		$_GET['exe-teacher']      = '1';
		$_GET['exe-presentation'] = '"><script>';
		$_GET['other']            = 'x';

		$result = $method->invoke( $this->rewriter, '<a href="html/page-2.html#top">Next</a><link href="css/style.css"><iframe src="html/frame.html"></iframe>', $hash, 'index.html' );

		// A presentation value the proxy will not copy still means "offered, off".
		$this->assertStringContainsString( $hash . '/html/page-2.html&#038;exe-teacher=1&#038;exe-presentation=0#top"', $result );
		$this->assertStringContainsString( $hash . '/css/style.css"', $result );
		$this->assertStringContainsString( $hash . '/html/frame.html"', $result );
		$this->assertStringNotContainsString( 'script', $result );
		$this->assertStringNotContainsString( 'other=', $result );
	}

	/**
	 * A mode parameter the link sets itself is not repeated: the browser would
	 * read the link's copy and PHP the request's.
	 */
	public function test_rewrite_relative_urls_keeps_mode_params_set_by_the_link() {
		$this->set_permalink_structure( '' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash                     = str_repeat( 'a', 40 );
		$_GET['exe-teacher']      = '1';
		$_GET['exe-presentation'] = '1';

		$result = $method->invoke( $this->rewriter, '<a href="html/page-2.html?x=1&amp;exe-teacher=0">Next</a>', $hash, 'index.html' );

		// Plain permalinks: the link's query follows the rest_route value.
		$this->assertStringContainsString( $hash . '/html/page-2.html&#038;x=1&#038;exe-teacher=0&#038;exe-presentation=1"', $result );
		$this->assertStringNotContainsString( 'exe-teacher=1', $result );
	}

	/**
	 * The menu state travels only while presenting, as in the package JS:
	 * otherwise a reader who reopened the menu would get it hidden again.
	 */
	public function test_rewrite_relative_urls_carries_nav_only_while_presenting() {
		$this->set_permalink_structure( '' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash        = str_repeat( 'a', 40 );
		$html        = '<a href="html/page-2.html">Next</a>';
		$_GET['nav'] = 'false';

		$this->assertStringNotContainsString( 'nav=', $method->invoke( $this->rewriter, $html, $hash, 'index.html' ) );

		$_GET['exe-presentation'] = '0';
		$this->assertStringNotContainsString( 'nav=', $method->invoke( $this->rewriter, $html, $hash, 'index.html' ) );

		$_GET['exe-presentation'] = '1';
		$this->assertStringContainsString( 'exe-presentation=1&#038;nav=false"', $method->invoke( $this->rewriter, $html, $hash, 'index.html' ) );
	}

	/**
	 * Only the path decides whether a URL is an HTML page, not its query.
	 */
	public function test_is_html_path_ignores_query_and_fragment() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'is_html_path' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, 'html/page.html?x=1#top' ) );
		$this->assertFalse( $method->invoke( null, '?x=page.html' ) );
		$this->assertFalse( $method->invoke( null, '#page.html' ) );
	}

	/**
	 * A rest_url filter may add a query argument: the package path goes before
	 * it, never glued after it.
	 */
	public function test_rewrite_urls_put_the_path_before_a_filtered_query() {
		$this->set_permalink_structure( '/%postname%/' );
		$html_method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$html_method->setAccessible( true );
		$hash     = str_repeat( 'a', 40 );
		$add_lang = static function ( $url ) {
			return add_query_arg( 'lang', 'es', $url );
		};
		add_filter( 'rest_url', $add_lang );
		$html = $html_method->invoke( $this->rewriter, '<iframe src="html/frame.html"></iframe><a href="html/page-2.html?x=1#top">2</a><div style="background:url(img/bg.svg)"></div>', $hash, 'index.html' );
		$css  = $this->rewriter->rewrite_css_urls( 'a{background:url(../img/logo.svg)}', $hash, 'css/style.css' );
		remove_filter( 'rest_url', $add_lang );

		$this->assertStringContainsString( $hash . '/html/frame.html?lang=es"', $html );
		$this->assertStringContainsString( $hash . '/html/page-2.html?lang=es&#038;x=1#top"', $html );
		$this->assertStringContainsString( $hash . '/img/bg.svg?lang=es)', $html );
		$this->assertStringContainsString( $hash . '/img/logo.svg?lang=es)', $css );
	}

	/**
	 * With plain permalinks the link's own query must not end up inside the
	 * rest_route value.
	 */
	public function test_rewrite_relative_urls_keeps_link_query_out_of_rest_route() {
		$this->set_permalink_structure( '' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );

		$result = $method->invoke( $this->rewriter, '<a href="viewer.html?file=x.pdf#p2">PDF</a>', $hash, 'index.html' );

		$this->assertStringContainsString( 'rest_route=/exelearning/v1/content/' . $hash . '/viewer.html&#038;file=x.pdf#p2"', $result );
	}

	/**
	 * The hash and file come from the route: a query argument with the same
	 * name (viewer.html?file=x.pdf) belongs to the package page.
	 */
	public function test_serve_content_reads_hash_and_file_from_the_route() {
		$hash    = str_repeat( 'a', 40 );
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/' . $hash . '/viewer.html' );
		$request->set_url_params(
			array(
				'hash' => $hash,
				'file' => 'viewer.html',
			)
		);
		$request->set_query_params(
			array(
				'hash' => 'not-a-hash',
				'file' => '../../wp-config.php',
			)
		);

		$result = $this->proxy->serve_content( $request );

		// The route values pass validation and simply do not exist on disk.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * With plain permalinks the path travels inside ?rest_route=, where a
	 * literal + or & would be read as query syntax.
	 */
	public function test_rewrite_relative_urls_encodes_query_syntax_in_names_inside_rest_route() {
		$hash = str_repeat( 'a', 40 );
		$html = '<img src="img/a+b.svg"><img src="img/q&amp;a.svg"><img src="img/caf&eacute;.svg">';

		$this->set_permalink_structure( '' );
		$plain = $this->rewriter->rewrite_relative_urls( $html, $hash, 'index.html' );
		$this->set_permalink_structure( '/%postname%/' );
		$pretty = $this->rewriter->rewrite_relative_urls( $html, $hash, 'index.html' );

		$this->assertStringContainsString( $hash . '/img/a%2Bb.svg"', $plain );
		$this->assertStringContainsString( $hash . '/img/q%26a.svg"', $plain );
		$this->assertStringContainsString( $hash . '/img/caf&eacute;.svg"', $plain );
		// In a path they are ordinary characters.
		$this->assertStringContainsString( $hash . '/img/a+b.svg"', $pretty );
		$this->assertStringContainsString( $hash . '/img/q&#038;a.svg"', $pretty );
	}

	/**
	 * A url() keeps the delimiters its author wrote: an added double quote
	 * would end the style="" attribute.
	 */
	public function test_rewrite_relative_urls_keeps_inline_style_delimiters() {
		$hash = str_repeat( 'a', 40 );
		$base = ExeLearning_Content_Proxy::get_uploads_url( $hash );

		$result = $this->rewriter->rewrite_relative_urls( '<p style="background:url(img/bg.png)"></p><p style="background:url( \'img/bg.png\' )"></p>', $hash, 'index.html' );

		$this->assertSame( '<p style="background:url(' . $base . 'img/bg.png)"></p><p style="background:url( \'' . $base . 'img/bg.png\' )"></p>', $result );
	}

	/**
	 * A stylesheet is not HTML: the query of a reference keeps its plain "&".
	 */
	public function test_rewrite_css_urls_writes_no_html_entities() {
		$css = $this->rewriter->rewrite_css_urls( 'a{src:url("font.woff?a=1&b=2")}', str_repeat( 'a', 40 ), 'css/style.css' );

		$this->assertStringContainsString( '/css/font.woff?a=1&b=2")', $css );
	}

	/**
	 * Browsers serialize url() in a style attribute with entity quotes: they
	 * are delimiters, not part of the URL.
	 */
	public function test_rewrite_relative_urls_reads_entity_quoted_style_urls() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );

		$absolute = '<div style="background:url(&quot;https://cdn.example.com/x.png&quot;)"></div><div style="background:url(&#39;data:image/png;base64,AAAA&#39;)"></div>';
		$this->assertSame( $absolute, $method->invoke( $this->rewriter, $absolute, $hash, 'index.html' ) );

		$result = $method->invoke( $this->rewriter, '<div style="background:url(&quot;img/bg.png&quot;)"></div>', $hash, 'index.html' );
		$this->assertStringContainsString( 'url(&quot;http', $result );
		$this->assertStringContainsString( $hash . '/img/bg.png&quot;)"', $result );
	}

	/**
	 * A link to the package folder goes to the proxy, which serves its index:
	 * the uploads directory blocks direct access.
	 */
	public function test_rewrite_relative_urls_sends_package_root_links_to_the_proxy() {
		$this->set_permalink_structure( '/%postname%/' );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );
		$hash = str_repeat( 'a', 40 );

		$result  = $method->invoke( $this->rewriter, '<a href="../">Home</a>', $hash, 'html/page.html' );
		$result .= $method->invoke( $this->rewriter, '<a href="./#top">Top</a>', $hash, 'index.html' );

		$this->assertStringContainsString( 'exelearning/v1/content/' . $hash . '/"', $result );
		$this->assertStringContainsString( 'exelearning/v1/content/' . $hash . '/#top"', $result );
		$this->assertStringNotContainsString( 'uploads/exelearning', $result );
	}

	/**
	 * On a routed request the file comes from the route only: without one the
	 * package index is served, whatever a query argument named "file" says.
	 */
	public function test_route_param_ignores_the_query_on_routed_requests() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'route_param' );
		$method->setAccessible( true );
		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/' . str_repeat( 'a', 40 ) );
		$request->set_url_params( array( 'hash' => str_repeat( 'a', 40 ) ) );
		$request->set_query_params( array( 'file' => 'nope.pdf' ) );

		$this->assertNull( $method->invoke( null, $request, 'file' ) );

		$direct = new WP_REST_Request( 'GET', '/exelearning/v1/content/x' );
		$direct->set_param( 'file', 'index.html' );
		$this->assertSame( 'index.html', $method->invoke( null, $direct, 'file' ) );
	}

	/**
	 * URLs with a scheme (mailto:, tel:, about:) are not package paths.
	 */
	public function test_rewrite_relative_urls_preserves_other_schemes() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html = '<a href="mailto:info@example.com">Mail</a><a href="tel:+34600000000">Call</a><iframe src="about:blank"></iframe>';

		$this->assertSame( $html, $method->invoke( $this->rewriter, $html, str_repeat( 'a', 40 ), '' ) );
	}

	/**
	 * Test rewrite_relative_urls preserves protocol-relative URLs.
	 */
	public function test_rewrite_relative_urls_preserves_protocol_relative() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="//cdn.example.com/image.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls handles multiple attributes.
	 */
	public function test_rewrite_relative_urls_multiple_elements() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="a.png"><img src="b.png"><link href="c.css">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'a.png', $result );
		$this->assertStringContainsString( 'b.png', $result );
		$this->assertStringContainsString( 'c.css', $result );
	}

	/**
	 * Test resolve_relative_path handles traversal beyond root.
	 */
	public function test_resolve_relative_path_beyond_root() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		// Going up more levels than available should result in empty or valid path.
		$result = $method->invoke( $this->rewriter, 'a', '../../file.txt' );
		$this->assertEquals( 'file.txt', $result );
	}

	/**
	 * Test rewrite_relative_urls with empty src.
	 */
	public function test_rewrite_relative_urls_empty_src() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls with absolute path.
	 */
	public function test_rewrite_relative_urls_absolute_path() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="/images/logo.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		// Absolute paths starting with / should not be rewritten.
		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls routes HTML links through proxy.
	 */
	public function test_rewrite_relative_urls_html_links_use_proxy() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<a href="html/page2.html">Next</a>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringContainsString( 'html/page2.html', $result );
		$this->assertStringNotContainsString( 'uploads/exelearning/', $result );
	}

	/**
	 * Test rewrite_relative_urls routes HTM links through proxy.
	 */
	public function test_rewrite_relative_urls_htm_links_use_proxy() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<a href="page.htm">Page</a>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringNotContainsString( 'uploads/exelearning/', $result );
	}

	/**
	 * Test validate_hash with valid hash.
	 */
	public function test_validate_hash_valid() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_hash' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, str_repeat( 'a', 40 ) );
		$this->assertTrue( $result );
	}

	/**
	 * Test validate_hash with invalid hash.
	 */
	public function test_validate_hash_invalid() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_hash' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'invalid' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_hash', $result->get_error_code() );
	}

	/**
	 * Test validate_hash with null.
	 */
	public function test_validate_hash_null() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_hash' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, null );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test validate_hash with empty string.
	 */
	public function test_validate_hash_empty() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_hash' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, '' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test validate_file_path with empty file defaults to index.html.
	 */
	public function test_validate_file_path_empty_defaults() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_file_path' );
		$method->setAccessible( true );

		// Will return file_not_found because directory doesn't exist,
		// but we can verify it tried with index.html by the error.
		$result = $method->invoke( $this->proxy, '', str_repeat( 'a', 40 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test validate_file_path with traversal attempt.
	 */
	public function test_validate_file_path_traversal() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_file_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, '../../../etc/passwd', str_repeat( 'a', 40 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	/**
	 * Test validate_file_path with valid but non-existent file.
	 */
	public function test_validate_file_path_not_found() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_file_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'nonexistent.html', str_repeat( 'a', 40 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test validate_hash is private method.
	 */
	public function test_validate_hash_is_private() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_hash' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test validate_file_path is private method.
	 */
	public function test_validate_file_path_is_private() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_file_path' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test serve_file is private method.
	 */
	public function test_serve_file_is_private() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'serve_file' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Script-capable documents (html/htm/svg/xml) must be routed through the
	 * proxy; plain assets are served directly.
	 */
	public function test_is_proxied_path_covers_script_capable_types() {
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'is_proxied_path' );
		$method->setAccessible( true );

		foreach ( array( 'a.html', 'a.htm', 'a.svg', 'a.xml', 'dir/IMG.SVG', 'x.svg?v=1' ) as $p ) {
			$this->assertTrue( $method->invoke( null, $p ), $p );
		}
		foreach ( array( 'a.css', 'a.js', 'a.png', 'a.json', 'a.woff2' ) as $p ) {
			$this->assertFalse( $method->invoke( null, $p ), $p );
		}
	}

	/**
	 * A valid isolated content origin is applied to both the proxy and the
	 * uploads URLs.
	 */
	public function test_content_origin_filter_rewrites_urls() {
		$cb = static function () {
			return 'https://sandbox.example.org';
		};
		add_filter( 'exelearning_content_origin', $cb );

		$hash = str_repeat( 'a', 40 );
		$this->assertStringStartsWith( 'https://sandbox.example.org/', ExeLearning_Content_Proxy::get_proxy_url( $hash ) );
		$this->assertStringStartsWith( 'https://sandbox.example.org/', ExeLearning_Content_Proxy::get_uploads_url( $hash, 'style.css' ) );

		remove_filter( 'exelearning_content_origin', $cb );
	}

	/**
	 * An origin that is not a bare scheme://host (e.g. carries a path) is
	 * rejected, and URLs stay on the site origin.
	 */
	public function test_content_origin_rejects_non_bare_origin() {
		$cb = static function () {
			return 'https://evil.example.org/path';
		};
		add_filter( 'exelearning_content_origin', $cb );

		$this->assertSame( '', ExeLearning_Content_Proxy::content_origin() );
		$this->assertStringNotContainsString( 'evil.example.org', (string) ExeLearning_Content_Proxy::get_proxy_url( str_repeat( 'a', 40 ) ) );

		remove_filter( 'exelearning_content_origin', $cb );
	}

	/**
	 * Secure mode appends a `sandbox` directive so the document stays opaque even when
	 * opened outside the iframe (new tab / raw URL navigation).
	 */
	public function test_build_html_csp_secure_adds_sandbox() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'build_html_csp' );
		$method->setAccessible( true );

		$csp = $method->invoke( $this->proxy, "'self'", true );

		// The CSP sandbox must mirror the secure iframe tokens, incl. allow-forms, or
		// form-based iDevices are blocked by the CSP even though the iframe allows them.
		$this->assertStringContainsString( 'sandbox allow-scripts allow-popups allow-forms allow-downloads', $csp );
		$this->assertStringContainsString( "default-src 'self'", $csp );
		// fflate compresses the package's .elpx rebuild in blob: workers (ADR-156-01).
		$this->assertStringContainsString( "worker-src 'self' blob:", $csp );
		// Strict (default): no bare https: channels, so the served document cannot exfiltrate
		// the content URL; frame-src is limited to the maintained providers.
		$this->assertDoesNotMatchRegularExpression( '~\bhttps:(?!//)~', $csp );
		$this->assertStringContainsString( 'https://www.youtube-nocookie.com', $csp );
	}

	/**
	 * The compatible CSP profile re-opens img/media to https: (documented weaker), while the
	 * sandbox directive is unchanged.
	 */
	public function test_build_html_csp_compatible_profile_allows_https() {
		$callback = function () {
			return ExeLearning_Iframe_Sandbox::CSP_COMPATIBLE;
		};
		add_filter( 'exelearning_csp_profile', $callback );

		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'build_html_csp' );
		$method->setAccessible( true );
		$csp = $method->invoke( $this->proxy, "'self'", true );

		remove_filter( 'exelearning_csp_profile', $callback );

		$this->assertMatchesRegularExpression( '~img-src[^;]*\bhttps:(?!//)~', $csp );
		$this->assertStringContainsString( 'sandbox allow-scripts allow-popups allow-forms allow-downloads', $csp );
	}

	/**
	 * Without the sandbox flag (the dev-only legacy escape hatch) the CSP omits the sandbox
	 * directive.
	 */
	public function test_build_html_csp_without_sandbox_flag() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'build_html_csp' );
		$method->setAccessible( true );

		$csp = $method->invoke( $this->proxy, "'self'", false );

		$this->assertStringNotContainsString( 'sandbox', $csp );
		$this->assertStringContainsString( "default-src 'self'", $csp );
	}

	/**
	 * In secure mode the served HTML gets the external-embed shim inlined.
	 */
	public function test_inject_embed_shim_adds_shim_in_secure_mode() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'inject_embed_shim' );
		$method->setAccessible( true );

		$html = '<html><head></head><body><p>content</p></body></html>';
		$out  = $method->invoke( $this->proxy, $html );

		$this->assertStringContainsString( 'id="exelearning-embed-shim"', $out );
		// The shim source itself is inlined.
		$this->assertStringContainsString( 'data-exe-embed-id', $out );
		// Injected before the closing body tag.
		$this->assertMatchesRegularExpression( '#</script>\s*</body>#', $out );
	}

	/**
	 * The same-origin legacy admin mode was removed: a leftover option=legacy is ignored and
	 * the shim is still injected (the content always renders secure).
	 */
	public function test_inject_embed_shim_ignores_legacy_option() {
		update_option( ExeLearning_Iframe_Sandbox::OPTION, 'legacy' );

		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'inject_embed_shim' );
		$method->setAccessible( true );

		$html = '<html><head></head><body><p>content</p></body></html>';
		$out  = $method->invoke( $this->proxy, $html );

		$this->assertStringContainsString( 'id="exelearning-embed-shim"', $out );
	}

	/**
	 * The MIME map serves ES modules (.mjs) as JavaScript so module scripts
	 * execute under strict MIME checking (issue #53).
	 */
	public function test_mime_types_includes_mjs() {
		$property = new ReflectionProperty( ExeLearning_Content_Proxy::class, 'mime_types' );
		$property->setAccessible( true );
		$mime_types = $property->getValue( $this->proxy );

		$this->assertEquals( 'application/javascript', $mime_types['mjs'] );
	}

	/**
	 * The asset-proxy mode is disabled by default, preserving direct uploads URLs.
	 */
	public function test_is_asset_proxy_enabled_defaults_false() {
		delete_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS );
		$this->assertFalse( ExeLearning_Content_Proxy::is_asset_proxy_enabled() );
	}

	/**
	 * The asset-proxy mode reflects the stored option.
	 */
	public function test_is_asset_proxy_enabled_reads_option() {
		update_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, 1 );
		$this->assertTrue( ExeLearning_Content_Proxy::is_asset_proxy_enabled() );

		update_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, 0 );
		$this->assertFalse( ExeLearning_Content_Proxy::is_asset_proxy_enabled() );
	}

	/**
	 * The `exelearning_proxy_assets` filter overrides the stored option in
	 * both directions.
	 */
	public function test_is_asset_proxy_enabled_filter_overrides() {
		// Force on while the option is off.
		delete_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS );
		$on = static function () {
			return true;
		};
		add_filter( 'exelearning_proxy_assets', $on );
		$this->assertTrue( ExeLearning_Content_Proxy::is_asset_proxy_enabled() );
		remove_filter( 'exelearning_proxy_assets', $on );

		// Force off while the option is on.
		update_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, 1 );
		$off = static function () {
			return false;
		};
		add_filter( 'exelearning_proxy_assets', $off );
		$this->assertFalse( ExeLearning_Content_Proxy::is_asset_proxy_enabled() );
		remove_filter( 'exelearning_proxy_assets', $off );
	}

	/**
	 * With the asset-proxy mode disabled (default), only script-capable
	 * documents are proxied; ordinary assets are served directly.
	 */
	public function test_is_proxied_path_assets_direct_when_disabled() {
		delete_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'is_proxied_path' );
		$method->setAccessible( true );

		foreach ( array( 'a.html', 'a.htm', 'a.svg', 'a.xml' ) as $p ) {
			$this->assertTrue( $method->invoke( null, $p ), $p );
		}
		foreach ( array( 'a.css', 'a.js', 'a.mjs', 'a.png', 'a.json', 'a.woff2' ) as $p ) {
			$this->assertFalse( $method->invoke( null, $p ), $p );
		}
	}

	/**
	 * With the asset-proxy mode enabled, every known asset is proxied while
	 * script-capable documents remain proxied and extensionless paths stay
	 * direct.
	 */
	public function test_is_proxied_path_assets_proxied_when_enabled() {
		update_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, 1 );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'is_proxied_path' );
		$method->setAccessible( true );

		foreach ( array( 'a.css', 'a.js', 'a.mjs', 'a.png', 'a.json', 'a.woff2', 'a.html', 'a.svg', 'x.css?v=1' ) as $p ) {
			$this->assertTrue( $method->invoke( null, $p ), $p );
		}
		// Extensionless paths (e.g. pretty-permalink navigation) stay direct.
		$this->assertFalse( $method->invoke( null, 'page' ), 'page' );
	}

	/**
	 * When disabled, asset URLs in HTML keep pointing at the uploads directory.
	 */
	public function test_rewrite_relative_urls_assets_use_uploads_when_disabled() {
		delete_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$hash   = str_repeat( 'a', 40 );
		$html   = '<script src="js/app.js"></script><link href="css/main.css" rel="stylesheet">';
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'uploads/exelearning/', $result );
		$this->assertStringNotContainsString( 'exelearning/v1/content/', $result );
	}

	/**
	 * When enabled, asset URLs in HTML are rewritten to the proxy endpoint.
	 */
	public function test_rewrite_relative_urls_assets_use_proxy_when_enabled() {
		update_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, 1 );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$hash   = str_repeat( 'a', 40 );
		$html   = '<script src="js/app.js"></script><link href="css/main.css" rel="stylesheet">';
		$result = $method->invoke( $this->rewriter, $html, $hash, '' );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringContainsString( 'js/app.js', $result );
		$this->assertStringContainsString( 'css/main.css', $result );
		$this->assertStringNotContainsString( 'uploads/exelearning/', $result );
	}

	/**
	 * External and special URLs are never proxied, even with the mode enabled.
	 */
	public function test_rewrite_relative_urls_external_not_proxied_when_enabled() {
		update_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, 1 );
		$method = new ReflectionMethod( ExeLearning_Content_Url_Rewriter::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$hash = str_repeat( 'a', 40 );
		$html = '<script src="https://cdn.example.com/x.js"></script>'
			. '<img src="data:image/png;base64,AAAA">'
			. '<a href="#anchor">x</a>';

		$this->assertEquals( $html, $method->invoke( $this->rewriter, $html, $hash, '' ) );
	}

	/**
	 * Build the HTML policy through the private builder.
	 *
	 * @param string $frame_ancestors Source list for frame-ancestors.
	 * @return string[] Directives keyed by name, each a list of sources.
	 */
	private function html_csp_directives( $frame_ancestors = "'self'" ) {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'build_html_csp' );
		$method->setAccessible( true );
		$directives = array();
		foreach ( explode( '; ', $method->invoke( $this->proxy, $frame_ancestors, false ) ) as $directive ) {
			$sources                             = explode( ' ', $directive );
			$directives[ array_shift( $sources ) ] = $sources;
		}
		return $directives;
	}

	/**
	 * The interactive video iDevices load their player API from these origins.
	 */
	public function test_html_csp_allows_idevice_player_scripts() {
		$script_src = $this->html_csp_directives()['script-src'];

		foreach ( array(
			'https://www.youtube.com',
			'https://www.youtube-nocookie.com',
			'https://youtu.be',
			'https://player.vimeo.com',
			'https://mediateca.educa.madrid.org',
		) as $origin ) {
			$this->assertContains( $origin, $script_src );
		}
		// General-purpose CDNs stay out: WordPress.org disallows offloading scripts.
		$this->assertNotContains( 'https://cdn.jsdelivr.net', $script_src );
	}

	/**
	 * Scripts stay on an allowlist and package scripts still cannot call out.
	 */
	public function test_html_csp_keeps_scripts_and_connections_restricted() {
		$directives = $this->html_csp_directives( "'self' https://site.example" );

		$this->assertNotContains( 'https:', $directives['script-src'] );
		$this->assertNotContains( '*', $directives['script-src'] );
		$this->assertSame( array( "'self'" ), $directives['connect-src'] );
		// Strict profile: frames are limited to the maintained providers.
		$this->assertNotContains( 'https:', $directives['frame-src'] );
		$this->assertContains( 'https://player.vimeo.com', $directives['frame-src'] );
		$this->assertSame( array( "'self'", 'https://site.example' ), $directives['frame-ancestors'] );
	}

	/**
	 * PDF documents get the opaque-origin sandbox CSP in secure mode.
	 */
	public function test_select_csp_pdf_secure_gets_opaque_sandbox() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'select_csp' );
		$method->setAccessible( true );
		$this->assertEquals(
			'sandbox allow-scripts allow-popups allow-forms allow-downloads',
			$method->invoke( $this->proxy, 'application/pdf', "'self'", true )
		);
	}

	/**
	 * The opaque package reads its own files with fetch(), so secure mode lets any
	 * origin read them (never with credentials); legacy is same-origin.
	 */
	public function test_cors_allow_origin_only_in_secure_mode() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'cors_allow_origin' );
		$method->setAccessible( true );
		$this->assertSame( '*', $method->invoke( $this->proxy, true ) );
		$this->assertSame( '', $method->invoke( $this->proxy, false ) );
	}

	/**
	 * In legacy (same-origin) mode a PDF gets no CSP, matching the HTML legacy policy.
	 */
	public function test_select_csp_pdf_legacy_gets_no_policy() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'select_csp' );
		$method->setAccessible( true );
		$this->assertSame( '', $method->invoke( $this->proxy, 'application/pdf', "'self'", false ) );
	}

	/**
	 * Media types are treated like the PDF: opaque sandbox in secure mode.
	 */
	public function test_select_csp_media_secure_gets_opaque_sandbox() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'select_csp' );
		$method->setAccessible( true );
		$this->assertEquals(
			'sandbox allow-scripts allow-popups allow-forms allow-downloads',
			$method->invoke( $this->proxy, 'video/mp4', "'self'", true )
		);
	}

	/**
	 * SVG/XML keep the script-free lockdown.
	 */
	public function test_select_csp_svg_is_script_free() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'select_csp' );
		$method->setAccessible( true );
		$csp = $method->invoke( $this->proxy, 'image/svg+xml', "'self'", true );
		$this->assertStringContainsString( "script-src 'none'", $csp );
		$this->assertStringContainsString( 'sandbox', $csp );
	}

	/**
	 * HTML gets a sandbox directive in secure mode and none in legacy mode.
	 */
	public function test_select_csp_html_sandbox_follows_mode() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'select_csp' );
		$method->setAccessible( true );
		$secure = $method->invoke( $this->proxy, 'text/html', "'self'", true );
		$legacy = $method->invoke( $this->proxy, 'text/html', "'self'", false );
		$this->assertStringContainsString( 'sandbox allow-scripts allow-popups allow-forms allow-downloads', $secure );
		$this->assertStringNotContainsString( 'sandbox', $legacy );
	}

	/**
	 * A Sec-Fetch-Dest: document request is recognised as a top-level navigation.
	 */
	public function test_is_toplevel_navigation_true_for_document() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'is_toplevel_navigation' );
		$method->setAccessible( true );
		$saved = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? $_SERVER['HTTP_SEC_FETCH_DEST'] : null;

		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
		$this->assertTrue( $method->invoke( $this->proxy ) );

		if ( null === $saved ) {
			unset( $_SERVER['HTTP_SEC_FETCH_DEST'] );
		} else {
			$_SERVER['HTTP_SEC_FETCH_DEST'] = $saved;
		}
	}

	/**
	 * An embedded request (Sec-Fetch-Dest: iframe) is not a top-level navigation.
	 */
	public function test_is_toplevel_navigation_false_for_iframe() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'is_toplevel_navigation' );
		$method->setAccessible( true );
		$saved = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? $_SERVER['HTTP_SEC_FETCH_DEST'] : null;

		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';
		$this->assertFalse( $method->invoke( $this->proxy ) );

		if ( null === $saved ) {
			unset( $_SERVER['HTTP_SEC_FETCH_DEST'] );
		} else {
			$_SERVER['HTTP_SEC_FETCH_DEST'] = $saved;
		}
	}

	/**
	 * With no Sec-Fetch-Dest header we treat the request as embedded (serve content).
	 */
	public function test_is_toplevel_navigation_false_without_header() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'is_toplevel_navigation' );
		$method->setAccessible( true );
		$saved = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? $_SERVER['HTTP_SEC_FETCH_DEST'] : null;

		unset( $_SERVER['HTTP_SEC_FETCH_DEST'] );
		$this->assertFalse( $method->invoke( $this->proxy ) );

		if ( null !== $saved ) {
			$_SERVER['HTTP_SEC_FETCH_DEST'] = $saved;
		}
	}

	/**
	 * The top-level notice is a self-contained, script-free HTML document.
	 */
	public function test_toplevel_notice_is_standalone_document() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'build_toplevel_notice' );
		$method->setAccessible( true );
		$html = $method->invoke( $this->proxy );
		$this->assertStringContainsString( '<!doctype html>', $html );
		$this->assertStringContainsString( '<h1', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * The runtime injected into proxied content is the CANONICAL external-media child
	 * bundle vendored from eXeLearning core, not the superseded shim it replaced.
	 *
	 * Asserted on the BYTES the plugin will inject, because the path is what a refactor
	 * changes and the bytes are what a learner runs.
	 */
	public function test_injected_child_runtime_is_the_canonical_bundle() {
		$path = EXELEARNING_PLUGIN_DIR . 'assets/js/exe_external_media/exe-external-media-child.min.js';
		$this->assertFileExists( $path, 'the child bundle was not vendored' );

		$source = file_get_contents( $path );
		// A symbol only the canonical bundle defines.
		$this->assertStringContainsString( 'exeExternalMediaChild', $source );
		// The privilege boundary: the in-content half must not carry the trusted half.
		$this->assertStringNotContainsString( 'exeExternalMediaHost', $source );
		// exelearning/exelearning ADR-2199-09: these bytes are redistributed inside the
		// content, so the grant travels.
		$this->assertStringContainsString( 'AGPL-3.0-or-later OR GPL-3.0-or-later', $source );
	}

	/** The superseded shim must not be what gets injected, even if it is still on disk. */
	public function test_superseded_shim_is_not_injected() {
		$reflection = new \ReflectionClass( \ExeLearning_Content_Proxy::class );
		$method     = $reflection->getMethod( 'embed_shim_source' );
		$method->setAccessible( true );

		$injected = $method->invoke( null );

		$this->assertNotEmpty( $injected, 'nothing would be injected into proxied content' );
		$this->assertStringContainsString( 'exeExternalMediaChild', $injected );
	}

	/**
	 * The vendored copy is byte-identical to what eXeLearning core published.
	 *
	 * This repo holds the BYTES and verifies them, rather than a copy of the logic that
	 * could drift (exelearning/exelearning ADR-2199-12). CI runs the same check with a
	 * pinned build hash; this test is the fast local half of it.
	 */
	public function test_vendored_artifact_matches_its_manifest() {
		$dir      = EXELEARNING_PLUGIN_DIR . 'assets/js/exe_external_media/';
		$manifest = json_decode( file_get_contents( $dir . 'exe-external-media.manifest.json' ), true );

		$this->assertIsArray( $manifest['files'] ?? null, 'the manifest has no file list' );

		foreach ( $manifest['files'] as $half => $record ) {
			$this->assertFileExists( $dir . $record['path'], "{$half} is missing" );
			$this->assertSame(
				$record['sha256'],
				hash( 'sha256', file_get_contents( $dir . $record['path'] ) ),
				"{$half} does not match the digest core published"
			);
		}

		// Editing a file and its digest together is the obvious way around a per-file
		// check, so the build hash covers the digest list itself.
		$keys = array_keys( $manifest['files'] );
		sort( $keys );
		$lines = array_map( fn( $k ) => $k . ':' . $manifest['files'][ $k ]['sha256'], $keys );
		$this->assertSame( $manifest['buildHash'], hash( 'sha256', implode( "\n", $lines ) ) );
	}

	/** Control is raw postMessage: no provider SDK may be inside the host bundle. */
	public function test_host_bundle_carries_no_provider_sdk() {
		$host = file_get_contents(
			EXELEARNING_PLUGIN_DIR . 'assets/js/exe_external_media/exe-external-media-host.min.js'
		);

		$this->assertStringNotContainsString( 'YT.Player', $host );
		$this->assertStringNotContainsString( 'Vimeo.Player', $host );
		$this->assertStringContainsString( 'enablejsapi', $host );
	}
}
