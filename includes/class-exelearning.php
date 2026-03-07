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
	 * Instance of hooks handler.
	 *
	 * @var ExeLearning_Hooks
	 */
	private $hooks;

	/**
	 * Instance of filters handler.
	 *
	 * @var ExeLearning_Filters
	 */
	private $filters;

	/**
	 * Instance of custom post types handler.
	 *
	 * @var ExeLearning_Post_Types
	 */
	private $post_types;

	/**
	 * Instance of mime types handler.
	 *
	 * @var ExeLearning_Mime_Types
	 */
	private $mime_types;

	/**
	 * Instance of shortcodes handler.
	 *
	 * @var ExeLearning_Shortcodes
	 */
	private $shortcodes;

	/**
	 * Instance of media library integration.
	 *
	 * @var ExeLearning_Media_Library
	 */
	private $media_library;

	/**
	 * Instance of internationalization handler.
	 *
	 * @var ExeLearning_I18n
	 */
	private $i18n;

	/**
	 * Instance of admin settings.
	 *
	 * @var ExeLearning_Admin_Settings
	 */
	private $admin_settings;

	/**
	 * Instance of admin upload handler.
	 *
	 * @var ExeLearning_Admin_Upload
	 */
	private $admin_upload;

	/**
	 * Instance of elp upload handler.
	 *
	 * @var ExeLearning_ELP_Upload
	 */
	private $elp_upload_handler;

	/**
	 * Instance of elp upload block.
	 *
	 * @var ExeLearning_ELP_Upload_Block
	 */
	private $elp_upload_block;

	/**
	 * Instance of the editor integration.
	 *
	 * @var ExeLearning_Editor
	 */
	private $editor;

	/**
	 * Instance of the REST API handler.
	 *
	 * @var ExeLearning_REST_API
	 */
	private $rest_api;

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
		$this->hooks              = new ExeLearning_Hooks();
		$this->filters            = new ExeLearning_Filters();
		$this->post_types         = new ExeLearning_Post_Types();
		$this->mime_types         = new ExeLearning_Mime_Types();
		$this->shortcodes         = new ExeLearning_Shortcodes();
		$this->media_library      = new ExeLearning_Media_Library();
		$this->i18n               = new ExeLearning_I18n();
		$this->admin_settings     = new ExeLearning_Admin_Settings();
		$this->admin_upload       = new ExeLearning_Admin_Upload();
		$this->elp_upload_handler = new ExeLearning_Elp_Upload_Handler();
		$this->elp_upload_block   = new ExeLearning_Elp_Upload_Block();
		$this->editor             = new ExeLearning_Editor();
		$this->rest_api           = new ExeLearning_REST_API();
	}

	/**
	 * Sets up plugin hooks.
	 */
	private function setup_hooks() {
		add_action( 'init', array( $this->hooks, 'register_hooks' ) );
		add_action( 'init', array( $this->post_types, 'register_post_types' ) );
		add_action( 'init', array( $this->shortcodes, 'register_shortcodes' ) );
		$this->filters->register_filters();
		$this->mime_types->register_mime_types();
		$this->elp_upload_handler->register();
	}

	/**
	 * Loads plugin translations.
	 */
	private function load_i18n() {
		add_action( 'plugins_loaded', array( $this->i18n, 'load_textdomain' ) );
	}

	/**
	 * Runs the plugin.
	 */
	public function run() {
		// Additional execution code can be added here.
	}
}
