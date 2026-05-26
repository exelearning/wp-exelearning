<?php
/**
 * Renders the split-button used to download an embedded .elpx in multiple formats.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Download_Button_Renderer.
 */
class ExeLearning_Download_Button_Renderer {

	/**
	 * Render the split download button HTML.
	 *
	 * The button is rendered as a `<div>` containing one or more anchors/buttons.
	 * Client-side JavaScript (`wp-exe-download.js`) drives the export for
	 * non-`.elpx` formats by lazy-loading the editor exporters bundle.
	 *
	 * @param int      $attachment_id Attachment post ID.
	 * @param string[] $format_ids    Enabled format ids (sanitized).
	 * @return string
	 */
	public static function render( $attachment_id, array $format_ids ) {
		$format_ids = ExeLearning_Download_Formats::sanitize( $format_ids );
		if ( empty( $format_ids ) ) {
			return '';
		}

		$file_url = wp_get_attachment_url( $attachment_id );
		$slug     = sanitize_title( pathinfo( get_the_title( $attachment_id ), PATHINFO_FILENAME ) );
		if ( '' === $slug ) {
			$slug = 'exelearning-' . $attachment_id;
		}
		$elpx_extracted = get_post_meta( $attachment_id, '_exelearning_extracted', true );
		$elp_url        = $elpx_extracted
			? wp_get_attachment_url( $attachment_id )
			: $file_url;

		$items = array();
		foreach ( $format_ids as $id ) {
			$fmt = ExeLearning_Download_Formats::get( $id );
			if ( ! $fmt ) {
				continue;
			}
			$items[] = $fmt;
		}

		if ( empty( $items ) ) {
			return '';
		}

		$primary  = array_shift( $items );
		$dropdown = $items;

		$container_id = 'exelearning-dl-' . wp_unique_id();

		$data_attrs = sprintf(
			'data-attachment-id="%d" data-elp-url="%s" data-slug="%s"',
			absint( $attachment_id ),
			esc_attr( $elp_url ),
			esc_attr( $slug )
		);

		$html  = '<div class="exelearning-download" id="' . esc_attr( $container_id ) . '" ' . $data_attrs . '>';
		$html .= self::render_item( $primary, true );

		if ( ! empty( $dropdown ) ) {
			$html .= '<button type="button" class="exelearning-download__toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__( 'More download formats', 'exelearning' ) . '">';
			$html .= '<span class="dashicons dashicons-arrow-down-alt2"></span>';
			$html .= '</button>';
			$html .= '<ul class="exelearning-download__menu" role="menu" hidden>';
			foreach ( $dropdown as $fmt ) {
				$html .= '<li role="none">' . self::render_item( $fmt, false ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render a single item — either the primary button or a dropdown entry.
	 *
	 * `.elpx` items are plain `<a download>` links (no JS required).
	 * Other formats are `<button>`s that the client-side script intercepts.
	 *
	 * @param array<string, mixed> $fmt        Format definition.
	 * @param bool                 $is_primary Whether this item is the visible primary action.
	 * @return string
	 */
	private static function render_item( array $fmt, $is_primary ) {
		$classes = $is_primary ? 'exelearning-download__primary' : 'exelearning-download__item';
		$label   = sprintf(
			/* translators: %s: format display label */
			__( 'Download %s', 'exelearning' ),
			$fmt['label']
		);

		if ( 'elpx' === $fmt['id'] ) {
			return sprintf(
				'<a href="#" class="%1$s" data-format="%2$s" data-suffix="%3$s" download role="%4$s">'
					. '<span class="dashicons dashicons-download"></span>'
					. '<span class="exelearning-download__label">%5$s</span>'
				. '</a>',
				esc_attr( $classes ),
				esc_attr( $fmt['id'] ),
				esc_attr( $fmt['suffix'] ),
				$is_primary ? 'button' : 'menuitem',
				esc_html( $label )
			);
		}

		return sprintf(
			'<button type="button" class="%1$s" data-format="%2$s" data-suffix="%3$s" data-mime="%4$s" role="%5$s">'
				. '<span class="dashicons dashicons-download"></span>'
				. '<span class="exelearning-download__label">%6$s</span>'
			. '</button>',
			esc_attr( $classes ),
			esc_attr( $fmt['id'] ),
			esc_attr( $fmt['suffix'] ),
			esc_attr( $fmt['mime'] ),
			$is_primary ? 'button' : 'menuitem',
			esc_html( $label )
		);
	}

	/**
	 * Enqueue the frontend JS and CSS needed by the split button.
	 *
	 * Safe to call multiple times; WordPress de-duplicates by handle.
	 */
	public static function enqueue_assets() {
		wp_enqueue_script(
			'exelearning-download',
			plugins_url( '../assets/js/wp-exe-download.js', __FILE__ ),
			array(),
			EXELEARNING_VERSION,
			true
		);

		// Static editor path is fixed by the build (dist/static/).
		$bundle_url = EXELEARNING_PLUGIN_URL . 'dist/static/app/yjs/exporters.bundle.js';
		$editor_url = EXELEARNING_PLUGIN_URL . 'dist/static/index.html';

		wp_localize_script(
			'exelearning-download',
			'wpExeDownloadConfig',
			array(
				'editorUrl'       => esc_url_raw( $editor_url ),
				'exportersBundle' => esc_url_raw( $bundle_url ),
				'i18n'            => array(
					'preparing' => __( 'Preparing download…', 'exelearning' ),
					'failed'    => __( 'Download failed. Please try again.', 'exelearning' ),
				),
			)
		);
	}
}
