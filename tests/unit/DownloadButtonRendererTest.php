<?php
/**
 * Tests for ExeLearning_Download_Button_Renderer.
 *
 * @package Exelearning
 */

/**
 * Class DownloadButtonRendererTest.
 *
 * @covers ExeLearning_Download_Button_Renderer
 */
class DownloadButtonRendererTest extends WP_UnitTestCase {

	/**
	 * An attachment with a known title used by every test.
	 *
	 * @var int
	 */
	private $attachment_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->attachment_id = $this->factory->attachment->create(
			array(
				'post_title'     => 'my project',
				'post_mime_type' => 'application/zip',
			)
		);
		update_post_meta( $this->attachment_id, '_exelearning_extracted', str_repeat( 'a', 40 ) );
	}

	/**
	 * Empty format list yields no markup.
	 */
	public function test_render_returns_empty_for_no_formats() {
		$this->assertSame( '', ExeLearning_Download_Button_Renderer::render( $this->attachment_id, array() ) );
	}

	/**
	 * Single-format render is a plain button (no toggle/menu).
	 */
	public function test_render_with_single_format_is_plain() {
		$html = ExeLearning_Download_Button_Renderer::render( $this->attachment_id, array( 'elpx' ) );

		$this->assertStringContainsString( 'data-format="elpx"', $html );
		$this->assertStringContainsString( 'data-suffix=".elpx"', $html );
		$this->assertStringContainsString( 'exelearning-download__primary', $html );
		$this->assertStringNotContainsString( 'exelearning-download__toggle', $html );
		$this->assertStringNotContainsString( 'exelearning-download__menu', $html );
	}

	/**
	 * Multi-format render emits the dropdown.
	 */
	public function test_render_with_multiple_formats_emits_dropdown() {
		$html = ExeLearning_Download_Button_Renderer::render(
			$this->attachment_id,
			array( 'html5', 'scorm12', 'epub3' )
		);

		// Primary action is the first enabled format.
		$this->assertMatchesRegularExpression( '/exelearning-download__primary[^>]*data-format="html5"/', $html );
		// Dropdown carries the other two.
		$this->assertStringContainsString( '<ul class="exelearning-download__menu"', $html );
		$this->assertStringContainsString( 'data-format="scorm12"', $html );
		$this->assertStringContainsString( 'data-format="epub3"', $html );
		$this->assertStringContainsString( 'data-mime="application/epub+zip"', $html );
		$this->assertStringContainsString( 'data-suffix="_scorm.zip"', $html );
	}

	/**
	 * Container exposes attachment id, elp URL and slug as data attributes.
	 */
	public function test_render_exposes_attachment_metadata() {
		$html = ExeLearning_Download_Button_Renderer::render(
			$this->attachment_id,
			array( 'elpx', 'html5' )
		);

		$this->assertStringContainsString( 'data-attachment-id="' . $this->attachment_id . '"', $html );
		$this->assertStringContainsString( 'data-slug="my-project"', $html );
		// elp URL must be the raw attachment URL.
		$this->assertStringContainsString( 'data-elp-url="' . esc_attr( wp_get_attachment_url( $this->attachment_id ) ) . '"', $html );
	}

	/**
	 * Render sanitizes the incoming list (drops unknown ids).
	 */
	public function test_render_sanitizes_format_list() {
		$html = ExeLearning_Download_Button_Renderer::render(
			$this->attachment_id,
			array( 'unknown', 'epub3', 'scorm2004' )
		);
		$this->assertStringContainsString( 'data-format="epub3"', $html );
		$this->assertStringNotContainsString( 'data-format="unknown"', $html );
		$this->assertStringNotContainsString( 'data-format="scorm2004"', $html );
	}

	/**
	 * Enqueue assets registers the download script with a localized config.
	 */
	public function test_enqueue_assets_registers_script() {
		ExeLearning_Download_Button_Renderer::enqueue_assets();
		$this->assertTrue( wp_script_is( 'exelearning-download', 'enqueued' ) );

		// Verify the localized config object reaches the page.
		$data = wp_scripts()->get_data( 'exelearning-download', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'wpExeDownloadConfig', $data );
		$this->assertStringContainsString( 'exportersBundle', $data );
		$this->assertStringContainsString( 'editorUrl', $data );
		// The export base must be localized so subdirectory installs resolve the
		// export bootstrap endpoint against home_url() rather than the origin root.
		$this->assertStringContainsString( 'exportBase', $data );
	}

	/**
	 * A title with nothing sluggable still produces a usable download name.
	 */
	public function test_render_falls_back_to_an_id_based_slug() {
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_title'     => '###',
				'post_mime_type' => 'application/zip',
			)
		);

		$html = ExeLearning_Download_Button_Renderer::render( $attachment_id, array( 'elpx' ) );

		$this->assertStringContainsString( 'data-slug="exelearning-' . $attachment_id . '"', $html );
	}

	/**
	 * The extension is dropped from the download name, so the client-side
	 * exporter can append its own.
	 */
	public function test_render_strips_the_extension_from_the_slug() {
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_title'     => 'My Course.elpx',
				'post_mime_type' => 'application/zip',
			)
		);

		$html = ExeLearning_Download_Button_Renderer::render( $attachment_id, array( 'elpx' ) );

		$this->assertStringContainsString( 'data-slug="my-course"', $html );
	}

	/**
	 * An id with no matching format definition is skipped rather than
	 * rendered as an empty entry.
	 */
	public function test_unknown_format_ids_are_skipped() {
		$method = new ReflectionMethod( ExeLearning_Download_Button_Renderer::class, 'build_items' );
		$method->setAccessible( true );

		$items = $method->invoke( null, array( 'elpx', 'no-such-format' ), true );

		$this->assertSame( array( 'elpx' ), wp_list_pluck( $items, 'id' ) );
	}

}
