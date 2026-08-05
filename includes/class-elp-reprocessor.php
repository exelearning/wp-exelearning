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
 * Validates, (re)extracts and updates metadata for existing .elpx attachments
 * (and .zip attachments whose contents validate as eXeLearning).
 */
class ExeLearning_Reprocessor {

	/**
	 * File extensions the reprocessor will consider as eXeLearning candidates.
	 *
	 * `.elpx` is the canonical extension; `.zip` is accepted too because a real
	 * eXeLearning source project can land in the Media Library renamed or stored
	 * by a flow (e.g. Formidable Forms) that kept a generic extension. A `.zip`
	 * is only ever acted upon once its contents validate as eXeLearning, so plain
	 * backup archives and the plugin's own web/SCORM/IMS exports are ignored.
	 *
	 * @var string[]
	 */
	const ACCEPTED_EXTENSIONS = array( 'elpx', 'zip' );

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
		if ( ! in_array( $ext, self::ACCEPTED_EXTENSIONS, true ) ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'This is not an eXeLearning file (.elpx).', 'exelearning' ),
				array( 'status' => 400 )
			);
		}

		// For a .zip the extension alone proves nothing; extract_to_new_dir()
		// below validates the contents (content.xml) and returns a clear error
		// for a non-eXeLearning archive without writing any metadata.

		// Remember the current extraction so we can clean it up only on success.
		$old_hash = get_post_meta( $attachment_id, '_exelearning_extracted', true );

		$extraction = $this->extract_to_new_dir( $file_path );
		if ( is_wp_error( $extraction ) ) {
			return $extraction;
		}

		// The archive validated as a real eXeLearning project. If it arrived with
		// a generic .zip extension, give it the canonical .elpx name so the
		// editor, exporter and save flow (all .elpx-only) accept it too.
		$this->normalize_zip_to_elpx( $attachment_id, $file_path );

		$this->apply_metadata( $attachment_id, $extraction['service'], $extraction['hash'], $extraction['has_preview'] );

		// Retire the previous extraction only after the new one is committed:
		// the old hash is preserved as a redirectable alias before its
		// directory is removed. See ADR-68-01.
		$this->retire_extraction( $attachment_id, (string) $old_hash, $extraction['hash'] );

		return array(
			'attachment_id' => (int) $attachment_id,
			'hash'          => $extraction['hash'],
			'has_preview'   => (bool) $extraction['has_preview'],
		);
	}

	/**
	 * Whether an attachment is an unprocessed (or stale) eXeLearning file.
	 *
	 * Returns true for an eligible attachment (a .elpx, or a .zip whose contents
	 * validate as eXeLearning) that has no extraction recorded, or whose recorded
	 * extraction directory no longer exists on disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function needs_reprocessing( $attachment_id ) {
		if ( ! $this->is_eligible( $attachment_id ) ) {
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
	 * Whether the attachment has an accepted extension (.elpx or .zip).
	 *
	 * Cheap, extension-only check with no file I/O. A positive result does NOT
	 * guarantee the file is really eXeLearning content; use is_eligible() (which
	 * also content-validates .zip files) before scanning or bulk processing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_exelearning_candidate( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return false;
		}

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return in_array( $ext, self::ACCEPTED_EXTENSIONS, true );
	}

	/**
	 * Whether an attachment should be treated as eXeLearning content.
	 *
	 * `.elpx` candidates are eligible by extension; `.zip` candidates are only
	 * eligible when their contents validate as an eXeLearning project. This is
	 * the content-gate that keeps plain backup archives out of the scan and bulk
	 * flows.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_eligible( $attachment_id ) {
		if ( ! $this->is_exelearning_candidate( $attachment_id ) ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( 'elpx' === $ext ) {
			return true;
		}

		// .zip: only eligible if the archive actually contains eXeLearning content.
		return $this->is_valid_elp_file( $file );
	}

	/**
	 * Whether a file on disk validates as an eXeLearning project (content sniff).
	 *
	 * Reuses the file service's parse-only validation (requires content.xml)
	 * without extracting anything.
	 *
	 * @param string $file_path Absolute path to the file.
	 * @return bool
	 */
	private function is_valid_elp_file( $file_path ) {
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		$service = new ExeLearning_Elp_File_Service();

		return ! is_wp_error( $service->validate_elp_file( $file_path ) );
	}

	/**
	 * Collect the IDs of every eXeLearning attachment that still needs processing.
	 *
	 * Used by the WP-CLI `--all` flow and the bulk/admin scan. The cheap meta
	 * LIKE query narrows the set to .elpx/.zip attachments; needs_reprocessing()
	 * then content-validates each candidate and checks its extraction state.
	 *
	 * @return int[] Attachment IDs.
	 */
	public function get_reprocessable_attachment_ids() {
		$ids = array();
		foreach ( $this->query_candidate_ids() as $id ) {
			if ( $this->needs_reprocessing( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Collect the IDs of every eligible eXeLearning attachment, processed or not.
	 *
	 * Used by the WP-CLI `--all --force` flow. Plain .zip archives are filtered
	 * out by the is_eligible() content gate.
	 *
	 * @return int[] Attachment IDs.
	 */
	public function get_candidate_attachment_ids() {
		$ids = array();
		foreach ( $this->query_candidate_ids() as $id ) {
			if ( $this->is_eligible( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return $ids;
	}

	/**
	 * Query attachments whose file name ends in an accepted extension.
	 *
	 * The meta LIKE clauses are a cheap pre-filter; is_exelearning_candidate()
	 * (via the callers) confirms the exact extension afterwards.
	 *
	 * @return int[] Attachment IDs.
	 */
	private function query_candidate_ids() {
		$meta_query = array( 'relation' => 'OR' );
		foreach ( self::ACCEPTED_EXTENSIONS as $ext ) {
			$meta_query[] = array(
				'key'     => '_wp_attached_file',
				'value'   => '.' . $ext,
				'compare' => 'LIKE',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-off admin/CLI maintenance scan.
			)
		);

		return array_map( 'intval', $query->posts );
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
		$metadata = array(
			'_exelearning_title'         => $elp_service->get_title(),
			'_exelearning_description'   => $elp_service->get_description(),
			'_exelearning_license'       => $elp_service->get_license(),
			'_exelearning_language'      => $elp_service->get_language(),
			'_exelearning_resource_type' => $elp_service->get_learning_resource_type(),
			'_exelearning_extracted'     => $hash,
			'_exelearning_version'       => $elp_service->get_version(),
			'_exelearning_has_preview'   => $has_preview ? '1' : '0',
		);

		// Allow integrations to enrich metadata (required keys are preserved).
		$file     = (string) get_attached_file( $attachment_id );
		$metadata = ExeLearning_Elp_File_Service::filter_metadata( $metadata, $file, $elp_service );

		foreach ( $metadata as $key => $value ) {
			update_post_meta( $attachment_id, $key, $value );
		}

		// Update attachment title/caption.
		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_excerpt' => $elp_service->get_title(),
				'post_content' => $elp_service->get_description(),
			)
		);

		/**
		 * Fires after ELPX metadata has been saved to the attachment.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $attachment_id WordPress attachment ID.
		 * @param array $metadata      Final metadata array that was saved.
		 */
		do_action( 'exelearning_after_elpx_metadata_saved', $attachment_id, $metadata );
	}

	/**
	 * Retire a superseded extraction hash after a successful commit.
	 *
	 * Persists the obsolete-hash alias — so the content proxy can redirect
	 * stale URLs to the attachment's current extraction — and deletes the old
	 * extraction directory only after the alias is verified as stored. When
	 * the alias is refused (the hash still serves live content for some
	 * attachment, is owned by another attachment, or persistence failed) the
	 * directory is retained, so previously published URLs keep working
	 * instead of turning into dead links. See
	 * docs/architecture/changes/68-stale-content-url-redirects/.
	 *
	 * @param int    $attachment_id Attachment whose extraction changed.
	 * @param string $old_hash      Previous extraction hash ('' if none).
	 * @param string $new_hash      Newly committed extraction hash.
	 */
	public function retire_extraction( $attachment_id, $old_hash, $new_hash ) {
		if ( empty( $old_hash ) || $old_hash === $new_hash ) {
			return;
		}

		$aliases = new ExeLearning_Content_Hash_Aliases();
		if ( ! $aliases->register( $attachment_id, $old_hash ) ) {
			return;
		}

		$this->cleanup_by_hash( $old_hash );
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
	 * Rename a validated .zip attachment to the canonical .elpx extension.
	 *
	 * Once extraction has confirmed the archive is a real eXeLearning project,
	 * renaming a generic .zip to .elpx makes the file a first-class citizen
	 * everywhere — the editor, exporter and save flow all key off the .elpx
	 * extension. Embedding uses the attachment ID, so existing shortcodes keep
	 * working. Non-fatal: if the move fails the preview still works, the file
	 * just keeps its .zip name.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file_path     Current file path.
	 * @return string Resulting file path (.elpx on success, unchanged otherwise).
	 */
	private function normalize_zip_to_elpx( $attachment_id, $file_path ) {
		if ( 'zip' !== strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) ) {
			return $file_path;
		}

		$dir      = dirname( $file_path );
		$new_name = wp_unique_filename( $dir, preg_replace( '/\.zip$/i', '.elpx', basename( $file_path ) ) );
		$new_path = trailingslashit( $dir ) . $new_name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- In-place move on the same filesystem; a failure is non-fatal and handled below.
		if ( ! @rename( $file_path, $new_path ) ) {
			return $file_path;
		}

		update_attached_file( $attachment_id, $new_path );

		return $new_path;
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
