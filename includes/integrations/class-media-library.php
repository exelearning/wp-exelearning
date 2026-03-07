<?php
/**
 * Media library integration for eXeLearning plugin.
 *
 * This class adds integration features to the WordPress media library
 * for handling eXeLearning files.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Media_Library.
 *
 * Manages integration with the WordPress media library.
 */
class ExeLearning_Media_Library {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'manage_media_columns', array( $this, 'add_elp_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_elp_column' ), 10, 2 );
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_elp_meta_box' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_modal_scripts' ) );

		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'add_elp_metadata_to_js' ), 10, 3 );
	}

	/**
	 * Enqueue scripts for the media modal.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_media_modal_scripts( $hook ) {
		// Load on pages where the media library is used.
		$allowed_hooks = array( 'upload.php', 'post.php', 'post-new.php', 'media.php' );

		if ( in_array( $hook, $allowed_hooks, true ) || did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();

			wp_enqueue_script(
				'exelearning-media-modal',
				plugins_url( '../../assets/js/exelearning-media-modal.js', __FILE__ ),
				array( 'jquery', 'media-views' ),
				EXELEARNING_VERSION,
				true
			);

			wp_localize_script(
				'exelearning-media-modal',
				'exelearningMediaStrings',
				array(
					'info'          => __( 'eXeLearning Info', 'exelearning' ),
					'version'       => __( 'Version:', 'exelearning' ),
					'sourceFile'    => '(' . __( 'source file', 'exelearning' ) . ')',
					'exported'      => '(' . __( 'exported', 'exelearning' ) . ')',
					'license'       => __( 'License:', 'exelearning' ),
					'language'      => __( 'Language:', 'exelearning' ),
					'type'          => __( 'Type:', 'exelearning' ),
					'noPreview'     => __( 'No preview available', 'exelearning' ),
					'noPreviewDesc' => __( 'This is an eXeLearning v2 source file (.elp). To view the content, open it in eXeLearning and export it as HTML.', 'exelearning' ),
					'previewNewTab' => __( 'Preview in new tab', 'exelearning' ),
					'editInExe'     => __( 'Edit in eXeLearning', 'exelearning' ),
				)
			);

			wp_enqueue_style(
				'exelearning-media-library',
				plugins_url( '../../assets/css/exelearning-admin.css', __FILE__ ),
				array(),
				EXELEARNING_VERSION
			);
		}
	}

	/**
	 * Add eXeLearning metadata to the attachment JS object.
	 *
	 * @param array   $response The attachment response array.
	 * @param WP_Post $post     The attachment post object.
	 * @param array   $meta     The attachment metadata (unused, required by WordPress filter API).
	 * @return array Modified response array.
	 */
	public function add_elp_metadata_to_js( $response, $post, $meta ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter API.
		unset( $meta ); // Unused parameter.

		// Early return if post is not valid.
		if ( ! $post || ! isset( $post->ID ) || empty( $post->ID ) ) {
			return $response;
		}

		$extracted_hash = get_post_meta( $post->ID, '_exelearning_extracted', true );

		if ( $extracted_hash ) {
			$has_preview = get_post_meta( $post->ID, '_exelearning_has_preview', true );
			$version     = get_post_meta( $post->ID, '_exelearning_version', true );

			$response['exelearning'] = array(
				'license'       => get_post_meta( $post->ID, '_exelearning_license', true ),
				'language'      => get_post_meta( $post->ID, '_exelearning_language', true ),
				'resource_type' => get_post_meta( $post->ID, '_exelearning_resource_type', true ),
				'version'       => $version,
				'has_preview'   => '1' === $has_preview,
			);

			// Only include preview_url if the file has index.html (version 3 files).
			if ( '1' === $has_preview ) {
				$response['exelearning']['preview_url'] = ExeLearning_Content_Proxy::get_proxy_url( $extracted_hash );
			}
		}

		return $response;
	}

	/**
	 * Adds a meta box to the attachment edit screen.
	 */
	public function add_elp_meta_box() {
		global $post;

		// Early return if post is not valid.
		if ( ! $post || ! isset( $post->ID ) || empty( $post->ID ) ) {
			return;
		}

		$extracted_url = get_post_meta( $post->ID, '_exelearning_extracted', true );

		// Metabox for preview of extracted content.
		if ( $extracted_url ) {
			add_meta_box(
				'exelearning-preview-metabox',
				__( 'eXeLearning Content Preview', 'exelearning' ),
				array( $this, 'render_preview_meta_box' ),
				'attachment',
				'normal',
				'high'
			);
		}

		if ( get_post_meta( $post->ID, '_exelearning_extracted', true ) ) {
			add_meta_box(
				'exelearning-metabox',
				__( 'eXeLearning Metadata', 'exelearning' ),
				array( $this, 'render_elp_meta_box' ),
				'attachment',
				'side'
			);
		}
	}

	/**
	 * Renders the preview meta box.
	 *
	 * @param WP_Post $post The attachment post object.
	 */
	public function render_preview_meta_box( $post ) {

		$directory   = get_post_meta( $post->ID, '_exelearning_extracted', true );
		$has_preview = get_post_meta( $post->ID, '_exelearning_has_preview', true );

		if ( $directory ) {
			if ( '1' === $has_preview ) {
				$preview_url = ExeLearning_Content_Proxy::get_proxy_url( $directory );

				echo '<div style="width: 100%; height: 600px; overflow: auto; margin-bottom: 15px;">';
				echo '<iframe src="' . esc_url( $preview_url ) . '" style="width: 100%; height: 100%; border: none;" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>';
				echo '</div>';
				echo '<p><a href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in new tab', 'exelearning' ) . '</a></p>';
			} else {
				echo '<div style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-bottom: 15px;">';
				echo '<p><strong>' . esc_html__( 'No preview available', 'exelearning' ) . '</strong></p>';
				echo '<p>' . esc_html__( 'This is an eXeLearning v2 source file (.elp). To view the content, open it in eXeLearning and export it as HTML.', 'exelearning' ) . '</p>';
				echo '</div>';
			}

			// Add "Edit in eXeLearning" button.
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$edit_url = add_query_arg(
					array(
						'page'          => 'exelearning-editor',
						'attachment_id' => $post->ID,
						'_wpnonce'      => wp_create_nonce( 'exelearning_editor' ),
					),
					admin_url( 'admin.php' )
				);

				echo '<p style="margin-top: 15px;">';
				echo '<a href="' . esc_url( $edit_url ) . '" class="button button-primary button-large exelearning-edit-page-button" ';
				echo 'data-attachment-id="' . esc_attr( $post->ID ) . '" ';
				echo 'style="width: 100%; text-align: center;">';
				echo '<span class="dashicons dashicons-edit" style="vertical-align: middle; margin-right: 5px;"></span>';
				echo esc_html__( 'Edit in eXeLearning', 'exelearning' );
				echo '</a>';
				echo '</p>';
			}
		}
	}


	/**
	 * Renders the content of the meta box.
	 *
	 * @param WP_Post $post The attachment post object.
	 */
	public function render_elp_meta_box( $post ) {
		$license       = get_post_meta( $post->ID, '_exelearning_license', true );
		$language      = get_post_meta( $post->ID, '_exelearning_language', true );
		$resource_type = get_post_meta( $post->ID, '_exelearning_resource_type', true );

		echo '<ul>';
		echo '<li><strong>' . esc_html__( 'License:', 'exelearning' ) . '</strong> ' . esc_html( $license ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Language:', 'exelearning' ) . '</strong> ' . esc_html( $language ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Resource Type:', 'exelearning' ) . '</strong> ' . esc_html( $resource_type ) . '</li>';
		echo '</ul>';
	}

	/**
	 * Adds a custom column for eXeLearning files in the media library.
	 *
	 * @param array $columns Current media library columns.
	 * @return array Modified columns.
	 */
	public function add_elp_column( $columns ) {
		$columns['exelearning'] = __( 'eXeLearning', 'exelearning' );
		return $columns;
	}

	/**
	 * Renders the custom eXeLearning column content.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Attachment ID.
	 */
	public function render_elp_column( $column_name, $post_id ) {
		if ( 'exelearning' !== $column_name ) {
			return;
		}

		// Check if it is an .elp file using metadata.
		$is_elp = get_post_meta( $post_id, '_exelearning_extracted', true );

		if ( $is_elp ) {
			$license       = get_post_meta( $post_id, '_exelearning_license', true );
			$language      = get_post_meta( $post_id, '_exelearning_language', true );
			$resource_type = get_post_meta( $post_id, '_exelearning_resource_type', true );

			echo '<div class="exelearning-metadata">';

			if ( $license ) {
				echo '<div><strong>' . esc_html__( 'License:', 'exelearning' ) . '</strong> ' . esc_html( $license ) . '</div>';
			}

			if ( $language ) {
				echo '<div><strong>' . esc_html__( 'Language:', 'exelearning' ) . '</strong> ' . esc_html( $language ) . '</div>';
			}

			if ( $resource_type ) {
				echo '<div><strong>' . esc_html__( 'Resource Type:', 'exelearning' ) . '</strong> ' . esc_html( $resource_type ) . '</div>';
			}

			echo '</div>';
		}
	}
}
