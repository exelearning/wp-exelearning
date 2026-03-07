<?php
/**
 * Admin settings page.
 *
 * This class registers and handles the admin settings page.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	exit;
}

/**
 * Class ExeLearning_Admin_Settings
 *
 * Manages the admin settings page.
 */
class ExeLearning_Admin_Settings {

	/**
	 * Option name for settings.
	 *
	 * @var string
	 */
	private $option_name = 'exelearning_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/wp-exelearning.php' ), array( $this, 'add_action_links' ) );
	}

	/**
	 * Adds a settings link to the plugin action links.
	 *
	 * @param array $links Current plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=exelearning-settings' ) ),
			esc_html__( 'Settings', 'exelearning' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Adds the admin menu item.
	 */
	public function add_admin_menu() {
		add_options_page(
			esc_html__( 'eXeLearning Settings', 'exelearning' ),
			esc_html__( 'eXeLearning', 'exelearning' ),
			'manage_options',
			'exelearning-settings',
			array( $this, 'display_settings_page' )
		);
	}

	/**
	 * Registers plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'exelearning_settings_group',
			$this->option_name,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'exelearning_settings_section',
			esc_html__( 'General Settings', 'exelearning' ),
			'__return_null',
			'exelearning-settings'
		);

		add_settings_field(
			'exelearning_option_example',
			esc_html__( 'Example Option', 'exelearning' ),
			array( $this, 'display_option_example' ),
			'exelearning-settings',
			'exelearning_settings_section'
		);
	}

	/**
	 * Sanitizes settings input.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['example_option'] ) ) {
			$sanitized['example_option'] = sanitize_text_field( $input['example_option'] );
		}

		return $sanitized;
	}

	/**
	 * Displays the example option field.
	 */
	public function display_option_example() {
		$options = get_option( $this->option_name );
		$value   = isset( $options['example_option'] ) ? $options['example_option'] : '';
		?>
		<input type="text" name="<?php echo esc_attr( $this->option_name ); ?>[example_option]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
		<?php
	}

	/**
	 * Displays the settings page.
	 */
	public function display_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'eXeLearning Settings', 'exelearning' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'exelearning_settings_group' );
				do_settings_sections( 'exelearning-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
