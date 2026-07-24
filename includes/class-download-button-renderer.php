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

		$editor_installed = class_exists( 'ExeLearning_Editor_Bundle' )
			&& ExeLearning_Editor_Bundle::is_available();

		$items = self::build_items( $format_ids, $editor_installed );
		if ( empty( $items ) ) {
			return '';
		}

		$slug = sanitize_title( pathinfo( get_the_title( $attachment_id ), PATHINFO_FILENAME ) );
		if ( '' === $slug ) {
			$slug = 'exelearning-' . $attachment_id;
		}

		$data_attrs = sprintf(
			'data-attachment-id="%d" data-elp-url="%s" data-slug="%s"',
			absint( $attachment_id ),
			esc_attr( wp_get_attachment_url( $attachment_id ) ),
			esc_attr( $slug )
		);

		$primary = array_shift( $items );

		$html  = '<div class="exelearning-download" id="' . esc_attr( 'exelearning-dl-' . wp_unique_id() ) . '" ' . $data_attrs . '>';
		$html .= self::render_item( $primary, true );
		$html .= self::render_dropdown( $items );
		$html .= '</div>';
		return $html;
	}

	/**
	 * Build the ordered list of format definitions to render.
	 *
	 * Without the static editor only the raw `.elpx` download works, so the
	 * client-export formats are marked disabled and sorted after the enabled
	 * ones (keeping the primary slot functional).
	 *
	 * @param string[] $format_ids       Sanitized format ids.
	 * @param bool     $editor_installed Whether the static editor is installed.
	 * @return array<int, array<string,mixed>>
	 */
	private static function build_items( array $format_ids, $editor_installed ) {
		$items = array();
		foreach ( $format_ids as $id ) {
			$fmt = ExeLearning_Download_Formats::get( $id );
			if ( ! $fmt ) {
				continue;
			}
			$fmt['disabled'] = ! empty( $fmt['client'] ) && ! $editor_installed;
			$items[]         = $fmt;
		}

		if ( ! $editor_installed ) {
			usort(
				$items,
				static function ( $a, $b ) {
					return ( $a['disabled'] ? 1 : 0 ) <=> ( $b['disabled'] ? 1 : 0 );
				}
			);
		}

		return $items;
	}

	/**
	 * Render the dropdown menu of secondary download formats.
	 *
	 * @param array<int, array<string,mixed>> $dropdown Secondary format definitions.
	 * @return string Menu HTML, or '' when there are no secondary formats.
	 */
	private static function render_dropdown( array $dropdown ) {
		if ( empty( $dropdown ) ) {
			return '';
		}
		$html  = '<button type="button" class="exelearning-download__toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__( 'More download formats', 'exelearning' ) . '">';
		$html .= '<span class="dashicons dashicons-arrow-down-alt2"></span>';
		$html .= '</button>';
		$html .= '<ul class="exelearning-download__menu" role="menu" hidden>';
		foreach ( $dropdown as $fmt ) {
			$html .= '<li role="none">' . self::render_item( $fmt, false ) . '</li>';
		}
		$html .= '</ul>';
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
		$label   = $fmt['label'];

		$disabled = ! empty( $fmt['disabled'] );
		if ( $disabled ) {
			$classes .= ' exelearning-download__item--disabled';
		}

		$title_attr = $disabled
			? ' title="' . esc_attr__( 'Install the eXeLearning editor from the plugin settings page to enable this format.', 'exelearning' ) . '"'
			: '';

		if ( 'elpx' === $fmt['id'] ) {
			return sprintf(
				'<a href="#" class="%1$s" data-format="%2$s" data-suffix="%3$s" download role="%4$s"%5$s>'
					. '<span class="dashicons dashicons-download"></span>'
					. '<span class="exelearning-download__label">%6$s</span>'
				. '</a>',
				esc_attr( $classes ),
				esc_attr( $fmt['id'] ),
				esc_attr( $fmt['suffix'] ),
				$is_primary ? 'button' : 'menuitem',
				$title_attr,
				esc_html( $label )
			);
		}

		return sprintf(
			'<button type="button" class="%1$s" data-format="%2$s" data-suffix="%3$s" data-mime="%4$s" role="%5$s"%6$s%7$s>'
				. '<span class="dashicons dashicons-download"></span>'
				. '<span class="exelearning-download__label">%8$s</span>'
			. '</button>',
			esc_attr( $classes ),
			esc_attr( $fmt['id'] ),
			esc_attr( $fmt['suffix'] ),
			esc_attr( $fmt['mime'] ),
			$is_primary ? 'button' : 'menuitem',
			$disabled ? ' disabled aria-disabled="true"' : '',
			$title_attr,
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

		// Client-side export formats only work when the static editor is
		// installed; expose this so the edit-mode toolbar can disable them
		// (the frontend renderer already disables them server-side).
		$editor_installed = class_exists( 'ExeLearning_Editor_Bundle' )
			&& ExeLearning_Editor_Bundle::is_available();

		wp_localize_script(
			'exelearning-download',
			'wpExeDownloadConfig',
			array(
				'editorUrl'       => esc_url_raw( $editor_url ),
				'exportersBundle' => esc_url_raw( $bundle_url ),
				// Base URL for the export bootstrap endpoint. Built from home_url()
				// so it is correct on subdirectory installs (e.g. /wp).
				'exportBase'      => esc_url_raw( home_url( '/' ) ),
				'editorInstalled' => $editor_installed,
				'i18n'            => array(
					'preparing'      => __( 'Preparing download…', 'exelearning' ),
					'failed'         => __( 'Download failed. Please try again.', 'exelearning' ),
					'editorRequired' => __( 'Install the eXeLearning editor from the plugin settings page to enable this format.', 'exelearning' ),
				),
			)
		);
	}
}
