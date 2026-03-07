<?php
/**
 * Plugin filters registration class.
 *
 * This class defines filters for the plugin.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Filters.
 *
 * Manages registration of filters.
 */
class ExeLearning_Filters {

	/**
	 * Registers plugin filters.
	 */
	public function register_filters() {
		// Example: filter attachment metadata to support .elp file metadata.
		add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );
	}

	/**
	 * Filters attachment metadata.
	 *
	 * @param array $metadata Original attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 *
	 * @return array Modified attachment metadata.
	 */
	public function filter_attachment_metadata( $metadata, $attachment_id ) {
		// Early return if attachment_id is not valid.
		if ( empty( $attachment_id ) || ! is_numeric( $attachment_id ) ) {
			return $metadata;
		}

		// Process .elp file metadata if applicable.
		$file = get_attached_file( $attachment_id );
		if ( $file && $this->is_elp_file( $file ) ) {
			// Add custom metadata for .elp files.
			$metadata['exelearning'] = 'elp file metadata';
		}

		return $metadata;
	}

	/**
	 * Checks if a file is an .elp file.
	 *
	 * @param string $file File path.
	 *
	 * @return bool True if the file is an .elp file, false otherwise.
	 */
	private function is_elp_file( $file ) {
		if ( ! $file || ! is_string( $file ) ) {
			return false;
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		return 'elpx' === $ext;
	}
}
