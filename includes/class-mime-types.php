<?php
/**
 * Mime types registration class.
 *
 * This class registers and filters mime types.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Mime_Types.
 *
 * Manages custom mime type registration.
 */
class ExeLearning_Mime_Types {

	/**
	 * Registers custom mime types.
	 */
	public function register_mime_types() {
		add_filter( 'upload_mimes', array( $this, 'add_elp_mime_type' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_elpx_filetype' ), 10, 5 );
		add_filter( 'post_mime_types', array( $this, 'add_elpx_post_mime_type' ) );
		add_filter( 'ajax_query_attachments_args', array( $this, 'include_elpx_in_media_library' ) );
	}

	/**
	 * Adds .elpx mime type.
	 *
	 * @param array $mimes Current mime types.
	 * @return array Modified mime types.
	 */
	public function add_elp_mime_type( $mimes ) {
		// Add .elpx mime type for eXeLearning files.
		$mimes['elpx'] = 'application/zip';
		return $mimes;
	}

	/**
	 * Fix file type detection for .elpx files.
	 *
	 * @param array  $data     File data array.
	 * @param string $file     Full path to the file.
	 * @param string $filename The name of the file.
	 * @param array  $mimes    Allowed mime types.
	 * @param string $real_mime Real mime type of the file.
	 * @return array Modified file data.
	 */
	public function fix_elpx_filetype( $data, $file, $filename, $mimes, $real_mime = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter API.
		// If the extension is .elpx, ensure it's properly recognized.
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( 'elpx' === $ext ) {
			$data['ext']  = 'elpx';
			$data['type'] = 'application/zip';
		}

		return $data;
	}

	/**
	 * Add .elpx to the media library filter dropdown.
	 *
	 * @param array $post_mime_types Current post mime types.
	 * @return array Modified post mime types.
	 */
	public function add_elpx_post_mime_type( $post_mime_types ) {
		$post_mime_types['application/zip'] = array(
			__( 'eXeLearning', 'exelearning' ),
			__( 'Manage eXeLearning Files', 'exelearning' ),
			/* translators: %s: number of files */
			_n_noop( 'eXeLearning <span class="count">(%s)</span>', 'eXeLearning <span class="count">(%s)</span>', 'exelearning' ),
		);
		return $post_mime_types;
	}

	/**
	 * Ensure .elpx files are included in media library queries.
	 *
	 * @param array $query Query arguments for attachments.
	 * @return array Modified query arguments.
	 */
	public function include_elpx_in_media_library( $query ) {
		// If no specific post_mime_type is set, include all types (including application/zip).
		// This ensures .elpx files show up in "All media items" view.
		return $query;
	}
}
