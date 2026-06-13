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
		add_filter( 'plugin_action_links_' . plugin_basename( EXELEARNING_PLUGIN_FILE ), array( $this, 'add_action_links' ) );
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
			<?php $this->render_security_section(); ?>
			<?php $this->render_styles_section(); ?>
			<?php $this->render_help_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the iframe security mode section and persist its form submission.
	 *
	 * Lets the admin choose how embedded eXeLearning content is sandboxed:
	 *  - Secure (default): opaque-origin iframe; author HTML/JS cannot reach the
	 *    WordPress page (recommended).
	 *  - Legacy: same-origin iframe, for environments that need it (e.g. WordPress
	 *    Playground, whose service worker only serves same-origin documents).
	 */
	private function render_security_section() {
		if ( isset( $_POST['exelearning_iframe_mode_submit'] ) ) {
			check_admin_referer( 'exelearning_iframe_mode' );
			if ( current_user_can( 'manage_options' ) ) {
				$submitted = isset( $_POST['exelearning_iframe_sandbox_mode'] )
					? sanitize_key( wp_unslash( $_POST['exelearning_iframe_sandbox_mode'] ) )
					: '';
				$mode = ExeLearning_Iframe_Sandbox::MODE_LEGACY === $submitted
					? ExeLearning_Iframe_Sandbox::MODE_LEGACY
					: ExeLearning_Iframe_Sandbox::MODE_SECURE;
				update_option( ExeLearning_Iframe_Sandbox::OPTION, $mode );
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html__( 'Settings saved.', 'exelearning' ) . '</p></div>';
			}
		}

		$current = ExeLearning_Iframe_Sandbox::mode();
		?>
		<div class="card" id="exelearning-security-card" style="max-width: 900px; margin-bottom: 20px;">
			<h2><?php esc_html_e( 'Security', 'exelearning' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'exelearning_iframe_mode' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="exelearning_iframe_sandbox_mode"><?php esc_html_e( 'Iframe security mode', 'exelearning' ); ?></label>
						</th>
						<td>
							<select name="exelearning_iframe_sandbox_mode" id="exelearning_iframe_sandbox_mode">
								<option value="secure" <?php selected( $current, ExeLearning_Iframe_Sandbox::MODE_SECURE ); ?>>
									<?php esc_html_e( 'Secure (opaque-origin sandbox)', 'exelearning' ); ?>
								</option>
								<option value="legacy" <?php selected( $current, ExeLearning_Iframe_Sandbox::MODE_LEGACY ); ?>>
									<?php esc_html_e( 'Legacy (same-origin)', 'exelearning' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Secure (recommended) isolates embedded content in an opaque-origin iframe. Legacy keeps same-origin behavior, needed only in some environments such as WordPress Playground.', 'exelearning' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'exelearning' ), 'primary', 'exelearning_iframe_mode_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the help section.
	 *
	 * Summarizes how to embed eXeLearning content with the [exelearning]
	 * shortcode and points to the full reference on GitHub. Kept intentionally
	 * lightweight: the canonical documentation lives in docs/SHORTCODES.md and
	 * docs/HOOKS.md.
	 */
	private function render_help_section() {
		$shortcode_docs = 'https://github.com/exelearning/wp-exelearning/blob/main/docs/SHORTCODES.md';
		$hooks_docs     = 'https://github.com/exelearning/wp-exelearning/blob/main/docs/HOOKS.md';
		?>
		<div class="card" id="exelearning-help-card" style="max-width: 900px; margin-bottom: 20px;">
			<h2><?php esc_html_e( 'Help', 'exelearning' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Embed an uploaded .elpx package anywhere with the [exelearning] shortcode, referencing the file by its Media Library attachment ID.', 'exelearning' ); ?>
			</p>

			<h3><?php esc_html_e( 'Shortcode examples', 'exelearning' ); ?></h3>
			<p><code style="display: block; padding: 8px; background: #f0f0f1;">[exelearning id="123"]</code></p>
			<p><code style="display: block; padding: 8px; background: #f0f0f1;">[exelearning id="123" height="800" teacher_mode="1" screenshot="poster"]</code></p>

			<h3><?php esc_html_e( 'Attributes', 'exelearning' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Attribute', 'exelearning' ); ?></th>
						<th><?php esc_html_e( 'Default', 'exelearning' ); ?></th>
						<th><?php esc_html_e( 'Description', 'exelearning' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>id</code></td>
						<td><code>0</code></td>
						<td><?php esc_html_e( 'Required. Media Library attachment ID of the .elpx package.', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>height</code></td>
						<td><code>600</code></td>
						<td><?php esc_html_e( 'Height of the preview, in pixels.', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>teacher_mode</code></td>
						<td><code>0</code></td>
						<td><?php esc_html_e( 'When enabled, the content loads with teacher mode active.', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>teacher_mode_visible</code></td>
						<td><code>1</code></td>
						<td><?php esc_html_e( 'Whether the teacher-mode toggle button is shown.', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>show_download</code></td>
						<td><code>0</code></td>
						<td><?php esc_html_e( 'When enabled, shows a multi-format download button.', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>download_formats</code></td>
						<td><em><?php esc_html_e( 'all', 'exelearning' ); ?></em></td>
						<td><?php esc_html_e( 'Comma-separated formats to offer: elpx, html5, scorm12, ims, epub3.', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>screenshot</code></td>
						<td><code>no</code></td>
						<td><?php esc_html_e( 'Show the package screenshot: no, poster (click to load), or only (image only). Requires eXeLearning 4.0.1 or newer.', 'exelearning' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Developer hooks', 'exelearning' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'The shortcode output can be customized with the exelearning_shortcode_atts, exelearning_preview_url, and exelearning_shortcode_output filters, among other actions and filters.', 'exelearning' ); ?>
			</p>

			<p>
				<a href="<?php echo esc_url( $shortcode_docs ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Full shortcode reference', 'exelearning' ); ?>
				</a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( $hooks_docs ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Developer hooks reference', 'exelearning' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the style management section.
	 *
	 * The section lists built-in styles (enable/disable) and
	 * administrator-uploaded styles (enable/disable/delete), and exposes
	 * a ZIP upload form. All state changes go through AJAX endpoints
	 * handled by {@see ExeLearning_Admin_Styles}.
	 */
	private function render_styles_section() {
		$builtins      = ExeLearning_Styles_Service::list_builtin_themes();
		$uploads       = ExeLearning_Styles_Service::list_uploaded_styles();
		$registry      = ExeLearning_Styles_Service::get_registry();
		$disabled_list = $registry['disabled_builtins'];
		$nonce         = wp_create_nonce( ExeLearning_Admin_Styles::AJAX_NONCE );
		$ajax_url      = admin_url( 'admin-ajax.php' );
		$max_size      = ExeLearning_Styles_Service::get_max_zip_size();
		$block_import  = ExeLearning_Styles_Service::is_import_blocked();
		?>
		<div class="card" id="exelearning-styles-card" style="max-width: 900px; margin-bottom: 20px;">
			<h2><?php esc_html_e( 'Styles', 'exelearning' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload eXeLearning style packages and control which styles the embedded editor exposes.', 'exelearning' ); ?>
			</p>

			<h3><?php esc_html_e( 'Import policy', 'exelearning' ); ?></h3>
			<p>
				<label>
					<input type="checkbox" id="exelearning-styles-block-import" <?php checked( $block_import ); ?> />
					<strong><?php esc_html_e( 'Block user-imported styles', 'exelearning' ); ?></strong>
				</label>
			</p>
			<p class="description">
				<?php esc_html_e( 'When enabled, the embedded editor hides the "User styles" tab and silently refuses to install a style bundled inside an imported .elpx project. Users may only choose from the admin-approved list below. This mirrors the eXeLearning ONLINE_THEMES_INSTALL=false behavior.', 'exelearning' ); ?>
			</p>

			<h3><?php esc_html_e( 'Upload a new style', 'exelearning' ); ?></h3>
			<form id="exelearning-styles-upload" enctype="multipart/form-data">
				<p>
					<input type="file" name="style_zip" accept=".zip,application/zip,application/x-zip-compressed" required />
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Upload style', 'exelearning' ); ?>
					</button>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: human-readable max file size. */
						esc_html__( 'Maximum file size: %s. Only .zip packages containing a valid config.xml are accepted.', 'exelearning' ),
						esc_html( size_format( $max_size ) )
					);
					?>
				</p>
			</form>

			<div id="exelearning-styles-status" style="display: none; margin: 10px 0;"></div>

			<h3><?php esc_html_e( 'Uploaded styles', 'exelearning' ); ?></h3>
			<?php if ( empty( $uploads ) ) : ?>
				<p><em><?php esc_html_e( 'No uploaded styles yet.', 'exelearning' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" id="exelearning-styles-uploaded">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Id', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Version', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Installed', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'exelearning' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $uploads as $style ) : ?>
							<tr data-slug="<?php echo esc_attr( $style['id'] ); ?>">
								<td><?php echo esc_html( $style['title'] ); ?></td>
								<td><code><?php echo esc_html( $style['id'] ); ?></code></td>
								<td><?php echo esc_html( $style['version'] ); ?></td>
								<td><?php echo esc_html( $style['installed_at'] ); ?></td>
								<td>
									<label>
										<input type="checkbox"
											class="exelearning-styles-toggle-uploaded"
											<?php checked( ! empty( $style['enabled'] ) ); ?> />
										<?php esc_html_e( 'Enabled', 'exelearning' ); ?>
									</label>
								</td>
								<td>
									<button type="button" class="button-link-delete exelearning-styles-delete">
										<?php esc_html_e( 'Delete', 'exelearning' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Built-in styles', 'exelearning' ); ?></h3>
			<?php if ( empty( $builtins ) ) : ?>
				<p>
					<em><?php esc_html_e( 'Built-in styles are not available because the embedded editor is not installed.', 'exelearning' ); ?></em>
				</p>
			<?php else : ?>
				<table class="widefat striped" id="exelearning-styles-builtins">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Id', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Version', 'exelearning' ); ?></th>
							<th><?php esc_html_e( 'Enabled', 'exelearning' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $builtins as $style ) : ?>
							<?php $is_disabled = in_array( $style['id'], $disabled_list, true ); ?>
							<tr data-id="<?php echo esc_attr( $style['id'] ); ?>">
								<td><?php echo esc_html( $style['title'] ); ?></td>
								<td><code><?php echo esc_html( $style['id'] ); ?></code></td>
								<td><?php echo esc_html( $style['version'] ); ?></td>
								<td>
									<label>
										<input type="checkbox"
											class="exelearning-styles-toggle-builtin"
											<?php checked( ! $is_disabled ); ?> />
										<?php esc_html_e( 'Enabled', 'exelearning' ); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Disabled built-in styles are hidden from the editor. Uploaded styles can be disabled or deleted at any time. Existing projects that reference a missing style fall back to the editor default.', 'exelearning' ); ?>
			</p>
		</div>
		<?php
		$this->render_styles_section_script( $ajax_url, $nonce );
	}

	/**
	 * Output the inline JavaScript that wires the styles admin UI to its AJAX
	 * endpoints. Split out of {@see self::render_styles_section()} to keep that
	 * method within the length limit.
	 *
	 * @param string $ajax_url admin-ajax.php URL.
	 * @param string $nonce    AJAX nonce.
	 */
	private function render_styles_section_script( $ajax_url, $nonce ) {
		?>
		<script>
		(function () {
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var statusBox = document.getElementById('exelearning-styles-status');

			function setStatus(type, message) {
				if (!statusBox) return;
				var cls = type === 'success' ? 'notice-success' : 'notice-error';
				statusBox.style.display = 'block';
				statusBox.innerHTML = '<div class="notice ' + cls + ' inline"><p></p></div>';
				statusBox.querySelector('p').textContent = message;
			}

			function post(formData) {
				formData.append('_ajax_nonce', nonce);
				return fetch(ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				}).then(function (r) { return r.json(); });
			}

			var uploadForm = document.getElementById('exelearning-styles-upload');
			if (uploadForm) {
				uploadForm.addEventListener('submit', function (e) {
					e.preventDefault();
					var fd = new FormData(uploadForm);
					fd.append('action', 'exelearning_styles_upload');
					setStatus('info', <?php echo wp_json_encode( __( 'Uploading…', 'exelearning' ) ); ?>);
					post(fd).then(function (resp) {
						if (resp && resp.success) {
							setStatus('success', (resp.data && resp.data.message) || <?php echo wp_json_encode( __( 'Style installed.', 'exelearning' ) ); ?>);
							setTimeout(function () { location.reload(); }, 700);
						} else {
							setStatus('error', (resp && resp.data && resp.data.message) || <?php echo wp_json_encode( __( 'Upload failed.', 'exelearning' ) ); ?>);
						}
					}).catch(function () {
						setStatus('error', <?php echo wp_json_encode( __( 'Network error.', 'exelearning' ) ); ?>);
					});
				});
			}

			function bindToggle(cls, action, datasetKey) {
				var inputs = document.querySelectorAll('.' + cls);
				for (var i = 0; i < inputs.length; i++) {
					inputs[i].addEventListener('change', function (ev) {
						var cb  = ev.target;
						var row = cb.closest('tr');
						var fd  = new FormData();
						fd.append('action', action);
						fd.append(datasetKey, row.dataset[datasetKey === 'slug' ? 'slug' : 'id']);
						fd.append('enabled', cb.checked ? '1' : '');
						post(fd).then(function (resp) {
							if (!resp || !resp.success) {
								cb.checked = !cb.checked;
								setStatus('error', (resp && resp.data && resp.data.message) || <?php echo wp_json_encode( __( 'Update failed.', 'exelearning' ) ); ?>);
							}
						}).catch(function () {
							cb.checked = !cb.checked;
							setStatus('error', <?php echo wp_json_encode( __( 'Network error.', 'exelearning' ) ); ?>);
						});
					});
				}
			}
			bindToggle('exelearning-styles-toggle-uploaded', 'exelearning_styles_toggle_uploaded', 'slug');
			bindToggle('exelearning-styles-toggle-builtin', 'exelearning_styles_toggle_builtin', 'id');

			var blockImportCb = document.getElementById('exelearning-styles-block-import');
			if (blockImportCb) {
				blockImportCb.addEventListener('change', function () {
					var fd = new FormData();
					fd.append('action', 'exelearning_styles_toggle_block_import');
					fd.append('enabled', blockImportCb.checked ? '1' : '');
					post(fd).then(function (resp) {
						if (!resp || !resp.success) {
							blockImportCb.checked = !blockImportCb.checked;
							setStatus('error', <?php echo wp_json_encode( __( 'Update failed.', 'exelearning' ) ); ?>);
						}
					}).catch(function () {
						blockImportCb.checked = !blockImportCb.checked;
						setStatus('error', <?php echo wp_json_encode( __( 'Network error.', 'exelearning' ) ); ?>);
					});
				});
			}

			var deletes = document.querySelectorAll('.exelearning-styles-delete');
			for (var i = 0; i < deletes.length; i++) {
				deletes[i].addEventListener('click', function (ev) {
					var btn = ev.target;
					var row = btn.closest('tr');
					var slug = row.dataset.slug;
					if (!window.confirm(<?php echo wp_json_encode( __( 'Delete this style? This cannot be undone.', 'exelearning' ) ); ?>)) {
						return;
					}
					var fd = new FormData();
					fd.append('action', 'exelearning_styles_delete');
					fd.append('slug', slug);
					post(fd).then(function (resp) {
						if (resp && resp.success) {
							row.parentNode.removeChild(row);
							setStatus('success', <?php echo wp_json_encode( __( 'Style deleted.', 'exelearning' ) ); ?>);
						} else {
							setStatus('error', (resp && resp.data && resp.data.message) || <?php echo wp_json_encode( __( 'Delete failed.', 'exelearning' ) ); ?>);
						}
					}).catch(function () {
						setStatus('error', <?php echo wp_json_encode( __( 'Network error.', 'exelearning' ) ); ?>);
					});
				});
			}
		})();
		</script>
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
