<?php
/**
 * REST API endpoints for eXeLearning plugin.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_REST_API.
 *
 * Handles REST API endpoints for the eXeLearning editor.
 */
class ExeLearning_REST_API {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		$namespace = 'exelearning/v1';

		// Content proxy endpoint for secure file delivery.
		register_rest_route(
			$namespace,
			'/content/(?P<hash>[a-f0-9]{40})(?:/(?P<file>.*))?',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'proxy_content' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'hash' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $param ) {
							return preg_match( '/^[a-f0-9]{40}$/i', $param );
						},
					),
					'file' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'index.html',
					),
				),
			)
		);

		// Save modified ELP file.
		register_rest_route(
			$namespace,
			'/save/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_elp_file' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get ELP file data for loading into editor.
		register_rest_route(
			$namespace,
			'/elp-data/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_elp_data' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Create new ELP file from editor.
		register_rest_route(
			$namespace,
			'/create',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_elp_file' ),
				'permission_callback' => array( $this, 'check_upload_permission' ),
			)
		);
	}

	/**
	 * Proxy content from extracted eXeLearning files.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function proxy_content( $request ) {
		$proxy = new ExeLearning_Content_Proxy();
		return $proxy->serve_content( $request );
	}

	/**
	 * Check if user can edit the attachment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_edit_permission( $request ) {
		$attachment_id = $request->get_param( 'id' );

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to edit this file.', 'exelearning' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if user can read the attachment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_read_permission( $request ) {
		$attachment_id = $request->get_param( 'id' );

		if ( ! current_user_can( 'read_post', $attachment_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to read this file.', 'exelearning' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if user can upload files.
	 *
	 * @return bool True if user can upload files.
	 */
	public function check_upload_permission() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Create new ELP file from editor.
	 *
	 * @param WP_REST_Request $request Request object (unused, required by REST API).
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function create_elp_file( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WordPress REST API.
		// Verify file upload.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST API authentication handled by permission_callback.
		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file uploaded.', 'exelearning' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- REST API authentication handled by permission_callback.
		$uploaded_file = $_FILES['file'];

		// Verify upload was successful.
		if ( UPLOAD_ERR_OK !== $uploaded_file['error'] ) {
			return new WP_Error(
				'upload_error',
				__( 'File upload failed.', 'exelearning' ),
				array( 'status' => 500 )
			);
		}

		// Use WordPress media handling.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Sanitize filename and ensure ELP extension.
		$filename = sanitize_file_name( $uploaded_file['name'] );
		if ( ! preg_match( '/\.elp[x]?$/i', $filename ) ) {
			$filename = preg_replace( '/\.[^.]+$/', '', $filename ) . '.elp';
		}

		$file = array(
			'name'     => $filename,
			'type'     => 'application/zip',
			'tmp_name' => $uploaded_file['tmp_name'],
			'error'    => $uploaded_file['error'],
			'size'     => $uploaded_file['size'],
		);

		// Handle the upload.
		$upload = wp_handle_upload( $file, array( 'test_form' => false ) );

		if ( isset( $upload['error'] ) ) {
			return new WP_Error(
				'upload_error',
				$upload['error'],
				array( 'status' => 500 )
			);
		}

		// Get title from filename (without extension).
		$title = preg_replace( '/\.elp[x]?$/i', '', $filename );

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'application/zip',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Process the ELP file (extract and set metadata).
		// This triggers our upload handler hooks.
		$result = $this->reprocess_elp_file( $attachment_id, $upload['file'] );

		if ( is_wp_error( $result ) ) {
			// Clean up on failure.
			wp_delete_attachment( $attachment_id, true );
			return $result;
		}

		// Build the editor URL for the new attachment.
		$editor_url = admin_url(
			'admin.php?page=exelearning-editor&attachment_id=' . $attachment_id .
			'&_wpnonce=' . wp_create_nonce( 'exelearning_editor' )
		);

		return rest_ensure_response(
			array(
				'success'      => true,
				'message'      => __( 'File created successfully.', 'exelearning' ),
				'attachmentId' => $attachment_id,
				'url'          => wp_get_attachment_url( $attachment_id ),
				'editUrl'      => $editor_url,
			)
		);
	}

	/**
	 * Save modified ELP file.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function save_elp_file( $request ) {
		$attachment_id = $request->get_param( 'id' );

		// Validate attachment.
		$validation = $this->validate_save_attachment( $attachment_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Validate uploaded file.
		$uploaded_file = $this->validate_uploaded_file();
		if ( is_wp_error( $uploaded_file ) ) {
			return $uploaded_file;
		}

		// Validate file path and type.
		$old_file_path = $this->validate_elp_file_path( $attachment_id );
		if ( is_wp_error( $old_file_path ) ) {
			return $old_file_path;
		}

		// Save old extraction hash before it gets overwritten by reprocessing.
		$old_hash = get_post_meta( $attachment_id, '_exelearning_extracted', true );

		// Replace the file.
		if ( ! move_uploaded_file( $uploaded_file['tmp_name'], $old_file_path ) ) {
			// Fallback for environments where move_uploaded_file fails (e.g. PHP-WASM).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy, WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback path; failure is handled explicitly.
			if ( ! @copy( $uploaded_file['tmp_name'], $old_file_path ) ) {
				return new WP_Error(
					'move_failed',
					__( 'Failed to save the file.', 'exelearning' ),
					array( 'status' => 500 )
				);
			}

			// Verify size after copy to detect truncation (e.g. PHP-WASM disk limits).
			$copied_size   = filesize( $old_file_path );
			$expected_size = $uploaded_file['size'];
			if ( false === $copied_size || absint( $copied_size ) !== absint( $expected_size ) ) {
				return new WP_Error(
					'copy_truncated',
					__( 'File copy appears truncated.', 'exelearning' ),
					array( 'status' => 500 )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $uploaded_file['tmp_name'] );
		}

		// Re-process the ELP file (extract and update metadata).
		$result = $this->reprocess_elp_file( $attachment_id, $old_file_path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clean up old extraction only after new one succeeds.
		if ( $old_hash ) {
			$this->cleanup_extraction_by_hash( $old_hash );
		}

		// Update attachment modified date.
		wp_update_post(
			array(
				'ID'                => $attachment_id,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			)
		);

		return rest_ensure_response( $this->build_save_response( $attachment_id ) );
	}

	/**
	 * Validate attachment exists and is valid.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_save_attachment( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'Invalid attachment ID.', 'exelearning' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * Validate uploaded file from request.
	 *
	 * @return array|WP_Error Uploaded file array or WP_Error.
	 */
	private function validate_uploaded_file() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST API authentication handled by permission_callback.
		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file uploaded.', 'exelearning' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- REST API authentication handled by permission_callback.
		$uploaded_file = $_FILES['file'];

		if ( UPLOAD_ERR_OK !== $uploaded_file['error'] ) {
			return new WP_Error(
				'upload_error',
				__( 'File upload failed.', 'exelearning' ),
				array( 'status' => 500 )
			);
		}

		return $uploaded_file;
	}

	/**
	 * Validate ELP file path and extension.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|WP_Error File path or WP_Error.
	 */
	private function validate_elp_file_path( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'Original file not found.', 'exelearning' ),
				array( 'status' => 404 )
			);
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( 'elpx' !== $ext ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'This is not an eXeLearning file (.elpx).', 'exelearning' ),
				array( 'status' => 400 )
			);
		}

		return $file_path;
	}

	/**
	 * Clean up old extracted folder for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function cleanup_old_extraction( $attachment_id ) {
		$old_extracted = get_post_meta( $attachment_id, '_exelearning_extracted', true );
		if ( ! $old_extracted ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$old_folder = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $old_extracted . '/';

		if ( is_dir( $old_folder ) ) {
			$this->recursive_delete( $old_folder );
		}
	}

	/**
	 * Clean up extraction directory by hash.
	 *
	 * Unlike cleanup_old_extraction(), this takes a hash directly instead of
	 * reading it from meta (which may already be updated to the new hash).
	 *
	 * @param string $hash Extraction hash to clean up.
	 */
	private function cleanup_extraction_by_hash( $hash ) {
		if ( empty( $hash ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$folder     = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';

		if ( is_dir( $folder ) ) {
			$this->recursive_delete( $folder );
		}
	}

	/**
	 * Build success response for save operation.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Response data.
	 */
	private function build_save_response( $attachment_id ) {
		$extracted_hash = get_post_meta( $attachment_id, '_exelearning_extracted', true );
		$has_preview    = get_post_meta( $attachment_id, '_exelearning_has_preview', true );
		$preview_url    = null;

		if ( $extracted_hash && '1' === $has_preview ) {
			$preview_url = ExeLearning_Content_Proxy::get_proxy_url( $extracted_hash );
		}

		return array(
			'success'       => true,
			'message'       => __( 'File saved successfully.', 'exelearning' ),
			'attachment_id' => $attachment_id,
			'preview_url'   => $preview_url,
		);
	}

	/**
	 * Get ELP file data for loading into editor.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_elp_data( $request ) {
		$attachment_id = $request->get_param( 'id' );

		// Verify the attachment exists.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'Invalid attachment ID.', 'exelearning' ),
				array( 'status' => 404 )
			);
		}

		// Get file info.
		$file_url = wp_get_attachment_url( $attachment_id );
		$file     = get_attached_file( $attachment_id );
		$ext      = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( 'elpx' !== $ext ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'This is not an eXeLearning file (.elpx).', 'exelearning' ),
				array( 'status' => 400 )
			);
		}

		// Get metadata.
		$extracted_hash = get_post_meta( $attachment_id, '_exelearning_extracted', true );
		$has_preview    = get_post_meta( $attachment_id, '_exelearning_has_preview', true );
		$version        = get_post_meta( $attachment_id, '_exelearning_version', true );

		$response_data = array(
			'id'         => $attachment_id,
			'url'        => $file_url,
			'title'      => get_the_title( $attachment_id ),
			'filename'   => basename( $file ),
			'extension'  => $ext,
			'version'    => $version ? intval( $version ) : null,
			'hasPreview' => '1' === $has_preview,
			'previewUrl' => null,
			'metadata'   => array(
				'title'        => get_post_meta( $attachment_id, '_exelearning_title', true ),
				'description'  => get_post_meta( $attachment_id, '_exelearning_description', true ),
				'license'      => get_post_meta( $attachment_id, '_exelearning_license', true ),
				'language'     => get_post_meta( $attachment_id, '_exelearning_language', true ),
				'resourceType' => get_post_meta( $attachment_id, '_exelearning_resource_type', true ),
			),
		);

		// Add preview URL if available.
		if ( $extracted_hash && '1' === $has_preview ) {
			$response_data['previewUrl'] = ExeLearning_Content_Proxy::get_proxy_url( $extracted_hash );
		}

		return rest_ensure_response( $response_data );
	}

	/**
	 * Re-process ELP file after save (extract and update metadata).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file_path     Path to the ELP file.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function reprocess_elp_file( $attachment_id, $file_path ) {
		// Validate the file.
		$elp_service = new ExeLearning_Elp_File_Service();
		$result      = $elp_service->validate_elp_file( $file_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Generate new extraction directory.
		$upload_dir  = wp_upload_dir();
		$unique_hash = sha1( $file_path . time() );
		$destination = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $unique_hash . '/';

		if ( ! wp_mkdir_p( $destination ) ) {
			return new WP_Error(
				'mkdir_failed',
				__( 'Failed to create directory for extracted files.', 'exelearning' ),
				array( 'status' => 500 )
			);
		}

		// Extract the file.
		$extract_result = $elp_service->extract( $file_path, $destination );
		if ( is_wp_error( $extract_result ) ) {
			return $extract_result;
		}

		// Check if index.html exists.
		$has_preview = file_exists( $destination . 'index.html' );

		// Update metadata.
		update_post_meta( $attachment_id, '_exelearning_title', $elp_service->get_title() );
		update_post_meta( $attachment_id, '_exelearning_description', $elp_service->get_description() );
		update_post_meta( $attachment_id, '_exelearning_license', $elp_service->get_license() );
		update_post_meta( $attachment_id, '_exelearning_language', $elp_service->get_language() );
		update_post_meta( $attachment_id, '_exelearning_resource_type', $elp_service->get_learning_resource_type() );
		update_post_meta( $attachment_id, '_exelearning_extracted', $unique_hash );
		update_post_meta( $attachment_id, '_exelearning_version', $elp_service->get_version() );
		update_post_meta( $attachment_id, '_exelearning_has_preview', $has_preview ? '1' : '0' );

		// Update attachment title/caption.
		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_excerpt' => $elp_service->get_title(),
				'post_content' => $elp_service->get_description(),
			)
		);

		return true;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}

		if ( is_file( $dir ) || is_link( $dir ) ) {
			wp_delete_file( $dir );
		} else {
			$files = array_diff( scandir( $dir ), array( '.', '..' ) );
			foreach ( $files as $file ) {
				$this->recursive_delete( $dir . DIRECTORY_SEPARATOR . $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct filesystem access needed for cleanup.
			rmdir( $dir );
		}
	}
}
