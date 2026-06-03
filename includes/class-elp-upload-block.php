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
	 * Enqueue frontend styles.
	 */
	public function enqueue_frontend_styles() {
		wp_enqueue_style(
			'exelearning-frontend',
			plugins_url( '../assets/css/exelearning.css', __FILE__ ),
			array(),
			EXELEARNING_VERSION
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

		wp_enqueue_script(
			'exelearning-elp-block',
			plugins_url( '../assets/js/elp-upload.js', __FILE__ ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'exelearning-editor', 'exelearning-download' ),
			EXELEARNING_VERSION,
			true
		);

		// Load JavaScript translations the standard way: WordPress serves the
		// generated languages/exelearning-{locale}-{md5}.json files for this
		// handle (md5 of assets/js/elp-upload.js). Strings must be literal
		// __( 'x', 'exelearning' ) calls so `wp i18n make-json` can extract them.
		wp_set_script_translations(
			'exelearning-elp-block',
			'exelearning',
			EXELEARNING_PLUGIN_DIR . 'languages'
		);

		// Frontend styles carry the .exelearning-download split-button rules used
		// by the edit-mode toolbar; the admin sheet covers the block preview.
		wp_enqueue_style(
			'exelearning-frontend',
			plugins_url( '../assets/css/exelearning.css', __FILE__ ),
			array(),
			EXELEARNING_VERSION
		);

		wp_enqueue_style(
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
		register_block_type(
			'exelearning/elp-upload',
			array(
				'editor_script'   => 'exelearning-elp-block',
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
						'default' => true,
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
			'teacher_mode_visible' => ! isset( $attributes['teacherModeVisible'] ) || (bool) $attributes['teacherModeVisible'],
			'show_download'        => isset( $attributes['showDownload'] ) && (bool) $attributes['showDownload'],
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
		return esc_url( ExeLearning_Content_Proxy::get_proxy_url( $data['extracted_dir'] ) );
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
		$html = sprintf(
			'<div id="%s" class="%s" data-teacher-mode-visible="%s">',
			esc_attr( $data['container_id'] ),
			esc_attr( implode( ' ', $data['wrapper_classes'] ) ),
			$data['teacher_mode_visible'] ? '1' : '0'
		);

		if ( '' !== $download_html ) {
			$html .= '<div class="exelearning-block-toolbar">' . $download_html . '</div>';
		}

		$html .= sprintf(
			'<iframe
                src="%s"
                style="width: 100%%; height: %dpx; border: 1px solid #ddd; border-radius: 4px;"
                title="%s"
                loading="lazy"
                sandbox="allow-scripts allow-same-origin allow-popups"
                referrerpolicy="no-referrer"
            ></iframe></div>',
			$this->build_preview_url( $data ),
			$data['height'],
			esc_attr( get_the_title( $data['attachment_id'] ) )
		);

		if ( ! $data['teacher_mode_visible'] ) {
			$html .= $this->teacher_mode_hide_script( $data['container_id'] );
		}

		return $html;
	}

	/**
	 * Inline script that hides the teacher-mode toggler inside the preview
	 * iframe. The iframe is same-origin, so we inject a small stylesheet into
	 * its document on load.
	 *
	 * @param string $container_id Wrapper element id.
	 * @return string Script HTML.
	 */
	private function teacher_mode_hide_script( $container_id ) {
		return sprintf(
			'<script>(function(){var c=document.getElementById("%1$s");if(!c)return;var f=c.querySelector("iframe");if(!f)return;var css="#teacher-mode-toggler-wrapper { visibility: hidden !important; }";var inject=function(){try{if(!f.contentDocument)return;var d=f.contentDocument;if(d.getElementById("exelearning-teacher-mode-style"))return;var st=d.createElement("style");st.id="exelearning-teacher-mode-style";st.textContent=css;(d.head||d.documentElement).appendChild(st);}catch(e){}};f.addEventListener("load",inject);inject();})();</script>',
			esc_js( $container_id )
		);
	}

}
