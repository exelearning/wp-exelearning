<?php
/**
 * Main plugin class.
 *
 * This class is responsible for loading and initializing all plugin components.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning.
 *
 * Loads and initializes all components of the plugin.
 */
class ExeLearning {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Instantiated plugin components, keyed by short name.
	 *
	 * Grouped into a single registry instead of one property per component so
	 * the class stays under the field-count limit and new components don't
	 * require a new property each.
	 *
	 * @var array<string, object>
	 */
	private $components = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->version = EXELEARNING_VERSION;
		$this->load_dependencies();
		$this->init_components();
		$this->setup_hooks();
		$this->load_i18n();
	}

	/**
	 * Loads required dependencies.
	 *
	 * Additional dependencies can be loaded here if needed.
	 */
	private function load_dependencies() {
		// All required files are loaded via require_once in the main plugin file.
	}

	/**
	 * Initializes all plugin components.
	 */
	private function init_components() {
		$this->components = array(
			'hooks'              => new ExeLearning_Hooks(),
			'filters'            => new ExeLearning_Filters(),
			'post_types'         => new ExeLearning_Post_Types(),
			'mime_types'         => new ExeLearning_Mime_Types(),
			'shortcodes'         => new ExeLearning_Shortcodes(),
			'media_library'      => new ExeLearning_Media_Library(),
			'i18n'               => new ExeLearning_I18n(),
			'admin_settings'     => new ExeLearning_Admin_Settings(),
			'admin_upload'       => new ExeLearning_Admin_Upload(),
			'elp_upload_handler' => new ExeLearning_Elp_Upload_Handler(),
			'elp_upload_block'   => new ExeLearning_Elp_Upload_Block(),
			'editor'             => new ExeLearning_Editor(),
			'export_bootstrap'   => new ExeLearning_Export_Bootstrap(),
			'rest_api'           => new ExeLearning_REST_API(),
			'preview_proxy'      => new ExeLearning_Preview_Proxy(),
		);

		if ( is_admin() ) {
			$this->components['editor_installer'] = new ExeLearning_Static_Editor_Installer();
			$this->components['admin_styles']     = new ExeLearning_Admin_Styles();
		}
	}

	/**
	 * Sets up plugin hooks.
	 */
	private function setup_hooks() {
		add_action( 'init', array( $this->components['hooks'], 'register_hooks' ) );
		add_action( 'init', array( $this->components['post_types'], 'register_post_types' ) );
		add_action( 'init', array( $this->components['shortcodes'], 'register_shortcodes' ) );
		$this->components['filters']->register_filters();
		$this->components['mime_types']->register_mime_types();
		$this->components['elp_upload_handler']->register();
	}

	/**
	 * Loads plugin translations.
	 */
	private function load_i18n() {
		add_action( 'plugins_loaded', array( $this->components['i18n'], 'load_textdomain' ) );
	}

	/**
	 * Runs the plugin.
	 */
	public function run() {
		// Additional execution code can be added here.
	}
}
