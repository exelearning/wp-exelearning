<?php
/**
 * Admin upload handler.
 *
 * This class manages the upload and processing of eXeLearning (.elp) files.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Admin_Upload.
 *
 * Handles the upload process for eXeLearning files.
 */
class ExeLearning_Admin_Upload {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_exelearning_upload', array( $this, 'handle_upload' ) );
	}

	/**
	 * Processes the file upload.
	 *
	 * Validates the request, verifies the file extension and handles the upload.
	 */
	public function handle_upload() {
		// Verify nonce.
		if ( ! isset( $_POST['exelearning_upload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['exelearning_upload_nonce'] ) ), 'exelearning_upload_action' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'exelearning' ) );
		}

		// Check user capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'exelearning' ) );
		}

		// Check for file upload errors.
		if ( ! isset( $_FILES['exelearning_file'], $_FILES['exelearning_file']['error'] ) || UPLOAD_ERR_OK !== $_FILES['exelearning_file']['error'] ) {
			wp_safe_redirect( add_query_arg( 'message', 'upload_error', wp_get_referer() ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = $_FILES['exelearning_file'];

		// Validate file extension (only .elpx allowed).
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'elpx' !== $ext ) {
			wp_safe_redirect( add_query_arg( 'message', 'invalid_file_type', wp_get_referer() ) );
			exit;
		}

		// Include required WordPress files for handling uploads.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Process the file upload.
		$upload_overrides = array( 'test_form' => false );
		$uploaded_file    = wp_handle_upload( $file, $upload_overrides );

		if ( isset( $uploaded_file['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'message', 'upload_error', wp_get_referer() ) );
			exit;
		}

		// Create an attachment for the uploaded file.
		$attachment = array(
			'post_mime_type' => $uploaded_file['type'],
			'post_title'     => sanitize_file_name( $file['name'] ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $uploaded_file['file'] );

		// Generate and update attachment metadata.
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $uploaded_file['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		wp_safe_redirect( add_query_arg( 'message', 'upload_success', wp_get_referer() ) );
		exit;
	}
}
