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
 */
class ContentProxyTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Content_Proxy
	 */
	private $proxy;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->proxy = new ExeLearning_Content_Proxy();
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
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, '', 'file.html' );
		$this->assertEquals( 'file.html', $result );
	}

	/**
	 * Test resolve_relative_path with parent directory traversal.
	 */
	public function test_resolve_relative_path_parent_traversal() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'html/css', '../images/logo.png' );
		$this->assertEquals( 'html/images/logo.png', $result );
	}

	/**
	 * Test resolve_relative_path with multiple parent traversals.
	 */
	public function test_resolve_relative_path_multiple_traversals() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'a/b/c', '../../d/file.txt' );
		$this->assertEquals( 'a/d/file.txt', $result );
	}

	/**
	 * Test resolve_relative_path with current directory references.
	 */
	public function test_resolve_relative_path_current_dir() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'folder', './file.html' );
		$this->assertEquals( 'folder/file.html', $result );
	}

	/**
	 * Test resolve_relative_path with leading dot-slash in empty base.
	 */
	public function test_resolve_relative_path_empty_base_with_dotslash() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, '', './file.html' );
		$this->assertEquals( 'file.html', $result );
	}

	/**
	 * Test resolve_relative_path with nested path.
	 */
	public function test_resolve_relative_path_nested() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->proxy, 'html', 'css/style.css' );
		$this->assertEquals( 'html/css/style.css', $result );
	}

	/**
	 * Test rewrite_relative_urls with basic HTML.
	 */
	public function test_rewrite_relative_urls_basic() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="images/logo.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringContainsString( 'images/logo.png', $result );
	}

	/**
	 * Test rewrite_relative_urls preserves absolute URLs.
	 */
	public function test_rewrite_relative_urls_preserves_absolute() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="https://example.com/image.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls preserves data URLs.
	 */
	public function test_rewrite_relative_urls_preserves_data_urls() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="data:image/png;base64,ABC123">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls handles href attributes.
	 */
	public function test_rewrite_relative_urls_handles_href() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<link href="css/style.css" rel="stylesheet">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringContainsString( 'css/style.css', $result );
	}

	/**
	 * Test rewrite_relative_urls handles poster attributes.
	 */
	public function test_rewrite_relative_urls_handles_poster() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<video poster="thumbnails/video.jpg"></video>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringContainsString( 'thumbnails/video.jpg', $result );
	}

	/**
	 * Test rewrite_relative_urls handles inline style url().
	 */
	public function test_rewrite_relative_urls_handles_inline_style() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<div style="background: url(images/bg.png)"></div>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertStringContainsString( 'exelearning/v1/content/', $result );
		$this->assertStringContainsString( 'images/bg.png', $result );
	}

	/**
	 * Test rewrite_relative_urls with file in subdirectory.
	 */
	public function test_rewrite_relative_urls_from_subdirectory() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="../images/logo.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, 'html/page.html' );

		$this->assertStringContainsString( 'images/logo.png', $result );
	}

	/**
	 * Test rewrite_relative_urls preserves javascript: links.
	 */
	public function test_rewrite_relative_urls_preserves_javascript() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<a href="javascript:void(0)">Click</a>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls preserves hash links.
	 */
	public function test_rewrite_relative_urls_preserves_hash() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<a href="#section">Section</a>';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls preserves protocol-relative URLs.
	 */
	public function test_rewrite_relative_urls_preserves_protocol_relative() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="//cdn.example.com/image.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls handles multiple attributes.
	 */
	public function test_rewrite_relative_urls_multiple_elements() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="a.png"><img src="b.png"><link href="c.css">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertStringContainsString( 'a.png', $result );
		$this->assertStringContainsString( 'b.png', $result );
		$this->assertStringContainsString( 'c.css', $result );
	}

	/**
	 * Test resolve_relative_path handles traversal beyond root.
	 */
	public function test_resolve_relative_path_beyond_root() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'resolve_relative_path' );
		$method->setAccessible( true );

		// Going up more levels than available should result in empty or valid path.
		$result = $method->invoke( $this->proxy, 'a', '../../file.txt' );
		$this->assertEquals( 'file.txt', $result );
	}

	/**
	 * Test rewrite_relative_urls with empty src.
	 */
	public function test_rewrite_relative_urls_empty_src() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		$this->assertEquals( $html, $result );
	}

	/**
	 * Test rewrite_relative_urls with absolute path.
	 */
	public function test_rewrite_relative_urls_absolute_path() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'rewrite_relative_urls' );
		$method->setAccessible( true );

		$html   = '<img src="/images/logo.png">';
		$hash   = str_repeat( 'a', 40 );
		$result = $method->invoke( $this->proxy, $html, $hash, '' );

		// Absolute paths starting with / should not be rewritten.
		$this->assertEquals( $html, $result );
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
}
