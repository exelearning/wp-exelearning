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
	 * - [exelearning id="123" height="800"] - Display with a custom pixel height
	 * - [exelearning id="123" height="75%"] - Display with a percentage height
	 * - [exelearning id="123" fullscreen="0"] - Hide the fullscreen button
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
				'fullscreen'           => '1',
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

		// The toolbar relies on the plugin stylesheet and the Dashicons font,
		// which are not loaded on the public frontend by default.
		$this->enqueue_frontend_assets();

		// Get extracted directory and preview status.
		$extracted_dir        = get_post_meta( $file_id, '_exelearning_extracted', true );
		$has_preview          = get_post_meta( $file_id, '_exelearning_has_preview', true );
		$height               = $this->sanitize_height_value( $atts['height'] );
		$teacher_mode         = in_array( strtolower( (string) $atts['teacher_mode'] ), array( '1', 'true', 'yes' ), true );
		$teacher_mode_visible = ! in_array( strtolower( (string) $atts['teacher_mode_visible'] ), array( '0', 'false', 'no' ), true );
		$show_download        = in_array( strtolower( (string) $atts['show_download'] ), array( '1', 'true', 'yes' ), true );
		$fullscreen           = $this->parse_boolean_attribute( $atts['fullscreen'], true );
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

		// Carry the eXeLearning teacher-layer opt-in on the iframe src when this
		// embed should offer the selector (see helper for the rationale).
		$preview_url = $this->maybe_append_teacher_param( $preview_url, $teacher_mode_visible, $teacher_mode );

		$html = $this->render_embed( $title, $preview_url, $height, $file_url, $download_html, $screenshot, $extracted_dir, $fullscreen );

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
	 * @param string $title         Content title.
	 * @param string $preview_url   Proxy preview URL (already carrying any ?exe-teacher opt-in).
	 * @param string $height        Sanitized CSS height (e.g. "600px" or "75%").
	 * @param string $file_url      URL to the original ELP file.
	 * @param string $download_html Pre-rendered download button, or empty string for the default link.
	 * @param string $screenshot    Screenshot mode: 'no', 'poster', or 'only'.
	 * @param string $extracted_dir Extraction hash/directory for the attachment.
	 * @param bool   $fullscreen    Whether the fullscreen button is offered.
	 * @return string HTML output.
	 */
	private function render_embed( $title, $preview_url, $height, $file_url, $download_html, $screenshot, $extracted_dir, $fullscreen = true ) {
		$screenshot_url = ( 'no' !== $screenshot && $this->has_screenshot( $extracted_dir ) )
			? ExeLearning_Content_Proxy::get_uploads_url( $extracted_dir, 'screenshot.png' )
			: '';

		if ( 'only' === $screenshot && '' !== $screenshot_url ) {
			return $this->render_screenshot( $title, $screenshot_url, $file_url, $download_html );
		}

		$poster_url = ( 'poster' === $screenshot ) ? $screenshot_url : '';

		return $this->render_preview( $title, $preview_url, $height, $file_url, $download_html, $poster_url, $fullscreen );
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

		$fallback_download = $this->render_toolbar_download_fallback( $file_url );

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
	 * @param string $title         Content title.
	 * @param string $preview_url   URL to the preview index.html (already carrying any ?exe-teacher opt-in).
	 * @param string $height        Sanitized CSS height (e.g. "600px" or "75%").
	 * @param string $file_url      URL to the original ELP file.
	 * @param string $download_html Pre-rendered multi-format download button, or empty string for the default link.
	 * @param string $poster_url    Screenshot URL used as a click-to-load poster; empty for an immediate embed.
	 * @param bool   $fullscreen    Whether the fullscreen button is offered.
	 * @return string HTML output.
	 */
	private function render_preview( $title, $preview_url, $height, $file_url, $download_html = '', $poster_url = '', $fullscreen = true ) {
		// Generate unique ID for this instance.
		$unique_id = 'exelearning-' . wp_unique_id();
		$is_poster = '' !== $poster_url;

		$fallback_download = $this->render_toolbar_download_fallback( $file_url );

		// The fullscreen button is opt-out: rendered by default, omitted (with its
		// click handler) when the fullscreen attribute is disabled.
		$fullscreen_html = '';
		if ( $fullscreen ) {
			$fullscreen_html = sprintf(
				'<button type="button" class="exelearning-toolbar-btn exelearning-fullscreen-btn" aria-label="%1$s" title="%1$s">
                            <span class="dashicons dashicons-fullscreen-alt" aria-hidden="true"></span>
                        </button>',
				esc_attr__( 'View fullscreen', 'exelearning' )
			);
		}

		// In poster mode the iframe loads lazily on click, so its src is deferred
		// to a data attribute and the iframe stays hidden until then.
		$iframe_src_attr = $is_poster
			? sprintf( 'data-src="%s"', esc_url( $preview_url ) )
			: sprintf( 'src="%s"', esc_url( $preview_url ) );

		$poster_html = '';
		if ( $is_poster ) {
			$poster_html = sprintf(
				'<button type="button" class="exelearning-poster" style="height: %s;">
                    <img src="%s" alt="%s" class="exelearning-poster-img" loading="lazy" />
                    <span class="exelearning-poster-play dashicons dashicons-controls-play" aria-hidden="true"></span>
                    <span class="screen-reader-text">%s</span>
                </button>',
				esc_attr( $height ),
				esc_url( $poster_url ),
				esc_attr( $title ),
				esc_html__( 'Load interactive content', 'exelearning' )
			);
		}

		$iframe_html = sprintf(
			'<iframe
                %s
                class="exelearning-iframe"
                style="width: 100%%; height: %s; border: none;%s"
                title="%s"
                loading="lazy"
                allow="fullscreen"
                sandbox="allow-scripts allow-same-origin allow-popups"
                referrerpolicy="no-referrer"
            ></iframe>',
			$iframe_src_attr,
			esc_attr( $height ),
			$is_poster ? ' display: none;' : '',
			esc_attr( $title )
		);

		return sprintf(
			'<div class="exelearning-shortcode exelearning-preview" id="%1$s">
                <div class="exelearning-toolbar">
                    <span class="exelearning-title">%2$s</span>
                    <div class="exelearning-toolbar-actions">
                        %3$s
                        %4$s
                    </div>
                </div>
                %5$s
                %6$s
            </div>%7$s',
			esc_attr( $unique_id ),
			esc_html( $title ),
			'' !== $download_html ? $download_html : $fallback_download,
			$fullscreen_html,
			$poster_html,
			$iframe_html,
			$this->render_preview_script( $unique_id, $is_poster, $fullscreen )
		);
	}

	/**
	 * Build the inline behavior script for a preview iframe.
	 *
	 * Wires only the behaviors present in this instance: the optional fullscreen
	 * button and the optional poster click-to-load. When neither is enabled no
	 * script is emitted. Each block is scoped to the instance container so
	 * multiple embeds on one page stay independent.
	 *
	 * Teacher-mode visibility is handled by eXeLearning core through the
	 * ?exe-teacher=1 query parameter on the iframe src, so no host-side CSS/JS
	 * injection is emitted here.
	 *
	 * @param string $unique_id  Container element ID.
	 * @param bool   $is_poster  Whether the iframe loads lazily from a poster.
	 * @param bool   $fullscreen Whether the fullscreen button is present.
	 * @return string Inline <script> markup, or '' when no behavior is needed.
	 */
	private function render_preview_script( $unique_id, $is_poster, $fullscreen = true ) {
		if ( ! $is_poster && ! $fullscreen ) {
			return '';
		}

		$body = '';

		if ( $fullscreen ) {
			$body .= '
                    var btn = container.querySelector(".exelearning-fullscreen-btn");
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
                    }';
		}

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

                    var iframe = container.querySelector(".exelearning-iframe");%s
                })();
            </script>',
			esc_attr( $unique_id ),
			$body
		);
	}

	/**
	 * Render the icon-only fallback download control shown in the toolbar.
	 *
	 * Used when no multi-format download button is provided. Shares the
	 * .exelearning-toolbar-btn class with the fullscreen button so both controls
	 * render identically, and exposes an accessible name through aria-label
	 * (the Dashicon itself is decorative and hidden from assistive technology).
	 *
	 * @param string $file_url URL to the original ELP file.
	 * @return string HTML output.
	 */
	private function render_toolbar_download_fallback( $file_url ) {
		return sprintf(
			'<a href="%1$s" class="exelearning-toolbar-btn" download aria-label="%2$s" title="%2$s">
                            <span class="dashicons dashicons-download" aria-hidden="true"></span>
                        </a>',
			esc_url( $file_url ),
			esc_attr__( 'Download source file', 'exelearning' )
		);
	}

	/**
	 * Enqueue the frontend stylesheet and the Dashicons font used by the toolbar.
	 *
	 * Shortcodes render during the_content, so the plugin cannot rely on an
	 * earlier wp_enqueue_scripts pass. Dashicons in particular is not loaded on
	 * the public frontend by default, which would leave the toolbar icons blank.
	 * Both handles are de-duplicated by WordPress, so repeat calls are cheap.
	 */
	private function enqueue_frontend_assets() {
		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'exelearning-frontend',
			plugins_url( '../assets/css/exelearning.css', __FILE__ ),
			array(),
			defined( 'EXELEARNING_VERSION' ) ? EXELEARNING_VERSION : false
		);
	}

	/**
	 * Parse a boolean-like shortcode attribute.
	 *
	 * Accepts 1/true/yes/on as true and 0/false/no/off as false (case- and
	 * whitespace-insensitive). Any other value falls back to the supplied
	 * default so unexpected input cannot flip the control on or off unsafely.
	 *
	 * @param mixed $value    Raw attribute value.
	 * @param bool  $fallback Value returned for unrecognized input.
	 * @return bool Parsed boolean.
	 */
	private function parse_boolean_attribute( $value, $fallback = false ) {
		$normalized = strtolower( trim( (string) $value ) );

		if ( in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $normalized, array( '0', 'false', 'no', 'off' ), true ) ) {
			return false;
		}

		return (bool) $fallback;
	}

	/**
	 * Sanitize the height attribute into a safe CSS length.
	 *
	 * Only positive pixel values (with or without the "px" suffix) and positive
	 * percentages are supported, matching the shortcode contract. Anything else
	 * — zero, negatives, calc()/var(), viewport units, or injection attempts —
	 * falls back to the default 600px so no untrusted value ever reaches the
	 * inline style attribute.
	 *
	 * @param mixed $value Raw height attribute value.
	 * @return string Normalized CSS height (e.g. "600px" or "75%").
	 */
	private function sanitize_height_value( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return $value . 'px';
		}

		if ( preg_match( '/^[1-9][0-9]*px$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^[1-9][0-9]*%$/', $value ) ) {
			return $value;
		}

		return '600px';
	}

	/**
	 * Append the eXeLearning teacher-layer opt-in to a preview URL.
	 *
	 * Exported packages hide teacher-only content by default and expose an
	 * in-page "teacher layer" selector through ?exe-teacher=1 (shown but off
	 * until the viewer turns it on). No host-side CSS/JS injection is needed —
	 * the request is carried on the iframe src when the embed should offer the
	 * selector. The legacy teacher_mode attribute (activate-on-load) folds into
	 * the same opt-in, since core no longer auto-reveals from the URL.
	 *
	 * @param string $preview_url          Proxy preview URL.
	 * @param bool   $teacher_mode_visible Whether the teacher-layer selector is available.
	 * @param bool   $teacher_mode         Legacy activate-on-load flag (same opt-in).
	 * @return string Preview URL, with ?exe-teacher=1 appended when offered.
	 */
	private function maybe_append_teacher_param( $preview_url, $teacher_mode_visible, $teacher_mode ) {
		if ( ! $teacher_mode_visible && ! $teacher_mode ) {
			return $preview_url;
		}

		$separator = ( false === strpos( $preview_url, '?' ) ) ? '?' : '&';

		return $preview_url . $separator . 'exe-teacher=1';
	}
}
