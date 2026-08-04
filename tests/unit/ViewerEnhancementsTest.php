<?php
/**
 * Tests for ExeLearning_Viewer_Enhancements.
 *
 * @package Exelearning
 */

/**
 * Class ViewerEnhancementsTest.
 *
 * A percentage height means "this fraction of the rendered width", which only the
 * browser can resolve. The filter answers it by appending a style rule and a small
 * script to the shortcode output, so what matters is both what it emits for a valid
 * request and how quietly it steps aside for everything else: the filter runs on
 * every embed on the site, including those it has no business touching.
 *
 * @covers ExeLearning_Viewer_Enhancements
 */
class ViewerEnhancementsTest extends WP_UnitTestCase {

	/**
	 * Instance under test.
	 *
	 * @var ExeLearning_Viewer_Enhancements
	 */
	private $enhancements;

	/**
	 * Shortcode output as the renderer produces it.
	 *
	 * @var string
	 */
	private $html;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->enhancements = new ExeLearning_Viewer_Enhancements();
		$this->html         =
			'<div id="exelearning-abc123" class="exelearning-preview">' .
			'<iframe class="exelearning-iframe" src="about:blank"></iframe>' .
			'</div>';
	}

	/**
	 * Run the filter with the default fixture markup.
	 *
	 * @param array $atts    Shortcode attributes.
	 * @param int   $file_id Attachment ID.
	 * @return string Filtered HTML.
	 */
	private function filter( $atts, $file_id = 42 ) {
		return $this->enhancements->add_responsive_height_behavior( $this->html, $file_id, $atts );
	}

	/**
	 * register_hooks() wires the shortcode filter and the block editor assets action.
	 */
	public function test_register_hooks_registers_callbacks() {
		$this->enhancements->register_hooks();

		$this->assertNotFalse(
			has_filter(
				'exelearning_shortcode_output',
				array( $this->enhancements, 'add_responsive_height_behavior' )
			)
		);
		// No editor-assets hook any more: the fullscreen button is wired by the
		// block's own edit component, because an API version 3 block renders
		// inside the canvas iframe where an outside script cannot reach it.
		$this->assertFalse(
			has_action(
				'enqueue_block_editor_assets',
				array( $this->enhancements, 'enqueue_block_editor_fullscreen_script' )
			)
		);
	}

	/**
	 * A percentage height appends the responsive sizing behavior.
	 */
	public function test_percentage_height_appends_behavior() {
		$output = $this->filter( array( 'height' => '75%' ) );

		$this->assertStringStartsWith( $this->html, $output, 'The original markup must survive untouched.' );
		$this->assertStringContainsString( '#exelearning-abc123[data-exelearning-responsive-height="1"]', $output );
		$this->assertStringContainsString( '--exelearning-responsive-height', $output );
		$this->assertStringContainsString( 'document.getElementById("exelearning-abc123")', $output );
		$this->assertStringContainsString( 'width * 75 / 100', $output );
	}

	/**
	 * The behavior observes the container when ResizeObserver is available and falls
	 * back to a window resize listener otherwise.
	 */
	public function test_behavior_covers_both_resize_paths() {
		$output = $this->filter( array( 'height' => '50%' ) );

		$this->assertStringContainsString( 'window.ResizeObserver', $output );
		$this->assertStringContainsString( 'observer.observe(container)', $output );
		$this->assertStringContainsString( 'window.addEventListener("resize", updateHeight)', $output );
	}

	/**
	 * Percentages are read as integers, whatever the surrounding whitespace or case.
	 */
	public function test_percentage_is_normalized() {
		$output = $this->filter( array( 'height' => '  100%  ' ) );

		$this->assertStringContainsString( 'width * 100 / 100', $output );
	}

	/**
	 * Absolute heights are the browser's business, not ours.
	 */
	public function test_absolute_height_is_left_alone() {
		$this->assertSame( $this->html, $this->filter( array( 'height' => '600px' ) ) );
	}

	/**
	 * A shortcode without a height attribute is left alone.
	 */
	public function test_missing_height_is_left_alone() {
		$this->assertSame( $this->html, $this->filter( array() ) );
	}

	/**
	 * Percentages that cannot describe a height are rejected rather than emitted as
	 * a rule the browser would have to guess at.
	 *
	 * @dataProvider provide_unusable_heights
	 *
	 * @param string $height Height attribute value.
	 */
	public function test_unusable_percentages_are_left_alone( $height ) {
		$this->assertSame( $this->html, $this->filter( array( 'height' => $height ) ) );
	}

	/**
	 * Height values that must not produce a rule.
	 *
	 * @return array[] Data sets.
	 */
	public function provide_unusable_heights() {
		return array(
			'zero'             => array( '0%' ),
			'leading zero'     => array( '075%' ),
			'fractional'       => array( '33.3%' ),
			'negative'         => array( '-50%' ),
			'empty'            => array( '' ),
			'percent alone'    => array( '%' ),
			'trailing content' => array( '75% !important' ),
		);
	}

	/**
	 * Output from another renderer, without the preview wrapper, is not ours to touch.
	 */
	public function test_foreign_markup_is_left_alone() {
		$html   = '<div id="exelearning-abc123">no preview class here</div>';
		$output = $this->enhancements->add_responsive_height_behavior( $html, 42, array( 'height' => '75%' ) );

		$this->assertSame( $html, $output );
	}

	/**
	 * Without a container id there is nothing for the script to attach to.
	 */
	public function test_markup_without_container_id_is_left_alone() {
		$html   = '<div class="exelearning-preview"><iframe class="exelearning-iframe"></iframe></div>';
		$output = $this->enhancements->add_responsive_height_behavior( $html, 42, array( 'height' => '75%' ) );

		$this->assertSame( $html, $output );
	}

	/**
	 * A missing attachment id means the shortcode never resolved a file.
	 */
	public function test_missing_file_id_is_left_alone() {
		$this->assertSame( $this->html, $this->filter( array( 'height' => '75%' ), 0 ) );
	}

	/**
	 * A filter caller that passes something other than an attribute array is ignored
	 * rather than allowed to reach the percentage parsing.
	 */
	public function test_non_array_attributes_are_left_alone() {
		$output = $this->enhancements->add_responsive_height_behavior( $this->html, 42, 'height=75%' );

		$this->assertSame( $this->html, $output );
	}

	/**
	 * The container id lands in the script as a JSON string, so an id that closed the
	 * string early could inject script into the page.
	 */
	public function test_container_id_is_encoded_for_the_script() {
		// The id pattern only admits word characters and dashes, so the realistic
		// worry is the rest of the attribute, which must not leak into the argument.
		$html   = '<div id="exelearning-a-b_c" class="exelearning-preview" data-x="\"><script>">' .
			'<iframe class="exelearning-iframe"></iframe></div>';
		$output = $this->enhancements->add_responsive_height_behavior( $html, 42, array( 'height' => '75%' ) );

		$this->assertStringContainsString( 'document.getElementById("exelearning-a-b_c")', $output );
		$this->assertStringContainsString( '#exelearning-a-b_c[data-exelearning-responsive-height="1"]', $output );
	}

	/**
	 * The editor no longer gets a separate fullscreen script.
	 *
	 * Under API version 3 the block renders inside the canvas iframe, where a
	 * script enqueued into the outer admin document never sees it. The button
	 * is wired by the block's own edit component instead.
	 */
	public function test_no_separate_fullscreen_script_is_enqueued() {
		do_action( 'enqueue_block_editor_assets' );

		$this->assertFalse( wp_script_is( 'exelearning-elp-block-fullscreen', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'exelearning-elp-block-fullscreen', 'registered' ) );
	}
}
