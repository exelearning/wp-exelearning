<?php
/**
 * Admin-side handler for the eXeLearning style management UI.
 *
 * Registers admin-ajax endpoints used by the "Styles" section of the
 * plugin settings page. All endpoints require `manage_options` and are
 * nonce-protected; they delegate logic to {@see ExeLearning_Styles_Service}.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Admin_Styles.
 */
class ExeLearning_Admin_Styles {

	const AJAX_NONCE = 'exelearning_styles';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_exelearning_styles_upload', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_exelearning_styles_toggle_uploaded', array( $this, 'ajax_toggle_uploaded' ) );
		add_action( 'wp_ajax_exelearning_styles_toggle_builtin', array( $this, 'ajax_toggle_builtin' ) );
		add_action( 'wp_ajax_exelearning_styles_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_exelearning_styles_toggle_block_import', array( $this, 'ajax_toggle_block_import' ) );
	}

	/**
	 * Handle a style ZIP upload.
	 */
	public function ajax_upload() {
		$this->check_common_permissions();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in check_common_permissions().
		if ( empty( $_FILES['style_zip'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'exelearning' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fields are sanitized individually below.
		$file = $_FILES['style_zip'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			wp_send_json_error(
				array( 'message' => __( 'File upload failed.', 'exelearning' ) ),
				500
			);
		}

		$tmp_name  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$orig_name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Uploaded file is not accessible.', 'exelearning' ) ),
				500
			);
		}

		$result = ExeLearning_Styles_Service::install_from_zip( $tmp_name, $orig_name );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Style installed.', 'exelearning' ),
				'style'   => $result,
			)
		);
	}

	/**
	 * Enable or disable an uploaded style.
	 */
	public function ajax_toggle_uploaded() {
		$this->check_common_permissions();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in check_common_permissions().
		$slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$enabled = self::read_bool_post( 'enabled' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => __( 'Missing style id.', 'exelearning' ) ), 400 );
		}
		$result = ExeLearning_Styles_Service::set_uploaded_enabled( $slug, $enabled );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * Enable or disable a built-in style.
	 */
	public function ajax_toggle_builtin() {
		$this->check_common_permissions();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in check_common_permissions().
		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '';
		$enabled = self::read_bool_post( 'enabled' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing style id.', 'exelearning' ) ), 400 );
		}
		ExeLearning_Styles_Service::set_builtin_enabled( $id, $enabled );
		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * Toggle the block-on-import flag for user-imported styles.
	 */
	public function ajax_toggle_block_import() {
		$this->check_common_permissions();
		$enabled = self::read_bool_post( 'enabled' );
		ExeLearning_Styles_Service::set_import_blocked( $enabled );
		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * Delete an uploaded style.
	 */
	public function ajax_delete() {
		$this->check_common_permissions();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in check_common_permissions().
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => __( 'Missing style id.', 'exelearning' ) ), 400 );
		}
		$result = ExeLearning_Styles_Service::delete_uploaded( $slug );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success();
	}

	/**
	 * Read a boolean-ish POST field and return it as a strict bool.
	 *
	 * Accepts the usual "truthy" string forms submitted by the admin UI and
	 * satisfies the WPCS input-sanitization sniff by passing the raw value
	 * through sanitize_text_field before interpretation.
	 *
	 * @param string $key POST field name.
	 * @return bool
	 */
	private static function read_bool_post( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling AJAX handler.
		if ( ! isset( $_POST[ $key ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling AJAX handler.
		$raw = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		return in_array( strtolower( $raw ), array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * Shared guard for all endpoints: capability + nonce.
	 */
	private function check_common_permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'exelearning' ) ), 403 );
		}
		$nonce = isset( $_REQUEST['_ajax_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['_ajax_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::AJAX_NONCE ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid or missing security token.', 'exelearning' ) ), 403 );
		}
	}
}
