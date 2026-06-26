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
				'teacher_mode'         => '0',
				'teacher_mode_visible' => '0',
				'show_download'        => '0',
				'download_formats'     => '',
				'screenshot'           => 'no',
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
		$teacher_mode         = in_array( strtolower( (string) $atts['teacher_mode'] ), array( '1', 'true', 'yes' ), true );
		$teacher_mode_visible = ! in_array( strtolower( (string) $atts['teacher_mode_visible'] ), array( '0', 'false', 'no' ), true );
		$show_download        = in_array( strtolower( (string) $atts['show_download'] ), array( '1', 'true', 'yes' ), true );
		$download_formats     = '' === $atts['download_formats']
			? ExeLearning_Download_Formats::default_ids()
			: ExeLearning_Download_Formats::sanitize( $atts['download_formats'] );
		$screenshot           = strtolower( (string) $atts['screenshot'] );
		$screenshot           = in_array( $screenshot, array( 'poster', 'only' ), true ) ? $screenshot : 'no';

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

		$html = $this->render_embed( $title, $preview_url, $height, $file_url, $teacher_mode_visible, $download_html, $teacher_mode, $screenshot, $extracted_dir );

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
	 * Render the embeddable preview, honoring the requested screenshot mode.
	 *
	 * Resolves the package screenshot (only packages built with eXeLearning
	 * >= 4.0.1 ship a screenshot.png at the extraction root) and dispatches to
	 * the standalone-image, poster, or plain-iframe renderer. When no screenshot
	 * exists the poster/only modes gracefully fall back to the plain iframe.
	 *
	 * @param string $title                Content title.
	 * @param string $preview_url          Proxy preview URL.
	 * @param int    $height               Iframe height in pixels.
	 * @param string $file_url             URL to the original ELP file.
	 * @param bool   $teacher_mode_visible Whether the teacher-mode toggler stays visible.
	 * @param string $download_html        Pre-rendered download button, or empty string for the default link.
	 * @param bool   $teacher_mode         Whether teacher mode should be activated on load.
	 * @param string $screenshot           Screenshot mode: 'no', 'poster', or 'only'.
	 * @param string $extracted_dir        Extraction hash/directory for the attachment.
	 * @return string HTML output.
	 */
	private function render_embed( $title, $preview_url, $height, $file_url, $teacher_mode_visible, $download_html, $teacher_mode, $screenshot, $extracted_dir ) {
		$screenshot_url = ( 'no' !== $screenshot && $this->has_screenshot( $extracted_dir ) )
			? ExeLearning_Content_Proxy::get_uploads_url( $extracted_dir, 'screenshot.png' )
			: '';

		if ( 'only' === $screenshot && '' !== $screenshot_url ) {
			return $this->render_screenshot( $title, $screenshot_url, $file_url, $download_html );
		}

		$poster_url = ( 'poster' === $screenshot ) ? $screenshot_url : '';

		return $this->render_preview( $title, $preview_url, $height, $file_url, $teacher_mode_visible, $download_html, $teacher_mode, $poster_url );
	}

	/**
	 * Check whether an extracted package ships a screenshot.png at its root.
	 *
	 * Only packages built with eXeLearning >= 4.0.1 include this file, so the
	 * check is a best-effort filesystem lookup at render time.
	 *
	 * @param string $extracted_dir Extraction hash/directory for the attachment.
	 * @return bool True when screenshot.png exists in the extraction directory.
	 */
	private function has_screenshot( $extracted_dir ) {
		if ( empty( $extracted_dir ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $extracted_dir . '/screenshot.png';

		return file_exists( $path );
	}

	/**
	 * Render the package screenshot as a standalone image (no iframe).
	 *
	 * @param string $title          Content title.
	 * @param string $screenshot_url URL to the package screenshot.png.
	 * @param string $file_url       URL to the original ELP file.
	 * @param string $download_html  Pre-rendered multi-format download button, or empty string for the default link.
	 * @return string HTML output.
	 */
	private function render_screenshot( $title, $screenshot_url, $file_url, $download_html = '' ) {
		$unique_id = 'exelearning-' . wp_unique_id();

		$fallback_download = sprintf(
			'<a href="%s" class="exelearning-toolbar-btn" download title="%s">
                <span class="dashicons dashicons-download"></span>
            </a>',
			esc_url( $file_url ),
			esc_attr__( 'Download source file', 'exelearning' )
		);

		return sprintf(
			'<div class="exelearning-shortcode exelearning-screenshot" id="%s">
                <div class="exelearning-toolbar">
                    <span class="exelearning-title">%s</span>
                    <div class="exelearning-toolbar-actions">
                        %s
                    </div>
                </div>
                <img src="%s" alt="%s" class="exelearning-screenshot-img" loading="lazy" />
            </div>',
			esc_attr( $unique_id ),
			esc_html( $title ),
			'' !== $download_html ? $download_html : $fallback_download,
			esc_url( $screenshot_url ),
			esc_attr( $title )
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
	 * @param bool   $teacher_mode         Whether teacher mode should be activated on load.
	 * @param string $poster_url           Screenshot URL used as a click-to-load poster; empty for an immediate embed.
	 * @return string HTML output.
	 */
	private function render_preview( $title, $preview_url, $height, $file_url, $teacher_mode_visible = true, $download_html = '', $teacher_mode = false, $poster_url = '' ) {
		// Generate unique ID for this instance.
		$unique_id = 'exelearning-' . wp_unique_id();
		$is_poster = '' !== $poster_url;

		// Teacher-mode visibility is owned by eXeLearning core: exported packages hide
		// teacher-only content by default and expose an in-page "teacher layer" selector
		// through ?exe-teacher=1 (the selector appears but stays off until the viewer
		// turns it on). No host-side CSS/JS injection is needed — we carry the request on
		// the iframe src whenever this embed should offer the selector. The legacy
		// teacher_mode attribute (activate-on-load) folds into the same opt-in, since
		// core deliberately no longer auto-reveals from the URL.
		if ( $teacher_mode_visible || $teacher_mode ) {
			$preview_url .= ( false === strpos( $preview_url, '?' ) ? '?' : '&' ) . 'exe-teacher=1';
		}

		$fallback_download = sprintf(
			'<a href="%s" class="exelearning-toolbar-btn" download title="%s">
                <span class="dashicons dashicons-download"></span>
            </a>',
			esc_url( $file_url ),
			esc_attr__( 'Download source file', 'exelearning' )
		);

		// In poster mode the iframe loads lazily on click, so its src is deferred
		// to a data attribute and the iframe stays hidden until then.
		$iframe_src_attr = $is_poster
			? sprintf( 'data-src="%s"', esc_url( $preview_url ) )
			: sprintf( 'src="%s"', esc_url( $preview_url ) );

		$poster_html = '';
		if ( $is_poster ) {
			$poster_html = sprintf(
				'<button type="button" class="exelearning-poster" style="height: %dpx;">
                    <img src="%s" alt="%s" class="exelearning-poster-img" loading="lazy" />
                    <span class="exelearning-poster-play dashicons dashicons-controls-play" aria-hidden="true"></span>
                    <span class="screen-reader-text">%s</span>
                </button>',
				$height,
				esc_url( $poster_url ),
				esc_attr( $title ),
				esc_html__( 'Load interactive content', 'exelearning' )
			);
		}

		$iframe_html = sprintf(
			'<iframe
                %s
                class="exelearning-iframe"
                style="width: 100%%; height: %dpx; border: none;%s"
                title="%s"
                loading="lazy"
                allow="fullscreen"
                sandbox="allow-scripts allow-same-origin allow-popups"
                referrerpolicy="no-referrer"
            ></iframe>',
			$iframe_src_attr,
			$height,
			$is_poster ? ' display: none;' : '',
			esc_attr( $title )
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
                %s
                %s
            </div>%s',
			esc_attr( $unique_id ),
			esc_html( $title ),
			'' !== $download_html ? $download_html : $fallback_download,
			esc_attr__( 'View fullscreen', 'exelearning' ),
			$poster_html,
			$iframe_html,
			$this->render_preview_script( $unique_id, $is_poster )
		);
	}

	/**
	 * Build the inline behavior script for a preview iframe.
	 *
	 * Teacher-mode visibility is handled by eXeLearning core through the
	 * ?exe-teacher=1 query parameter on the iframe src, so no host-side CSS/JS
	 * injection is emitted here. The script only wires the optional poster
	 * click-to-load behavior.
	 *
	 * @param string $unique_id Container element ID.
	 * @param bool   $is_poster Whether the iframe loads lazily from a poster.
	 * @return string Inline <script> markup.
	 */
	private function render_preview_script( $unique_id, $is_poster ) {
		$body = '';

		if ( $is_poster ) {
			$body .= '
                    var poster = container.querySelector(".exelearning-poster");
                    if (poster && iframe) {
                        poster.addEventListener("click", function() {
                            var src = iframe.getAttribute("data-src");
                            if (src && !iframe.getAttribute("src")) {
                                iframe.setAttribute("src", src);
                            }
                            iframe.style.display = "";
                            poster.style.display = "none";
                        });
                    }';
		}

		return sprintf(
			'<script>
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
                    }%s
                })();
            </script>',
			esc_attr( $unique_id ),
			$body
		);
	}
}
