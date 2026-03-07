<?php
/**
 * File upload handler for eXeLearning files.
 *
 * This class validates and extracts .elp files upon upload.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Elp_Upload_Handler.
 *
 * Processes the .elp file uploads.
 */
class ExeLearning_Elp_Upload_Handler {

	/**
	 * Registers the upload filter.
	 */
	public function register() {
		add_filter( 'wp_handle_upload', array( $this, 'process_elp_upload' ) );
		add_action( 'delete_attachment', array( $this, 'exelearning_delete_extracted_folder' ) );

		add_action( 'add_attachment', array( $this, 'save_elp_metadata' ) );
	}

	/**
	 * Processes .elp file uploads.
	 *
	 * Checks if the uploaded file is a valid .elp (zip) file, verifies its structure,
	 * and extracts it to a secure folder with a unique hash.
	 *
	 * @param array $upload The upload data.
	 *
	 * @return array|WP_Error Modified upload data or WP_Error on failure.
	 */
	public function process_elp_upload( $upload ) {
		$file = $upload['file'];
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		// Process only .elpx files (.elp is no longer supported).
		if ( 'elpx' !== $ext ) {
			return $upload;
		}

		// Validate the .elp file using the ELP File Service.
		$elp_service = new ExeLearning_Elp_File_Service();
		$result      = $elp_service->validate_elp_file( $file );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $file );
			return $result;
		}

		// Determine a secure destination folder.
		$upload_dir  = wp_upload_dir();
		$unique_hash = sha1( $file . time() );
		$destination = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $unique_hash . '/';

		if ( ! wp_mkdir_p( $destination ) ) {
			return new WP_Error( 'mkdir_failed', 'Failed to create directory for extracted files.' );
		}

		// Create security .htaccess to block direct access.
		$this->create_security_htaccess();

		// Extract the file.
		$extract_result = $elp_service->extract( $file, $destination );
		if ( is_wp_error( $extract_result ) ) {
			wp_delete_file( $file );
			return $extract_result;
		}

		// Check if index.html exists (only version 3 files have it).
		$has_preview = file_exists( $destination . 'index.html' );

		$post_data = array(
			'post_excerpt' => $elp_service->get_title(),       // Title goes to caption.
			'post_content' => $elp_service->get_description(), // Description goes to content.
		);

		// Store metadata in a transient for later use.
		$metadata = array(
			'_exelearning_title'         => $elp_service->get_title(),
			'_exelearning_description'   => $elp_service->get_description(),
			'_exelearning_license'       => $elp_service->get_license(),
			'_exelearning_language'      => $elp_service->get_language(),
			'_exelearning_resource_type' => $elp_service->get_learning_resource_type(),
			'_exelearning_extracted'     => $unique_hash,
			'_exelearning_version'       => $elp_service->get_version(),
			'_exelearning_has_preview'   => $has_preview ? '1' : '0',
		);

		$transient_key = 'exelearning_data_' . md5( $file );

		set_transient(
			$transient_key,
			array(
				'post_data' => $post_data,
				'metadata'  => $metadata,
			),
			300
		);

		// Optionally, remove the original .elp file.
		// Example: unlink( $file ).

		return $upload;
	}

	/**
	 * Saves ELP metadata to the attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function save_elp_metadata( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'elpx' !== $ext ) {
			return;
		}

		$transient_key = 'exelearning_data_' . md5( $file );
		$data          = get_transient( $transient_key );

		if ( $data ) {
			// Update main attachment fields.
			wp_update_post(
				array_merge(
					array( 'ID' => $attachment_id ),
					$data['post_data']
				)
			);

			// Save additional metadata.
			foreach ( $data['metadata'] as $key => $value ) {
				update_post_meta( $attachment_id, $key, $value );
			}

			delete_transient( $transient_key );
		}
	}

	/**
	 * Recursively deletes a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 */
	private function exelearning_recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			wp_delete_file( $dir );
		} else {
			$files = array_diff( scandir( $dir ), array( '.', '..' ) );
			foreach ( $files as $file ) {
				$this->exelearning_recursive_delete( $dir . DIRECTORY_SEPARATOR . $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct filesystem access needed for cleanup.
			rmdir( $dir );
		}
	}

	/**
	 * Deletes the extracted folder associated with an attachment.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function exelearning_delete_extracted_folder( $post_id ) {
		$directory = get_post_meta( $post_id, '_exelearning_extracted', true );

		if ( $directory ) {
			$upload_dir = wp_upload_dir();
			$full_path  = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $directory . '/';

			if ( is_dir( $full_path ) ) {
				$this->exelearning_recursive_delete( $full_path );
			}
		}
	}

	/**
	 * Creates a security .htaccess file to block direct access to extracted content.
	 *
	 * All content must be served through the secure proxy controller.
	 */
	private function create_security_htaccess() {
		$upload_dir    = wp_upload_dir();
		$htaccess_path = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/.htaccess';

		// Only create if it doesn't exist.
		if ( file_exists( $htaccess_path ) ) {
			return;
		}

		$htaccess_content = <<<'HTACCESS'
# Security: Block direct access to eXeLearning extracted content
# All content must be served through the secure proxy controller

# Deny all direct access
<IfModule mod_authz_core.c>
    # Apache 2.4+
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    # Apache 2.2
    Order deny,allow
    Deny from all
</IfModule>

# Alternative: return 403 for all requests
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^ - [F,L]
</IfModule>
HTACCESS;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess_path, $htaccess_content );
	}
}
