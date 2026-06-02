<?php
/**
 * Plugin hooks registration class.
 *
 * This class defines actions and filters for the plugin.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Hooks.
 *
 * Placeholder for plugin-wide action/filter registration.
 *
 * This plugin works directly with WordPress attachments (Media Library) and does
 * not register a custom post type. The previous boilerplate registered a public
 * `exelearning` CPT and a no-op `the_content` filter; both were removed because
 * they exposed unwanted public routes/menus and contradicted the architecture
 * documented in {@see ExeLearning_Post_Types}.
 */
class ExeLearning_Hooks {

	/**
	 * Registers plugin hooks.
	 *
	 * Intentionally empty: feature-specific hooks are registered by their own
	 * classes (mime types, upload handler, REST API, editor, etc.).
	 */
	public function register_hooks() {
		// No global hooks needed. See class docblock.
	}
}
