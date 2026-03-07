<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://exelearning.net/
 * @package    Exelearning
 * @subpackage Exelearning/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Exelearning
 * @subpackage Exelearning/admin
 * @author     INTEF <cedec@educacion.gob.es>
 */
class Exelearning_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		$this->load_dependencies();
		add_filter( 'plugin_action_links_' . plugin_basename( EXELEARNING_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
		add_action( 'save_post_exelearning', array( $this, 'save_post' ), 10, 1 );
	}

	/**
	 * Add settings link to the plugins page.
	 *
	 * @param array $links The existing links.
	 * @return array The modified links.
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=exelearning_settings' ) . '">' . __( 'Settings', 'exelearning' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Load the required dependencies for this class.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-exelearning-admin-settings.php';

		if ( ! has_action( 'admin_menu', array( 'Exelearning_Admin_Settings', 'create_menu' ) ) ) {
			new Exelearning_Admin_Settings();
		}
	}


	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( 'settings_page_exelearning_settings' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/exelearning-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'settings_page_exelearning_settings', 'upload.php', 'media-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/exelearning-admin.js', array( 'jquery' ), $this->version, false );

		wp_localize_script(
			$this->plugin_name,
			'exelearningAdmin',
			array(
				'title'        => __( 'Select eXeLearning File', 'exelearning' ),
				'button'       => __( 'Use this file', 'exelearning' ),
				'allowedTypes' => array(
					'application/zip',
					'.elp',
				),
			)
		);
	}

	/**
	 * Save post metadata when a post is saved.
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_post( $post_id ) {
		// Check if our nonce is set.
		if ( ! isset( $_POST['exelearning_content_nonce'] ) ) {
			return;
		}

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['exelearning_content_nonce'] ) ), 'exelearning_content_metabox' ) ) {
			return;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check the user's permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save the .elp file ID.
		if ( isset( $_POST['exelearning_file_id'] ) ) {
			update_post_meta( $post_id, '_exelearning_file_id', sanitize_text_field( wp_unslash( $_POST['exelearning_file_id'] ) ) );
		}
	}
}
