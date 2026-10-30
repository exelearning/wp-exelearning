<?php
/**
 * Tests for the delivery half of ExeLearning_Content_Proxy.
 *
 * ContentProxyTest covers validation and URL rewriting in isolation. This file
 * exercises the code that actually answers a request: reading the file from the
 * extraction directory, rewriting its references and writing the body.
 *
 * serve_content() ends in exit(), so the tests drive the private serve_file()
 * seam it delegates to. The response headers themselves cannot be asserted: the
 * CLI runner has already flushed WordPress' bootstrap banner, so every header()
 * call is a no-op that neither headers_list() nor xdebug_get_headers() records.
 *
 * @package Exelearning
 */

/**
 * Class ContentProxyServeTest.
 *
 * @covers ExeLearning_Content_Proxy
 */
class ContentProxyServeTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Content_Proxy
	 */
	private $proxy;

	/**
	 * Extraction hash used by the fixture package.
	 *
	 * @var string
	 */
	private $hash;

	/**
	 * Absolute path of the fixture extraction directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Warnings raised while a proxy method was running, other than the
	 * unavoidable "headers already sent" notice from the CLI runner.
	 *
	 * @var string[]
	 */
	private $unexpected_errors = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->proxy = new ExeLearning_Content_Proxy();
		$this->hash  = sha1( 'content-proxy-serve-' . wp_rand() );

		$upload_dir = wp_upload_dir();
		$this->dir  = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $this->hash;

		wp_mkdir_p( $this->dir . '/css' );
		wp_mkdir_p( $this->dir . '/images' );

		delete_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		$this->recursive_delete( $this->dir );
		parent::tear_down();
	}

	/**
	 * An HTML document is served with its references rewritten: sibling pages
	 * go through the proxy, plain assets are linked straight from uploads.
	 */
	public function test_serve_file_rewrites_html_references() {
		$this->write(
			'index.html',
			'<html><head><link href="css/style.css" rel="stylesheet"></head>'
			. '<body><a href="page2.html">Next</a><img src="images/logo.png"></body></html>'
		);

		$output = $this->serve( 'index.html' );

		$this->assertStringContainsString(
			ExeLearning_Content_Proxy::get_proxy_url( $this->hash, 'page2.html' ),
			$output,
			'Sibling HTML pages must stay behind the proxy.'
		);
		$this->assertStringContainsString(
			ExeLearning_Content_Proxy::get_uploads_url( $this->hash, 'images/logo.png' ),
			$output,
			'Images must be linked directly from the uploads directory.'
		);
		$this->assertStringContainsString(
			ExeLearning_Content_Proxy::get_uploads_url( $this->hash, 'css/style.css' ),
			$output
		);
	}

	/**
	 * A stylesheet's url() references are resolved against the directory the
	 * stylesheet lives in, not against the package root.
	 */
	public function test_serve_file_resolves_css_urls_against_the_stylesheet_directory() {
		$this->write( 'css/style.css', 'body{background:url(../images/bg.png)}.i{background:url("icon.svg")}' );

		$output = $this->serve( 'css/style.css' );

		$this->assertStringContainsString(
			ExeLearning_Content_Proxy::get_uploads_url( $this->hash, 'images/bg.png' ),
			$output,
			'../ in a url() must resolve relative to the stylesheet.'
		);
		$this->assertStringContainsString(
			ExeLearning_Content_Proxy::get_proxy_url( $this->hash, 'css/icon.svg' ),
			$output,
			'SVG is script-capable and must be routed through the proxy.'
		);
	}

	/**
	 * Absolute and external url() references in CSS are left untouched.
	 */
	public function test_serve_file_leaves_absolute_css_urls_alone() {
		$css = '.a{background:url(/root.png)}.b{background:url(https://cdn.example.com/x.png)}';
		$this->write( 'css/absolute.css', $css );

		$this->assertSame( $css, $this->serve( 'css/absolute.css' ) );
	}

	/**
	 * Files that are neither HTML nor CSS are streamed byte for byte.
	 */
	public function test_serve_file_streams_other_assets_verbatim() {
		$bytes = "\x89PNG\r\n\x1a\n" . random_bytes( 64 );
		$this->write( 'images/logo.png', $bytes );

		$this->assertSame( $bytes, $this->serve( 'images/logo.png' ) );
	}

	/**
	 * A symlink that resolves outside the extraction directory is refused even
	 * though the path itself contains no traversal.
	 */
	public function test_symlink_escaping_the_extraction_directory_is_denied() {
		$outside = wp_upload_dir()['basedir'] . '/outside-' . wp_rand() . '.html';
		file_put_contents( $outside, 'secret' ); // phpcs:ignore
		symlink( $outside, $this->dir . '/escape.html' );

		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_file_path' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->proxy, 'escape.html', $this->hash );

		wp_delete_file( $outside );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'access_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A file that really lives inside the extraction directory resolves to its
	 * sanitized path and absolute location.
	 */
	public function test_validate_file_path_resolves_a_file_inside_the_extraction_dir() {
		$this->write( 'css/style.css', 'body{}' );

		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'validate_file_path' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->proxy, './css//style.css', $this->hash );

		$this->assertSame(
			array(
				'file'      => 'css/style.css',
				'full_path' => $this->dir . '/css/style.css',
			),
			$result
		);
	}

	/**
	 * The site origin used for frame-ancestors is derived from home_url(),
	 * including the port when the site runs on a non-default one.
	 */
	public function test_site_origin_includes_the_port() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'site_origin' );
		$method->setAccessible( true );

		$home = static function () {
			return 'https://example.org:8443/blog';
		};
		add_filter( 'home_url', $home );
		$origin = $method->invoke( null );
		remove_filter( 'home_url', $home );

		$this->assertSame( 'https://example.org:8443', $origin );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Write a file into the fixture extraction directory.
	 *
	 * @param string $relative Path relative to the extraction directory.
	 * @param string $contents File contents.
	 */
	private function write( $relative, $contents ) {
		file_put_contents( $this->dir . '/' . $relative, $contents ); // phpcs:ignore
	}

	/**
	 * Run the proxy's private serve_file() and capture what it writes.
	 *
	 * The proxy calls header(), which the CLI test runner cannot honour once
	 * WordPress' bootstrap has printed its banner. That single warning is
	 * swallowed; anything else is recorded and fails the test.
	 *
	 * @param string $file Path relative to the extraction directory.
	 * @return string Response body.
	 */
	private function serve( $file ) {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'serve_file' );
		$method->setAccessible( true );

		$this->unexpected_errors = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler
			function ( $errno, $errstr ) {
				if ( false === strpos( $errstr, 'Cannot modify header information' ) ) {
					$this->unexpected_errors[] = $errstr;
				}
				return true;
			}
		);

		ob_start();
		try {
			$method->invoke( $this->proxy, $this->dir . '/' . $file, $file, $this->hash );
			$output = ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();
			restore_error_handler();
			throw $e;
		}
		restore_error_handler();

		$this->assertSame( array(), $this->unexpected_errors, 'The proxy raised an unexpected PHP warning.' );

		return $output;
	}

	/**
	 * Scheme://host[:port] of the test site.
	 *
	 * @return string
	 */
	private function site_origin() {
		$parts  = wp_parse_url( home_url() );
		$origin = $parts['scheme'] . '://' . $parts['host'];
		return empty( $parts['port'] ) ? $origin : $origin . ':' . $parts['port'];
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_delete( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $entry ) {
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->recursive_delete( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * SVG is streamed as-is: it is served as an image, never rewritten as a
	 * document, so nothing inside it is turned into a same-origin URL.
	 */
	public function test_serve_file_streams_svg_verbatim() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><image href="images/logo.png"/></svg>';
		$this->write( 'images/icon.svg', $svg );

		$this->assertSame( $svg, $this->serve( 'images/icon.svg' ) );
	}

	/**
	 * A stylesheet at the package root resolves its references against the
	 * root, not against a phantom "." directory.
	 */
	public function test_serve_file_resolves_root_level_css_urls() {
		$this->write( 'main.css', 'body{background:url(images/bg.png)}' );

		$output = $this->serve( 'main.css' );

		$this->assertStringContainsString(
			ExeLearning_Content_Proxy::get_uploads_url( $this->hash, 'images/bg.png' ),
			$output
		);
	}

	/**
	 * Absolute url() references inside an inline style attribute are left
	 * alone, like every other absolute reference.
	 */
	public function test_serve_file_leaves_absolute_inline_style_urls_alone() {
		$html = '<div style="background:url(/theme/bg.png)"></div>';
		$this->write( 'index.html', $html );

		// Not assertSame: served HTML also carries the embed shim appended by
		// the proxy. What this test is about is the URL surviving untouched.
		$this->assertStringContainsString( $html, $this->serve( 'index.html' ) );
	}

	/**
	 * A home_url() without a usable scheme and host yields no site origin, so
	 * no malformed value can end up in a framing policy.
	 */
	public function test_site_origin_is_empty_for_an_unusable_home_url() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'site_origin' );
		$method->setAccessible( true );

		$home = static function () {
			return 'not a url';
		};
		add_filter( 'home_url', $home );
		$origin = $method->invoke( null );
		remove_filter( 'home_url', $home );

		$this->assertSame( '', $origin );
	}

	/**
	 * A stale-hash redirect carries the original query string forward, so a
	 * deep link keeps its parameters, but never the plain-permalink routing
	 * argument nor a parameter with no value to re-encode.
	 */
	public function test_a_redirect_preserves_only_meaningful_query_arguments() {
		$method = new ReflectionMethod( ExeLearning_Content_Proxy::class, 'add_preserved_query_args' );
		$method->setAccessible( true );

		$request = new WP_REST_Request( 'GET', '/exelearning/v1/content/x/index.html' );
		$request->set_query_params(
			array(
				'exe-teacher' => '1',
				'rest_route'  => '/exelearning/v1/content/x/index.html',
			)
		);
		$this->assertSame(
			'https://example.org/page.html?exe-teacher=1',
			$method->invoke( $this->proxy, 'https://example.org/page.html', $request )
		);

		$empty = new WP_REST_Request( 'GET', '/exelearning/v1/content/x/index.html' );
		$empty->set_query_params( array( 'rest_route' => '/x' ) );
		$this->assertSame(
			'https://example.org/page.html',
			$method->invoke( $this->proxy, 'https://example.org/page.html', $empty )
		);

		$blank = new WP_REST_Request( 'GET', '/exelearning/v1/content/x/index.html' );
		$blank->set_query_params( array( 'nothing' => null ) );
		$this->assertSame(
			'https://example.org/page.html',
			$method->invoke( $this->proxy, 'https://example.org/page.html', $blank )
		);
	}

}
