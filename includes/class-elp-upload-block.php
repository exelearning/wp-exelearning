<?php
/**
 * Registers the eXeLearning .elp upload block.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Elp_Upload_Block.
 *
 * Registers and renders the eXeLearning Gutenberg block.
 */
class ExeLearning_Elp_Upload_Block {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
	}

	/**
	 * Enqueue frontend styles and register the shared embed-loader behavior.
	 *
	 * The loader is registered here but not enqueued: a page with no eXeLearning
	 * block has nothing for it to bind, and enqueueing from this hook would put the
	 * request on every frontend page of the site. render_block_preview() enqueues it
	 * at the point it emits a wrapper for the loader to find.
	 */
	public function enqueue_frontend_styles() {
		wp_enqueue_style(
			'exelearning-frontend',
			plugins_url( '../assets/css/exelearning.css', __FILE__ ),
			array(),
			EXELEARNING_VERSION
		);
		// Binds every `.exelearning-embed-loader` on the page at once, so the
		// spinner costs one cached file instead of an inline copy per block.
		wp_register_script(
			'exelearning-embed-loader',
			plugins_url( '../assets/js/exelearning-embed-loader.js', __FILE__ ),
			array(),
			EXELEARNING_VERSION,
			true
		);
	}

	/**
	 * Enqueue block editor scripts and styles.
	 */
	public function enqueue_block_scripts() {
		// The download orchestrator (registers the exelearning-download handle and
		// the wpExeDownloadConfig object / window.wpExeDownload API) so the
		// edit-mode download toolbar can reuse the same export pipeline.
		ExeLearning_Download_Button_Renderer::enqueue_assets();

		// Use the plugin-root file as the base so the registered URL is clean
		// (no "includes/../"). wp_set_script_translations() derives the JSON
		// filename from md5 of the script's path relative to the plugin, so an
		// unnormalized "includes/../assets/..." would never match the
		// make-json output (md5 of "assets/js/elp-upload.js").
		wp_enqueue_script(
			'exelearning-elp-block',
			plugins_url( 'assets/js/elp-upload.js', EXELEARNING_PLUGIN_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'exelearning-editor', 'exelearning-download' ),
			EXELEARNING_VERSION,
			true
		);

		/**
		 * Hand the editor the SAME sandbox tokens the public page uses.
		 *
		 * The edit component used to hardcode `allow-same-origin`, which gave an uploaded
		 * package the plugin's own origin in the one place where the person viewing
		 * untrusted content is also the person holding credentials to the site. Reading
		 * the tokens from the single source of truth means the editor cannot drift from
		 * the front end again.
		 */
		wp_localize_script(
			'exelearning-elp-block',
			'exeLearningBlockEditor',
			array( 'sandboxTokens' => ExeLearning_Iframe_Sandbox::sandbox_tokens() )
		);

		/**
		 * And the host that fills the promoted embeds, started on THIS screen.
		 *
		 * The relay is otherwise started from render_block_preview(), which only ever runs
		 * on the front end. Now that the preview is opaque, a screen without it is strictly
		 * worse than the same-origin one it replaced: the child reports every embed as a
		 * placeholder, nothing overlays them, and the author sees black rectangles.
		 */
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();

		// Load JavaScript translations the standard way: WordPress serves the
		// generated languages/exelearning-{locale}-{md5}.json files for this
		// handle (md5 of assets/js/elp-upload.js). Strings must be literal
		// __( 'x', 'exelearning' ) calls so `wp i18n make-json` can extract them.
		wp_set_script_translations(
			'exelearning-elp-block',
			'exelearning',
			EXELEARNING_PLUGIN_DIR . 'languages'
		);

		// The stylesheets themselves are declared on the block type (see
		// register_block()), which is what gets them into the editor canvas
		// iframe under API version 3. Enqueueing them from here would only
		// reach the outer admin document, where the block no longer renders.
	}

	/**
	 * Register the block's stylesheets so the block type can name them.
	 *
	 * Registered rather than enqueued: WordPress enqueues a block's `style` and
	 * `editor_style` handles itself, and for an API version 3 block it injects
	 * them into the canvas iframe.
	 */
	public function register_block_styles() {
		// Carries the .exelearning-download split-button rules the edit-mode
		// toolbar shares with the frontend.
		//
		// Depends on dashicons, and that dependency is load-bearing: the
		// download and fullscreen buttons draw their icons from that font, and
		// an API version 3 block renders inside the editor canvas iframe, which
		// receives the block's declared styles *and their dependencies* and
		// nothing else. wp-admin loading dashicons into the outer document does
		// not help the block any more.
		wp_register_style(
			'exelearning-frontend',
			plugins_url( '../assets/css/exelearning.css', __FILE__ ),
			array( 'dashicons' ),
			EXELEARNING_VERSION
		);

		// Editor-only chrome around the block preview.
		wp_register_style(
			'exelearning-block-editor',
			plugins_url( '../assets/css/exelearning-admin.css', __FILE__ ),
			array(),
			EXELEARNING_VERSION
		);
	}

	/**
	 * Register the block type.
	 */
	public function register_block() {
		$this->register_block_styles();

		register_block_type(
			'exelearning/elp-upload',
			array(
				// api_version 3 must match the apiVersion the JS registers with,
				// or WordPress and the editor disagree about whether the block
				// can render inside the canvas iframe.
				'api_version'     => 3,
				'editor_script'   => 'exelearning-elp-block',
				// Named here rather than enqueued: this is what puts them inside
				// the canvas iframe. `style` also loads on the frontend, where
				// the same sheet already arrives via wp_enqueue_scripts for the
				// shortcode, and WordPress deduplicates by handle.
				'style'           => 'exelearning-frontend',
				'editor_style'    => 'exelearning-block-editor',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'attachmentId'       => array(
						'type' => 'number',
					),
					'url'                => array(
						'type' => 'string',
					),
					'previewUrl'         => array(
						'type' => 'string',
					),
					'title'              => array(
						'type' => 'string',
					),
					'hasPreview'         => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'height'             => array(
						'type'    => 'number',
						'default' => 600,
					),
					'align'              => array(
						'type'    => 'string',
						'default' => 'none',
					),
					'teacherModeVisible' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'showDownload'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'downloadFormats'    => array(
						'type'    => 'array',
						'default' => ExeLearning_Download_Formats::default_ids(),
						'items'   => array( 'type' => 'string' ),
					),
					'fullscreen'         => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'supports'        => array(
					'align' => array( 'left', 'center', 'right', 'wide', 'full' ),
					'html'  => false,
				),
			)
		);
	}

	/**
	 * Render the block on the frontend.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML output.
	 */
	public function render_block( $attributes ) {
		if ( empty( $attributes['attachmentId'] ) ) {
			return '';
		}

		// Toolbar/download icons need the Dashicons font, which is not loaded on
		// the public frontend by default.
		wp_enqueue_style( 'dashicons' );

		$data = $this->prepare_block_render_data( $attributes );

		if ( ! $data['extracted_dir'] ) {
			return '<div class="exelearning-error">' . esc_html__( 'Error: eXeLearning content not found', 'exelearning' ) . '</div>';
		}

		$download_html = $this->maybe_render_download_button( $data );

		// Source files (no extracted preview) fall back to a download notice.
		if ( '1' !== $data['has_preview'] ) {
			return $this->render_block_no_preview( $data, $download_html );
		}

		return $this->render_block_preview( $data, $download_html );
	}

	/**
	 * Normalize raw block attributes into the values render uses.
	 *
	 * @param array $attributes Block attributes.
	 * @return array Prepared render data.
	 */
	private function prepare_block_render_data( $attributes ) {
		$attachment_id = absint( $attributes['attachmentId'] );

		$wrapper_classes = array( 'wp-block-exelearning-elp-upload', 'exelearning-block-frontend' );
		$align           = isset( $attributes['align'] ) ? $attributes['align'] : '';
		if ( ! empty( $align ) && 'none' !== $align ) {
			$wrapper_classes[] = 'align' . $align;
		}

		return array(
			'attachment_id'        => $attachment_id,
			'extracted_dir'        => get_post_meta( $attachment_id, '_exelearning_extracted', true ),
			'has_preview'          => get_post_meta( $attachment_id, '_exelearning_has_preview', true ),
			'height'               => isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 600,
			'teacher_mode_visible' => isset( $attributes['teacherModeVisible'] ) && (bool) $attributes['teacherModeVisible'],
			'show_download'        => isset( $attributes['showDownload'] ) && (bool) $attributes['showDownload'],
			'fullscreen'           => isset( $attributes['fullscreen'] ) && (bool) $attributes['fullscreen'],
			'download_formats'     => isset( $attributes['downloadFormats'] )
				? ExeLearning_Download_Formats::sanitize( $attributes['downloadFormats'] )
				: ExeLearning_Download_Formats::default_ids(),
			'wrapper_classes'      => $wrapper_classes,
			'container_id'         => 'exelearning-block-' . wp_unique_id(),
		);
	}

	/**
	 * Render the multi-format download button when enabled, else an empty string.
	 *
	 * @param array $data Prepared render data.
	 * @return string Download button HTML or ''.
	 */
	private function maybe_render_download_button( $data ) {
		if ( ! $data['show_download'] || empty( $data['download_formats'] ) ) {
			return '';
		}
		ExeLearning_Download_Button_Renderer::enqueue_assets();
		return ExeLearning_Download_Button_Renderer::render( $data['attachment_id'], $data['download_formats'] );
	}

	/**
	 * Build the escaped proxy preview URL.
	 *
	 * @param array $data Prepared render data.
	 * @return string Escaped preview URL.
	 */
	private function build_preview_url( $data ) {
		$url = ExeLearning_Content_Proxy::get_proxy_url( $data['extracted_dir'] );

		// eXeLearning core hides teacher-only content by default and exposes an in-page
		// "teacher layer" selector via ?exe-teacher=1 (shown but off until the viewer
		// turns it on). No host-side CSS/JS injection is needed — opt in by carrying the
		// parameter on the iframe src when this block should offer the selector. This
		// works through the secure-mode proxy too: the parameter rides on the iframe src
		// and the package reads its own location.search even under the opaque origin.
		if ( ! empty( $data['teacher_mode_visible'] ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'exe-teacher=1';
		}

		return esc_url( $url );
	}

	/**
	 * Render the "source file, no preview" fallback with a download link.
	 *
	 * @param array  $data          Prepared render data.
	 * @param string $download_html Pre-rendered download button, or ''.
	 * @return string HTML output.
	 */
	private function render_block_no_preview( $data, $download_html ) {
		$download = '' !== $download_html
			? $download_html
			: sprintf(
				'<a href="%s" class="exelearning-download-link" download>%s</a>',
				esc_url( wp_get_attachment_url( $data['attachment_id'] ) ),
				esc_html__( 'Download file', 'exelearning' )
			);

		return sprintf(
			'<div class="%s exelearning-no-preview-frontend">
                    <div class="exelearning-notice">
                        <p><strong>%s</strong></p>
                        <p>%s</p>
                        %s
                    </div>
                </div>',
			esc_attr( implode( ' ', $data['wrapper_classes'] ) ),
			esc_html( get_the_title( $data['attachment_id'] ) ),
			esc_html__( 'This eXeLearning content is a source file and cannot be previewed directly.', 'exelearning' ),
			$download
		);
	}

	/**
	 * Render the preview iframe with an optional download toolbar.
	 *
	 * @param array  $data          Prepared render data.
	 * @param string $download_html Pre-rendered download button, or ''.
	 * @return string HTML output.
	 */
	private function render_block_preview( $data, $download_html ) {
		// In secure mode the content is opaque, so whitelisted external embeds are
		// promoted to this page (no-op in legacy, where they already work inline).
		ExeLearning_Iframe_Sandbox::enqueue_embed_relay();
		// Parent-side media host for the interactive-video iDevice in secure mode (DEC-0067).
		ExeLearning_Iframe_Sandbox::enqueue_media_host();

		$html = sprintf(
			'<div id="%s" class="%s" data-teacher-mode-visible="%s">',
			esc_attr( $data['container_id'] ),
			esc_attr( implode( ' ', $data['wrapper_classes'] ) ),
			$data['teacher_mode_visible'] ? '1' : '0'
		);

		$fullscreen_html = '';
		if ( ! empty( $data['fullscreen'] ) ) {
			$fullscreen_html = sprintf(
				'<button type="button" class="exelearning-toolbar-btn exelearning-fullscreen-btn" aria-label="%1$s" title="%1$s"><span class="dashicons dashicons-fullscreen-alt" aria-hidden="true"></span></button>',
				esc_attr__( 'View fullscreen', 'exelearning' )
			);
		}

		if ( '' !== $download_html || '' !== $fullscreen_html ) {
			$html .= '<div class="exelearning-block-toolbar">' . $download_html . $fullscreen_html . '</div>';
		}

		// The package is downloaded, parsed and laid out inside the iframe before
		// anything paints, which reads as a blank frame for a noticeable moment.
		// Wrap it so the loader script can cover that gap with a spinner, and pull
		// the script in here so it only ships on pages that actually have a wrapper.
		// It is a footer script enqueued while the content renders, which still
		// prints; in a REST render the handle was never registered and this is a
		// harmless no-op.
		wp_enqueue_script( 'exelearning-embed-loader' );
		$html .= '<div class="exelearning-embed-loader">';
		$html .= sprintf(
			'<iframe
                src="%s"
                class="exelearning-iframe"
                style="width: 100%%; height: %dpx; border: 1px solid #ddd; border-radius: 4px;"
                title="%s"
                loading="lazy"
                sandbox="%s"
                referrerpolicy="no-referrer"
            ></iframe>',
			$this->build_preview_url( $data ),
			$data['height'],
			esc_attr( get_the_title( $data['attachment_id'] ) ),
			esc_attr( ExeLearning_Iframe_Sandbox::sandbox_tokens() )
		);
		$html .= sprintf(
			'<div class="exelearning-embed-loader__spinner" role="status" aria-live="polite">%s</div>',
			esc_html__( 'Loading content…', 'exelearning' )
		);
		$html .= '</div>';

		$html .= '</div>';

		if ( ! empty( $data['fullscreen'] ) ) {
			$html .= $this->render_block_fullscreen_script( $data['container_id'] );
		}

		return $html;
	}

	/**
	 * Build the inline fullscreen behavior script for a block preview.
	 *
	 * Scoped to the instance container so multiple blocks on one page stay
	 * independent. The button fullscreens the iframe element from the parent
	 * page, which works regardless of the iframe sandbox.
	 *
	 * @param string $container_id Container element ID.
	 * @return string Inline <script> markup.
	 */
	private function render_block_fullscreen_script( $container_id ) {
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
                    }
                })();
            </script>',
			esc_attr( $container_id )
		);
	}
}
