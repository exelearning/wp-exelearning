<?php
/**
 * Reprocessor for existing eXeLearning (.elpx) attachments.
 *
 * Exposes the plugin's validation/extraction/metadata logic for attachments
 * that already live in the Media Library but were never processed (for example
 * uploaded before the plugin was active, or through a flow such as Formidable
 * Forms that does not run the upload handler). It reuses the same extraction
 * primitives the REST API uses for saving/creating files, so behaviour stays
 * consistent across every entry point.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Reprocessor.
 *
 * Validates, (re)extracts and updates metadata for existing .elpx attachments.
 */
class ExeLearning_Reprocessor {

	/**
	 * (Re)process an existing attachment so it becomes previewable.
	 *
	 * The new extraction is created in a fresh directory BEFORE any metadata is
	 * touched; only after it succeeds do we point the attachment at it and clean
	 * up the previous extraction. A failure therefore leaves the existing
	 * extraction and metadata untouched, and running it twice never leaves an
	 * orphaned directory (idempotent).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error { attachment_id, hash, has_preview } on success, WP_Error otherwise.
	 */
	public function reprocess( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'Invalid attachment ID.', 'exelearning' ),
				array( 'status' => 404 )
			);
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'The eXeLearning file could not be found on disk.', 'exelearning' ),
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

		// Remember the current extraction so we can clean it up only on success.
		$old_hash = get_post_meta( $attachment_id, '_exelearning_extracted', true );

		$extraction = $this->extract_to_new_dir( $file_path );
		if ( is_wp_error( $extraction ) ) {
			return $extraction;
		}

		$this->apply_metadata( $attachment_id, $extraction['service'], $extraction['hash'], $extraction['has_preview'] );

		// Drop the previous extraction only after the new one is committed.
		if ( $old_hash && $old_hash !== $extraction['hash'] ) {
			$this->cleanup_by_hash( $old_hash );
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'hash'          => $extraction['hash'],
			'has_preview'   => (bool) $extraction['has_preview'],
		);
	}

	/**
	 * Whether an attachment is an unprocessed (or stale) .elpx that should be reprocessed.
	 *
	 * Returns true for a .elpx attachment that has no extraction recorded, or
	 * whose recorded extraction directory no longer exists on disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function needs_reprocessing( $attachment_id ) {
		if ( ! $this->is_elpx_attachment( $attachment_id ) ) {
			return false;
		}

		$hash = get_post_meta( $attachment_id, '_exelearning_extracted', true );
		if ( empty( $hash ) ) {
			return true;
		}

		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';

		return ! is_dir( $dir );
	}

	/**
	 * Whether the attachment points at a .elpx file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_elpx_attachment( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return false;
		}

		return 'elpx' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	}

	/**
	 * Collect the IDs of every .elpx attachment that still needs processing.
	 *
	 * Used by the WP-CLI `--all` flow and the bulk/admin scan. The cheap meta
	 * LIKE query narrows the set to attachments whose file name contains
	 * `.elpx`; needs_reprocessing() then confirms the extension and extraction
	 * state for each candidate.
	 *
	 * @return int[] Attachment IDs.
	 */
	public function get_reprocessable_attachment_ids() {
		$ids = array();
		foreach ( $this->get_elpx_attachment_ids() as $id ) {
			if ( $this->needs_reprocessing( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Collect the IDs of every .elpx attachment, regardless of extraction state.
	 *
	 * Used by the WP-CLI `--all --force` flow. The cheap meta LIKE query narrows
	 * the candidates; is_elpx_attachment() then confirms the real extension.
	 *
	 * @return int[] Attachment IDs.
	 */
	public function get_elpx_attachment_ids() {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off admin/CLI maintenance scan.
					array(
						'key'     => '_wp_attached_file',
						'value'   => '.elpx',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$ids = array();
		foreach ( $query->posts as $id ) {
			if ( $this->is_elpx_attachment( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Validate an ELP file and extract it to a fresh, unique directory.
	 *
	 * Does NOT touch any attachment metadata; on failure it cleans up the
	 * partially-created directory so callers can treat it as atomic. Also writes
	 * the security .htaccess guard so freshly-created extraction roots (e.g. on a
	 * site that installed the plugin after files were uploaded) are protected.
	 *
	 * @param string $file_path Path to the .elpx file to process.
	 * @return array|WP_Error { service: ExeLearning_Elp_File_Service, hash: string, has_preview: bool } or WP_Error.
	 */
	public function extract_to_new_dir( $file_path ) {
		$elp_service = new ExeLearning_Elp_File_Service();
		$result      = $elp_service->validate_elp_file( $file_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Unique hash: microtime()+wp_rand() avoids collisions for two runs of
		// the same path within one second. Kept as sha1 (40 hex) for the
		// content-proxy route regex.
		$upload_dir  = wp_upload_dir();
		$unique_hash = sha1( $file_path . microtime( true ) . wp_rand() );
		$destination = trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $unique_hash . '/';

		if ( ! wp_mkdir_p( $destination ) ) {
			return new WP_Error(
				'mkdir_failed',
				__( 'Failed to create directory for extracted files.', 'exelearning' ),
				array( 'status' => 500 )
			);
		}

		// Ensure the parent directory is guarded even when this attachment was
		// uploaded before the plugin (and its upload filter) ever ran.
		ExeLearning_Elp_Upload_Handler::write_security_htaccess();

		$extract_result = $elp_service->extract( $file_path, $destination );
		if ( is_wp_error( $extract_result ) ) {
			$this->cleanup_by_hash( $unique_hash );
			return $extract_result;
		}

		return array(
			'service'     => $elp_service,
			'hash'        => $unique_hash,
			'has_preview' => file_exists( $destination . 'index.html' ),
		);
	}

	/**
	 * Persist ELP metadata for an attachment after a successful extraction.
	 *
	 * @param int                          $attachment_id Attachment ID.
	 * @param ExeLearning_Elp_File_Service $elp_service   Parsed ELP service.
	 * @param string                       $hash          Extraction hash.
	 * @param bool                         $has_preview   Whether index.html exists.
	 */
	public function apply_metadata( $attachment_id, $elp_service, $hash, $has_preview ) {
		update_post_meta( $attachment_id, '_exelearning_title', $elp_service->get_title() );
		update_post_meta( $attachment_id, '_exelearning_description', $elp_service->get_description() );
		update_post_meta( $attachment_id, '_exelearning_license', $elp_service->get_license() );
		update_post_meta( $attachment_id, '_exelearning_language', $elp_service->get_language() );
		update_post_meta( $attachment_id, '_exelearning_resource_type', $elp_service->get_learning_resource_type() );
		update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
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
	}

	/**
	 * Delete an extraction directory by hash.
	 *
	 * @param string $hash Extraction hash to clean up.
	 */
	public function cleanup_by_hash( $hash ) {
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
