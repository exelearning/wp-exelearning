<?php
/**
 * Post types registration class.
 *
 * This plugin works directly with WordPress attachments (Media Library).
 * No custom post type is needed - ELP files are uploaded as attachments
 * and their metadata is stored in attachment post meta.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Post_Types.
 *
 * Placeholder class for potential future post type needs.
 * Currently, the plugin uses WordPress attachments directly.
 */
class ExeLearning_Post_Types {

	/**
	 * Registers custom post types.
	 *
	 * Currently empty - ELP files are managed as attachments.
	 */
	public function register_post_types() {
		// No custom post type needed.
		// ELP files are uploaded to Media Library as attachments.
		// Metadata is stored in attachment post meta:
		// - _exelearning_title
		// - _exelearning_description
		// - _exelearning_license
		// - _exelearning_language
		// - _exelearning_resource_type
		// - _exelearning_extracted (hash to extracted folder).
	}
}
