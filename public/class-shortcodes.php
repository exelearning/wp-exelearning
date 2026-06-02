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
				'show_download'        => '0',
				'download_formats'     => '',
			),
			$atts,
			'exelearning'
		);

		$file_id = intval( $atts['id'] );

		/**
		 * Filters the shortcode attributes after defaults are merged and before rendering.
		 *
		 * Allows integrations to change presentation-level options (height,
		 * teacher mode, download button, download formats). The values the
		 * renderer relies on are re-sanitized below, so this filter cannot inject
		 * unsafe values, bypass the attachment/permission checks, or change which
		 * file is rendered in an unsafe way.
		 *
		 * @since 1.0.0
		 *
		 * @param array $atts    Sanitized shortcode attributes.
		 * @param int   $file_id Attachment ID parsed from the shortcode.
		 * @return array Shortcode attributes. Must be an array.
		 */
		$filtered_atts = apply_filters( 'exelearning_shortcode_atts', $atts, $file_id );
		if ( is_array( $filtered_atts ) ) {
			$atts = $filtered_atts;
		}

		// Recompute the file ID from the (possibly filtered) attributes.
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
		$show_download        = in_array( strtolower( (string) $atts['show_download'] ), array( '1', 'true', 'yes' ), true );
		$download_formats     = '' === $atts['download_formats']
			? ExeLearning_Download_Formats::default_ids()
			: ExeLearning_Download_Formats::sanitize( $atts['download_formats'] );

		// Get file info.
		$file_url = wp_get_attachment_url( $file_id );
		$title    = get_the_title( $file_id );

		$download_html = '';
		if ( $show_download && ! empty( $download_formats ) ) {
			ExeLearning_Download_Button_Renderer::enqueue_assets();
			$download_html = ExeLearning_Download_Button_Renderer::render( $file_id, $download_formats );
		}

		if ( ! $extracted_dir || '1' !== $has_preview ) {
			// No preview available - show download link.
			$html = $this->render_no_preview( $title, $file_url, $download_html );

			/** This filter is documented below where the preview branch returns. */
			return apply_filters( 'exelearning_shortcode_output', $html, $file_id, $atts );
		}

		// Build preview URL using secure proxy.
		$preview_url = ExeLearning_Content_Proxy::get_proxy_url( $extracted_dir );

		/**
		 * Filters the preview URL before it is rendered into the iframe.
		 *
		 * Allows integrations to wrap or adjust the preview URL. The value is
		 * still escaped with esc_url() at output time. This filter must NOT be
		 * used to bypass the plugin's content-proxy security model; pointing the
		 * iframe at an unverified external origin is unsupported.
		 *
		 * @since 1.0.0
		 *
		 * @param string $preview_url   Proxy preview URL.
		 * @param int    $file_id       Attachment ID.
		 * @param string $extracted_dir Extraction hash/directory for the attachment.
		 * @return string Preview URL.
		 */
		$preview_url = (string) apply_filters( 'exelearning_preview_url', $preview_url, $file_id, $extracted_dir );

		$html = $this->render_preview( $title, $preview_url, $height, $file_url, $teacher_mode_visible, $download_html );

		/**
		 * Filters the final shortcode HTML before it is returned.
		 *
		 * Receives the already-rendered, escaped HTML and lets themes or
		 * integrations wrap or modify it. The default output is unchanged when no
		 * callback is attached. Any HTML added by a callback is its own
		 * responsibility to keep safe.
		 *
		 * @since 1.0.0
		 *
		 * @param string $html    Rendered shortcode HTML.
		 * @param int    $file_id Attachment ID.
		 * @param array  $atts    Shortcode attributes used to render the output.
		 * @return string Shortcode HTML.
		 */
		return apply_filters( 'exelearning_shortcode_output', $html, $file_id, $atts );
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
	 * @param string $title         Content title.
	 * @param string $file_url      URL to the ELP file.
	 * @param string $download_html Pre-rendered multi-format download button, or empty string for the default link.
	 * @return string HTML output.
	 */
	private function render_no_preview( $title, $file_url, $download_html = '' ) {
		$fallback = sprintf(
			'<a href="%s" class="exelearning-download-link" download>
                <span class="dashicons dashicons-download"></span>
                %s
            </a>',
			esc_url( $file_url ),
			esc_html__( 'Download file', 'exelearning' )
		);

		return sprintf(
			'<div class="exelearning-shortcode exelearning-no-preview">
                <div class="exelearning-notice">
                    <strong>%s</strong>
                    <p>%s</p>
                    %s
                </div>
            </div>',
			esc_html( $title ),
			esc_html__( 'This is a source file that cannot be previewed directly. Download it to open with eXeLearning.', 'exelearning' ),
			'' !== $download_html ? $download_html : $fallback
		);
	}

	/**
	 * Render preview iframe.
	 *
	 * @param string $title                Content title.
	 * @param string $preview_url          URL to the preview index.html.
	 * @param int    $height               Height of the iframe.
	 * @param string $file_url             URL to the original ELP file.
	 * @param bool   $teacher_mode_visible Whether teacher mode toggler should be visible.
	 * @param string $download_html        Pre-rendered multi-format download button, or empty string for the default link.
	 * @return string HTML output.
	 */
	private function render_preview( $title, $preview_url, $height, $file_url, $teacher_mode_visible = true, $download_html = '' ) {
		// Generate unique ID for this instance.
		$unique_id = 'exelearning-' . wp_unique_id();

		$fallback_download = sprintf(
			'<a href="%s" class="exelearning-toolbar-btn" download title="%s">
                <span class="dashicons dashicons-download"></span>
            </a>',
			esc_url( $file_url ),
			esc_attr__( 'Download source file', 'exelearning' )
		);

		return sprintf(
			'<div class="exelearning-shortcode exelearning-preview" id="%s">
                <div class="exelearning-toolbar">
                    <span class="exelearning-title">%s</span>
                    <div class="exelearning-toolbar-actions">
                        %s
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
                    sandbox="allow-scripts allow-same-origin allow-popups"
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
                                if (d.getElementById("exelearning-teacher-mode-style")) return;
                                var st = d.createElement("style");
                                st.id = "exelearning-teacher-mode-style";
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
			'' !== $download_html ? $download_html : $fallback_download,
			esc_attr__( 'View fullscreen', 'exelearning' ),
			esc_url( $preview_url ),
			$height,
			esc_attr( $title ),
			esc_attr( $unique_id ),
			$teacher_mode_visible ? 'true' : 'false'
		);
	}
}
