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
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
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
	 * Displays the settings page.
	 */
	public function display_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'eXeLearning Settings', 'exelearning' ); ?></h1>

			<?php $this->render_editor_status_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the embedded editor status and install section.
	 */
	private function render_editor_status_section() {
		$is_installed = ExeLearning_Static_Editor_Installer::is_editor_installed();
		$version_data = ExeLearning_Static_Editor_Installer::get_installed_version();
		$nonce        = wp_create_nonce( ExeLearning_Static_Editor_Installer::AJAX_ACTION );
		$ajax_url     = admin_url( 'admin-ajax.php' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check.
		$editor_missing = isset( $_GET['editor-missing'] ) && '1' === $_GET['editor-missing'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$return_attachment = isset( $_GET['return_attachment'] ) ? absint( $_GET['return_attachment'] ) : 0;
		?>
		<div class="card" style="max-width: 800px; margin-bottom: 20px;">
			<h2><?php esc_html_e( 'Embedded Editor', 'exelearning' ); ?></h2>

			<?php if ( $editor_missing ) : ?>
				<div class="notice notice-warning inline" style="margin: 10px 0;">
					<p>
						<strong><?php esc_html_e( 'The embedded editor is required to edit eXeLearning files.', 'exelearning' ); ?></strong>
						<?php esc_html_e( 'Please install it using the button below.', 'exelearning' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $is_installed ) : ?>
				<p>
					<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
					<strong><?php esc_html_e( 'Status:', 'exelearning' ); ?></strong>
					<?php esc_html_e( 'Installed', 'exelearning' ); ?>
				</p>
				<?php if ( $version_data ) : ?>
					<p>
						<strong><?php esc_html_e( 'Version:', 'exelearning' ); ?></strong>
						<?php echo esc_html( $version_data['version'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Installed on:', 'exelearning' ); ?></strong>
						<?php echo esc_html( $version_data['installed_at'] ); ?>
					</p>
				<?php endif; ?>
				<p>
					<button type="button" id="exelearning-update-editor" class="button"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
						<?php esc_html_e( 'Update to Latest Version', 'exelearning' ); ?>
					</button>
				</p>
			<?php else : ?>
				<p>
					<span class="dashicons dashicons-warning" style="color: #dba617;"></span>
					<strong><?php esc_html_e( 'Status:', 'exelearning' ); ?></strong>
					<?php esc_html_e( 'Not installed', 'exelearning' ); ?>
				</p>
				<p><?php esc_html_e( 'The embedded eXeLearning editor is not installed. You can download and install the latest version automatically from GitHub.', 'exelearning' ); ?></p>
				<p>
					<button type="button" id="exelearning-install-editor" class="button button-primary"
						data-nonce="<?php echo esc_attr( $nonce ); ?>"
						data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
						<?php esc_html_e( 'Download & Install Editor', 'exelearning' ); ?>
					</button>
				</p>
			<?php endif; ?>

			<?php if ( $return_attachment ) : ?>
				<input type="hidden" id="exelearning-return-attachment" value="<?php echo esc_attr( $return_attachment ); ?>" />
			<?php endif; ?>

			<div id="exelearning-install-status" style="display: none; margin-top: 10px;"></div>

			<p class="description" style="margin-top: 15px;">
				<?php
				printf(
					/* translators: %s: make build-editor command */
					esc_html__( 'Developers can also build the editor from source using %s.', 'exelearning' ),
					'<code>make build-editor</code>'
				);
				?>
			</p>
		</div>

		<script>
		(function() {
			var installBtn = document.getElementById('exelearning-install-editor');
			var updateBtn = document.getElementById('exelearning-update-editor');
			var statusDiv = document.getElementById('exelearning-install-status');
			var returnInput = document.getElementById('exelearning-return-attachment');

			function escapeHtml(str) {
				var div = document.createElement('div');
				div.appendChild(document.createTextNode(str));
				return div.innerHTML;
			}

			function showError(btn, message) {
				statusDiv.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(message) + '</p></div>';
				btn.disabled = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Try Again', 'exelearning' ) ); ?>;
			}

			function doInstall(btn) {
				if (!btn) return;

				var nonce = btn.getAttribute('data-nonce');
				var ajaxUrl = btn.getAttribute('data-ajax-url');

				btn.disabled = true;
				btn.textContent = <?php echo wp_json_encode( __( 'Installing...', 'exelearning' ) ); ?>;
				statusDiv.style.display = 'block';
				statusDiv.innerHTML = '<p><span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' +
					<?php echo wp_json_encode( __( 'Downloading and installing the editor. This may take a minute...', 'exelearning' ) ); ?> + '</p>';

				var formData = new FormData();
				formData.append('action', <?php echo wp_json_encode( ExeLearning_Static_Editor_Installer::AJAX_ACTION ); ?>);
				formData.append('_nonce', nonce);

				fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
					.then(function(resp) { return resp.json(); })
					.then(function(data) {
						if (data.success) {
							statusDiv.innerHTML = '<div class="notice notice-success inline"><p>' +
								escapeHtml(data.data.message) + '</p></div>';
							if (returnInput && returnInput.value) {
								var editorUrl = <?php echo wp_json_encode( admin_url( 'admin.php?page=exelearning-editor' ) ); ?> +
									'&attachment_id=' + returnInput.value +
									'&_wpnonce=' + <?php echo wp_json_encode( wp_create_nonce( 'exelearning_editor' ) ); ?>;
								statusDiv.innerHTML += '<p><a href="' + editorUrl + '" class="button button-primary">' +
									<?php echo wp_json_encode( __( 'Open Editor', 'exelearning' ) ); ?> + '</a></p>';
							}
							setTimeout(function() { if (!returnInput || !returnInput.value) location.reload(); }, 2000);
						} else {
							showError(btn, data.data && data.data.message ? data.data.message : <?php echo wp_json_encode( __( 'Installation failed.', 'exelearning' ) ); ?>);
						}
					})
					.catch(function() {
						showError(btn, <?php echo wp_json_encode( __( 'Network error. Please check your connection and try again.', 'exelearning' ) ); ?>);
					});
			}

			if (installBtn) installBtn.addEventListener('click', function() { doInstall(installBtn); });
			if (updateBtn) updateBtn.addEventListener('click', function() { doInstall(updateBtn); });
		})();
		</script>
		<?php
	}
}
