<?php
/**
 * Frontend assets shared by every eXeLearning embed.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Embed_Assets.
 *
 * The shortcode and the block render the same controls (a fullscreen button, and
 * a poster that loads the package on click) and used to carry the behavior as an
 * inline <script> printed once per embed. Both now enqueue this one file instead.
 */
class ExeLearning_Embed_Assets {

	/**
	 * Enqueue the script that drives the embed controls.
	 *
	 * Called from the renderers rather than from `wp_enqueue_scripts`: a page with
	 * no embed has nothing for the script to bind, and shortcodes render during
	 * `the_content`, after that hook has already run. Safe to call repeatedly;
	 * WordPress de-duplicates by handle.
	 *
	 * @return void
	 */
	public static function enqueue() {
		wp_enqueue_script(
			'exelearning-embed',
			plugins_url( 'assets/js/exelearning-embed.js', EXELEARNING_PLUGIN_FILE ),
			array(),
			EXELEARNING_VERSION,
			true
		);
	}
}
