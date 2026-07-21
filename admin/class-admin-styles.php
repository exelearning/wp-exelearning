<?php
/**
 * Admin-side handler for the eXeLearning style management actions.
 *
 * Registers the admin-post endpoints used by the "Styles" area of the
 * plugin settings page: uploading a style ZIP and deleting an uploaded
 * style. Both endpoints require `manage_options`, verify a nonce with
 * check_admin_referer(), and report their outcome through the Settings
 * API notices (add_settings_error + the settings_errors transient), so
 * messages render exactly like core settings notices after the redirect.
 *
 * Enable/disable state is NOT handled here: those checkboxes are part of
 * the main settings form and persist through options.php.
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

	/**
	 * admin-post action (and nonce action) for style uploads.
	 */
	const ACTION_UPLOAD = 'exelearning_styles_upload';

	/**
	 * admin-post action for style deletion. The nonce action is suffixed
	 * with the style slug so a nonce can only delete the row it was
	 * generated for.
	 */
	const ACTION_DELETE = 'exelearning_styles_delete';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( $this, 'handle_upload' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( $this, 'handle_delete' ) );
	}

	/**
	 * Handle a style ZIP upload posted from the settings page.
	 */
	public function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'exelearning' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_UPLOAD );

		if ( empty( $_FILES['style_zip'] ) ) {
			$this->redirect_with_notice( __( 'No file uploaded.', 'exelearning' ), 'error' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fields are validated and sanitized individually below.
		$file = $_FILES['style_zip'];
		if ( ! is_array( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->redirect_with_notice( __( 'File upload failed.', 'exelearning' ), 'error' );
		}

		$tmp_name  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$orig_name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			$this->redirect_with_notice( __( 'Uploaded file is not accessible.', 'exelearning' ), 'error' );
		}

		// Explicit extension allowlist plus a server-side type check; the
		// client-supplied MIME type is never trusted. The archive contents
		// (valid config.xml, safe entries) are validated by install_from_zip().
		$extension = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
		$filetype  = wp_check_filetype_and_ext( $tmp_name, $orig_name, array( 'zip' => 'application/zip' ) );
		if ( 'zip' !== $extension || 'zip' !== $filetype['ext'] ) {
			$this->redirect_with_notice( __( 'The uploaded file must be a .zip archive.', 'exelearning' ), 'error' );
		}

		$result = ExeLearning_Styles_Service::install_from_zip( $tmp_name, $orig_name );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( __( 'Style installed.', 'exelearning' ), 'success' );
	}

	/**
	 * Handle the deletion of an uploaded style (nonced admin-post link).
	 */
	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'exelearning' ), '', array( 'response' => 403 ) );
		}

		// The slug is read before nonce verification only because it is part
		// of the per-row nonce action verified immediately below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = isset( $_GET['slug'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['slug'] ) ) : '';
		$slug = ExeLearning_Styles_Service::normalize_slug( $slug );
		check_admin_referer( self::ACTION_DELETE . '_' . $slug );

		$result = ExeLearning_Styles_Service::delete_uploaded( $slug );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_message(), 'error' );
		}

		$this->redirect_with_notice( __( 'Style deleted.', 'exelearning' ), 'success' );
	}

	/**
	 * Register a settings notice, persist it across the redirect, and send
	 * the user back to the settings page.
	 *
	 * Mirrors the core options.php flow: the notice is stored in the
	 * `settings_errors` transient and rendered by settings_errors() on the
	 * settings screen, keyed off the `settings-updated` query arg.
	 *
	 * @param string $message User-facing message.
	 * @param string $type    Notice type: 'success', 'error', 'warning' or 'info'.
	 */
	private function redirect_with_notice( $message, $type ) {
		add_settings_error( 'exelearning_styles', 'exelearning_styles_result', $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		$this->finish_request(
			add_query_arg(
				array(
					'page'             => ExeLearning_Admin_Settings::PAGE_SLUG,
					'settings-updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
	}

	/**
	 * Redirect and terminate the request.
	 *
	 * Split into its own method so tests can override it instead of letting
	 * exit end the PHP process.
	 *
	 * @param string $location Redirect target URL.
	 */
	protected function finish_request( $location ) {
		wp_safe_redirect( $location );
		exit;
	}
}
