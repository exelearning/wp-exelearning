<?php
/**
 * Viewer enhancements for shortcode and block embeds.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Adds responsive shortcode sizing and block-editor fullscreen behavior.
 */
class ExeLearning_Viewer_Enhancements {

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		add_filter( 'exelearning_shortcode_output', array( $this, 'add_responsive_height_behavior' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_fullscreen_script' ), 20 );
	}

	/**
	 * Make percentage heights responsive to the rendered embed width.
	 *
	 * A value such as height="75%" means that the preview area is 75% as tall
	 * as the rendered embed is wide. This gives the percentage a deterministic
	 * reference and keeps the iframe responsive when its container is resized.
	 *
	 * @param string $html    Rendered shortcode HTML.
	 * @param int    $file_id Attachment ID.
	 * @param array  $atts    Shortcode attributes.
	 * @return string Filtered shortcode HTML.
	 */
	public function add_responsive_height_behavior( $html, $file_id, $atts ) {
		if ( ! $file_id || ! is_array( $atts ) || false === strpos( $html, 'exelearning-preview' ) ) {
			return $html;
		}

		$height = isset( $atts['height'] ) ? strtolower( trim( (string) $atts['height'] ) ) : '';
		if ( ! preg_match( '/^([1-9][0-9]*)%$/', $height, $matches ) ) {
			return $html;
		}

		if ( ! preg_match( '/id="(exelearning-[A-Za-z0-9_-]+)"/', $html, $container_matches ) ) {
			return $html;
		}

		$percentage   = absint( $matches[1] );
		$container_id = $container_matches[1];

		$html .= sprintf(
			'<style>
                #%1$s[data-exelearning-responsive-height="1"] .exelearning-iframe,
                #%1$s[data-exelearning-responsive-height="1"] .exelearning-poster {
                    height: var(--exelearning-responsive-height) !important;
                }
            </style>
            <script>
                (function() {
                    var container = document.getElementById(%2$s);
                    if (!container || container.dataset.exelearningResponsiveHeight === "1") return;

                    var iframe = container.querySelector(".exelearning-iframe");
                    if (!iframe) return;

                    container.dataset.exelearningResponsiveHeight = "1";

                    function updateHeight() {
                        var width = container.clientWidth;
                        if (width <= 0) return;

                        container.style.setProperty(
                            "--exelearning-responsive-height",
                            Math.round(width * %3$d / 100) + "px"
                        );
                    }

                    updateHeight();
                    if (window.ResizeObserver) {
                        var observer = new ResizeObserver(updateHeight);
                        observer.observe(container);
                    } else {
                        window.addEventListener("resize", updateHeight);
                    }
                })();
            </script>',
			esc_attr( $container_id ),
			wp_json_encode( $container_id ),
			$percentage
		);

		return $html;
	}

	/**
	 * Enqueue editor behavior for the fullscreen preview button.
	 */
	public function enqueue_block_editor_fullscreen_script() {
		wp_enqueue_script(
			'exelearning-elp-block-fullscreen',
			plugins_url( '../assets/js/elp-upload-fullscreen.js', __FILE__ ),
			array( 'exelearning-elp-block' ),
			EXELEARNING_VERSION,
			true
		);
	}
}
