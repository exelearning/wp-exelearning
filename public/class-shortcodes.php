<?php
/**
 * Shortcodes handler for eXeLearning plugin.
 *
 * This class registers and manages shortcodes.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Shortcodes.
 *
 * Handles plugin shortcodes.
 */
class ExeLearning_Shortcodes {

	/**
	 * Registers plugin shortcodes.
	 */
	public function register_shortcodes() {
		add_shortcode( 'exelearning', array( $this, 'display_exelearning' ) );
	}

	/**
	 * Displays content for the eXeLearning shortcode.
	 *
	 * Usage:
	 * - [exelearning id="123"] - Display ELP content with default height
	 * - [exelearning id="123" height="800"] - Display with custom height
	 *
	 * @param array       $atts Shortcode attributes.
	 * @param string|null $content Enclosed content (not used, required by WordPress shortcode API).
	 *
	 * @return string Processed shortcode content.
	 */
	public function display_exelearning( $atts, $content = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress shortcode API.
		$atts = shortcode_atts(
			array(
				'id'                   => 0,
				'height'               => 600,
				'teacher_mode_visible' => '1',
			),
			$atts,
			'exelearning'
		);

		$file_id = intval( $atts['id'] );
		if ( ! $file_id ) {
			return $this->render_error( __( 'Invalid eXeLearning file ID.', 'exelearning' ) );
		}

		// Retrieve attachment details.
		$post = get_post( $file_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->render_error( __( 'eXeLearning file not found.', 'exelearning' ) );
		}

		// Get extracted directory and preview status.
		$extracted_dir        = get_post_meta( $file_id, '_exelearning_extracted', true );
		$has_preview          = get_post_meta( $file_id, '_exelearning_has_preview', true );
		$height               = absint( $atts['height'] );
		$teacher_mode_visible = ! in_array( strtolower( (string) $atts['teacher_mode_visible'] ), array( '0', 'false', 'no' ), true );

		// Get file info.
		$file_url = wp_get_attachment_url( $file_id );
		$title    = get_the_title( $file_id );

		if ( ! $extracted_dir || '1' !== $has_preview ) {
			// No preview available - show download link.
			return $this->render_no_preview( $title, $file_url );
		}

		// Build preview URL using secure proxy.
		$preview_url = ExeLearning_Content_Proxy::get_proxy_url( $extracted_dir );

		return $this->render_preview( $title, $preview_url, $height, $file_url, $teacher_mode_visible );
	}

	/**
	 * Render error message.
	 *
	 * @param string $message Error message.
	 * @return string HTML output.
	 */
	private function render_error( $message ) {
		return sprintf(
			'<div class="exelearning-shortcode exelearning-error">
                <p>%s</p>
            </div>',
			esc_html( $message )
		);
	}

	/**
	 * Render no-preview message with download link.
	 *
	 * @param string $title    Content title.
	 * @param string $file_url URL to the ELP file.
	 * @return string HTML output.
	 */
	private function render_no_preview( $title, $file_url ) {
		return sprintf(
			'<div class="exelearning-shortcode exelearning-no-preview">
                <div class="exelearning-notice">
                    <strong>%s</strong>
                    <p>%s</p>
                    <a href="%s" class="exelearning-download-link" download>
                        <span class="dashicons dashicons-download"></span>
                        %s
                    </a>
                </div>
            </div>',
			esc_html( $title ),
			esc_html__( 'This is a source file that cannot be previewed directly. Download it to open with eXeLearning.', 'exelearning' ),
			esc_url( $file_url ),
			esc_html__( 'Download file', 'exelearning' )
		);
	}

	/**
	 * Render preview iframe.
	 *
	 * @param string $title       Content title.
	 * @param string $preview_url URL to the preview index.html.
	 * @param int    $height      Height of the iframe.
	 * @param string $file_url    URL to the original ELP file.
	 * @param bool   $teacher_mode_visible Whether teacher mode toggler should be visible.
	 * @return string HTML output.
	 */
	private function render_preview( $title, $preview_url, $height, $file_url, $teacher_mode_visible = true ) {
		// Generate unique ID for this instance.
		$unique_id = 'exelearning-' . wp_unique_id();

		return sprintf(
			'<div class="exelearning-shortcode exelearning-preview" id="%s">
                <div class="exelearning-toolbar">
                    <span class="exelearning-title">%s</span>
                    <div class="exelearning-toolbar-actions">
                        <a href="%s" class="exelearning-toolbar-btn" download title="%s">
                            <span class="dashicons dashicons-download"></span>
                        </a>
                        <button type="button" class="exelearning-toolbar-btn exelearning-fullscreen-btn" title="%s">
                            <span class="dashicons dashicons-fullscreen-alt"></span>
                        </button>
                    </div>
                </div>
                <iframe
                    src="%s"
                    class="exelearning-iframe"
                    style="width: 100%%; height: %dpx; border: none;"
                    title="%s"
                    loading="lazy"
                    allow="fullscreen"
                    sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    referrerpolicy="no-referrer"
                ></iframe>
            </div>
            <script>
                (function() {
                    var container = document.getElementById("%s");
                    if (!container) return;

                    var btn = container.querySelector(".exelearning-fullscreen-btn");
                    var iframe = container.querySelector(".exelearning-iframe");

                    if (btn && iframe) {
                        btn.addEventListener("click", function() {
                            if (iframe.requestFullscreen) {
                                iframe.requestFullscreen();
                            } else if (iframe.webkitRequestFullscreen) {
                                iframe.webkitRequestFullscreen();
                            } else if (iframe.msRequestFullscreen) {
                                iframe.msRequestFullscreen();
                            }
                        });
                    }

                    if (!%s && iframe) {
                        var css = "#teacher-mode-toggler-wrapper { visibility: hidden !important; }";
                        var inject = function() {
                            try {
                                if (!iframe.contentDocument) return;
                                var d = iframe.contentDocument;
                                var st = d.createElement("style");
                                st.textContent = css;
                                (d.head || d.documentElement).appendChild(st);
                            } catch (e) {}
                        };
                        iframe.addEventListener("load", inject);
                        inject();
                    }
                })();
            </script>',
			esc_attr( $unique_id ),
			esc_html( $title ),
			esc_url( $file_url ),
			esc_attr__( 'Download source file', 'exelearning' ),
			esc_attr__( 'View fullscreen', 'exelearning' ),
			esc_url( $preview_url ),
			$height,
			esc_attr( $title ),
			esc_attr( $unique_id ),
			$teacher_mode_visible ? 'true' : 'false'
		);
	}
}
