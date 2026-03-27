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
		wp_enqueue_script(
			'exelearning-elp-block',
			plugins_url( '../assets/js/elp-upload.js', __FILE__ ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'exelearning-editor' ),
			EXELEARNING_VERSION,
			true
		);

		$this->inject_block_translations();

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

		$attachment_id        = absint( $attributes['attachmentId'] );
		$extracted_dir        = get_post_meta( $attachment_id, '_exelearning_extracted', true );
		$has_preview          = get_post_meta( $attachment_id, '_exelearning_has_preview', true );
		$height               = isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 600;
		$align                = isset( $attributes['align'] ) ? $attributes['align'] : '';
		$teacher_mode_visible = ! isset( $attributes['teacherModeVisible'] ) || (bool) $attributes['teacherModeVisible'];
		$container_id         = 'exelearning-block-' . wp_unique_id();

		// Build wrapper classes.
		$wrapper_classes = array( 'wp-block-exelearning-elp-upload', 'exelearning-block-frontend' );
		if ( ! empty( $align ) && 'none' !== $align ) {
			$wrapper_classes[] = 'align' . $align;
		}

		if ( ! $extracted_dir ) {
			return '<div class="exelearning-error">' . esc_html__( 'Error: eXeLearning content not found', 'exelearning' ) . '</div>';
		}

		// Build preview URL using secure proxy.
		$preview_url = esc_url( ExeLearning_Content_Proxy::get_proxy_url( $extracted_dir ) );

		// If no preview available, show a link to download the file.
		if ( '1' !== $has_preview ) {
			$file_url = wp_get_attachment_url( $attachment_id );
			$title    = get_the_title( $attachment_id );

			return sprintf(
				'<div class="%s exelearning-no-preview-frontend">
                    <div class="exelearning-notice">
                        <p><strong>%s</strong></p>
                        <p>%s</p>
                        <a href="%s" class="exelearning-download-link" download>%s</a>
                    </div>
                </div>',
				esc_attr( implode( ' ', $wrapper_classes ) ),
				esc_html( $title ),
				esc_html__( 'This eXeLearning content is a source file and cannot be previewed directly.', 'exelearning' ),
				esc_url( $file_url ),
				esc_html__( 'Download file', 'exelearning' )
			);
		}

		// Show iframe with the content.
		$html = sprintf(
			'<div id="%s" class="%s" data-teacher-mode-visible="%s">
                <iframe
                    src="%s"
                    style="width: 100%%; height: %dpx; border: 1px solid #ddd; border-radius: 4px;"
                    title="%s"
                    loading="lazy"
                    sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    referrerpolicy="no-referrer"
                ></iframe>
            </div>',
			esc_attr( $container_id ),
			esc_attr( implode( ' ', $wrapper_classes ) ),
			$teacher_mode_visible ? '1' : '0',
			$preview_url,
			$height,
			esc_attr( get_the_title( $attachment_id ) )
		);

		if ( ! $teacher_mode_visible ) {
			$html .= sprintf(
				'<script>(function(){var c=document.getElementById("%1$s");if(!c)return;var f=c.querySelector("iframe");if(!f)return;var css="#teacher-mode-toggler-wrapper { visibility: hidden !important; }";var inject=function(){try{if(!f.contentDocument)return;var d=f.contentDocument;var st=d.createElement("style");st.textContent=css;(d.head||d.documentElement).appendChild(st);}catch(e){}};f.addEventListener("load",inject);inject();})();</script>',
				esc_js( $container_id )
			);
		}

		return $html;
	}

	/**
	 * Inject JS translations from the already-loaded MO textdomain.
	 * This avoids needing separate JSON translation files.
	 */
	private function inject_block_translations() {
		$strings = array(
			'Settings',
			'Height (px)',
			'Show Teacher Mode toggler',
			'Edit in eXeLearning',
			'eXeLearning Content',
			'Upload or select a .elpx file from your media library',
			'Upload .elpx File',
			'Media Library',
			'Change file',
			'Remove',
			'No preview available',
			'This is an eXeLearning v2 source file. The content will be displayed on the frontend if exported HTML is available.',
		);

		$locale_data = array();
		foreach ( $strings as $s ) {
			$t = __( $s, 'exelearning' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			if ( $t !== $s ) {
				$locale_data[ $s ] = array( $t );
			}
		}

		if ( empty( $locale_data ) ) {
			return;
		}

		$locale_data[''] = array( 'domain' => 'exelearning' );

		wp_add_inline_script(
			'exelearning-elp-block',
			'wp.i18n.setLocaleData(' . wp_json_encode( $locale_data ) . ',"exelearning");',
			'before'
		);
	}
}
