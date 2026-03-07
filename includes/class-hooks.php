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
 * Manages registration of actions and filters.
 */
class ExeLearning_Hooks {

	/**
	 * Registers plugin hooks.
	 */
	public function register_hooks() {
		// Example: register a custom post type.
		add_action( 'init', array( $this, 'register_custom_post_type' ) );

		// Example: modify post content.
		add_filter( 'the_content', array( $this, 'modify_content' ) );
	}

	/**
	 * Registers a custom post type.
	 */
	public function register_custom_post_type() {
		$args = array(
			'label'        => 'eXeLearning',
			'public'       => true,
			'show_in_menu' => true,
		);

		register_post_type( 'exelearning', $args );
	}

	/**
	 * Modifies post content.
	 *
	 * @param string $content Original post content.
	 *
	 * @return string Modified post content.
	 */
	public function modify_content( $content ) {
		// Modify the content as needed.
		return $content;
	}
}
