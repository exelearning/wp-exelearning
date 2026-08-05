<?php
/**
 * Admin settings page.
 *
 * Registers the plugin options through the WordPress Settings API and renders
 * the settings screen following core admin conventions. The screen is built
 * from three independent forms (HTML forbids nesting them):
 *
 * 1. The settings form posts to `options.php` and saves every registered
 *    option (import policy, asset proxy, enabled/disabled styles) with a
 *    single Save Changes button.
 * 2. The style upload form posts to `admin-post.php` and is handled by
 *    {@see ExeLearning_Admin_Styles::handle_upload()}.
 * 3. Per-row style deletion uses nonced `admin-post.php` links handled by
 *    {@see ExeLearning_Admin_Styles::handle_delete()}.
 *
 * The embedded-editor card is informational only: the editor ships inside the
 * plugin package and cannot be installed or updated at runtime (ADR-72-01).
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
	 * Settings API option group shared by every registered option.
	 */
	const OPTION_GROUP = 'exelearning_settings';

	/**
	 * Settings page slug (also used as the Settings API page identifier).
	 */
	const PAGE_SLUG = 'exelearning-settings';

	/**
	 * Hook suffix returned by add_options_page(), used to scope assets and
	 * the contextual help tab to this screen only.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'exelearning' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Adds the admin menu item and hooks the screen-load callback.
	 */
	public function add_admin_menu() {
		$this->hook_suffix = add_options_page(
			esc_html__( 'eXeLearning Settings', 'exelearning' ),
			esc_html__( 'eXeLearning', 'exelearning' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'display_settings_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'on_settings_page_load' ) );
		}
	}

	/**
	 * Screen-load callback: contextual help tab and contextual notices.
	 *
	 * Runs on `load-{$hook_suffix}` so it only ever affects this screen.
	 */
	public function on_settings_page_load() {
		$screen = get_current_screen();
		if ( $screen ) {
			$screen->add_help_tab(
				array(
					'id'      => 'exelearning-overview',
					'title'   => __( 'Overview', 'exelearning' ),
					'content' => $this->get_help_tab_content(),
				)
			);
			$screen->set_help_sidebar( $this->get_help_sidebar_content() );
		}

		// Surface the "editor missing" redirect (see editor-bootstrap.php) as a
		// regular settings notice so it renders through settings_errors().
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check.
		if ( isset( $_GET['editor-missing'] ) && '1' === $_GET['editor-missing'] && ! ExeLearning_Editor_Bundle::is_available() ) {
			add_settings_error(
				'exelearning_editor',
				'exelearning_editor_missing',
				__( 'The embedded editor is required to edit eXeLearning files.', 'exelearning' ) . ' ' . __( 'This installation does not include it: official release packages ship the editor, so please reinstall the plugin from a release package (or build it with "make build-editor" in a development checkout).', 'exelearning' ),
				'warning'
			);
		}
	}

	/**
	 * Build the contextual Help tab content.
	 *
	 * @return string HTML.
	 */
	private function get_help_tab_content() {
		return '<p>' . esc_html__( 'Embed an uploaded .elpx package anywhere with the [exelearning] shortcode, referencing the file by its Media Library attachment ID.', 'exelearning' ) . '</p>'
			. '<p><code>[exelearning id="123"]</code></p>'
			. '<p>' . esc_html__( 'The full attribute reference is available at the bottom of this page.', 'exelearning' ) . '</p>';
	}

	/**
	 * Build the contextual Help sidebar content.
	 *
	 * @return string HTML.
	 */
	private function get_help_sidebar_content() {
		return '<p><strong>' . esc_html__( 'For more information:', 'exelearning' ) . '</strong></p>'
			. '<p><a href="https://github.com/exelearning/wp-exelearning/blob/main/docs/SHORTCODES.md" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Full shortcode reference', 'exelearning' ) . '</a></p>'
			. '<p><a href="https://github.com/exelearning/wp-exelearning/blob/main/docs/HOOKS.md" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Developer hooks reference', 'exelearning' ) . '</a></p>';
	}

	/**
	 * Enqueue the stylesheet and script for the settings screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'exelearning-settings',
			EXELEARNING_PLUGIN_URL . 'assets/css/exelearning-settings.css',
			array(),
			EXELEARNING_VERSION
		);
		wp_enqueue_script(
			'exelearning-settings',
			EXELEARNING_PLUGIN_URL . 'assets/js/exelearning-settings.js',
			array(),
			EXELEARNING_VERSION,
			true
		);
	}

	/**
	 * Register options, sections and fields with the Settings API.
	 *
	 * Every option carries a real sanitize callback; options.php refuses to
	 * persist anything that is not registered here.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => 0,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => 0,
			)
		);
		register_setting(
			self::OPTION_GROUP,
			ExeLearning_Styles_Service::OPTION_DISABLED_STYLES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_disabled_styles' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'exelearning_section_styles',
			__( 'Styles', 'exelearning' ),
			array( $this, 'render_styles_section_intro' ),
			self::PAGE_SLUG
		);
		add_settings_field(
			'exelearning_styles_block_import',
			__( 'Import policy', 'exelearning' ),
			array( $this, 'render_block_import_field' ),
			self::PAGE_SLUG,
			'exelearning_section_styles',
			array( 'label_for' => 'exelearning-styles-block-import' )
		);

		add_settings_section(
			'exelearning_section_uploaded_styles',
			__( 'Uploaded styles', 'exelearning' ),
			array( $this, 'render_uploaded_styles_table' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'exelearning_section_builtin_styles',
			__( 'Built-in styles', 'exelearning' ),
			array( $this, 'render_builtin_styles_table' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'exelearning_section_content_delivery',
			__( 'Content delivery', 'exelearning' ),
			null,
			self::PAGE_SLUG
		);
		add_settings_field(
			'exelearning_proxy_assets',
			__( 'Asset delivery', 'exelearning' ),
			array( $this, 'render_proxy_assets_field' ),
			self::PAGE_SLUG,
			'exelearning_section_content_delivery',
			array( 'label_for' => 'exelearning-proxy-assets' )
		);
	}

	/**
	 * Sanitize a checkbox option to a strict 1/0 integer.
	 *
	 * Nothing is submitted for unchecked boxes through options.php, so
	 * absent values must come out as 0.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return int 1 when checked, 0 otherwise.
	 */
	public function sanitize_checkbox( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Sanitize the disabled-styles option into a flat list of style ids.
	 *
	 * Accepts the two shapes that can reach it:
	 * - Form submissions: an id-keyed map where a hidden input posts "1"
	 *   (disabled) unless the matching "Enabled" checkbox overrides it
	 *   with "0" (enabled).
	 * - Programmatic writes and the DB migration: a flat list of ids.
	 *
	 * Unknown or stale ids are kept: they are harmless (ignored at render
	 * time) and dropping them here would silently re-enable styles whose
	 * package is temporarily unavailable (e.g. editor not installed).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string[] Sorted, unique list of disabled style ids.
	 */
	public function sanitize_disabled_styles( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$disabled = array();
		foreach ( $value as $key => $item ) {
			if ( is_int( $key ) ) {
				$id       = sanitize_title( (string) $item );
				$excluded = true;
			} else {
				$id = sanitize_title( (string) $key );
				// '1' (hidden input) means disabled; '0' (checkbox) means enabled.
				$excluded = ! empty( $item );
			}
			if ( '' !== $id && $excluded && ! in_array( $id, $disabled, true ) ) {
				$disabled[] = $id;
			}
		}
		sort( $disabled );
		return $disabled;
	}

	/**
	 * Displays the settings page.
	 *
	 * Note: settings_errors() is not called here on purpose. Screens under
	 * Settings (options-general.php) already get it via core's
	 * options-head.php, and a second call would print every notice twice.
	 */
	public function display_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'eXeLearning Settings', 'exelearning' ); ?></h1>

			<?php $this->render_editor_status_section(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<?php $this->render_upload_form(); ?>
			<?php $this->render_help_reference(); ?>
		</div>
		<?php
	}

	/**
	 * Section intro for the Styles section.
	 */
	public function render_styles_section_intro() {
		echo '<p class="description">' . esc_html__( 'Upload eXeLearning style packages and control which styles the embedded editor exposes.', 'exelearning' ) . '</p>';
	}

	/**
	 * Field callback: "Block user-imported styles" checkbox.
	 */
	public function render_block_import_field() {
		$block_import = ExeLearning_Styles_Service::is_import_blocked();
		?>
		<label for="exelearning-styles-block-import">
			<input type="checkbox"
				id="exelearning-styles-block-import"
				name="<?php echo esc_attr( ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT ); ?>"
				value="1"
				<?php checked( $block_import ); ?> />
			<?php esc_html_e( 'Block user-imported styles', 'exelearning' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, the embedded editor hides the "User styles" tab and silently refuses to install a style bundled inside an imported .elpx project. Users may only choose from the admin-approved list below. This mirrors the eXeLearning ONLINE_THEMES_INSTALL=false behavior.', 'exelearning' ); ?>
		</p>
		<?php
	}

	/**
	 * Field callback: "Serve package assets through the WordPress proxy" checkbox.
	 */
	public function render_proxy_assets_field() {
		$proxy_assets = (bool) get_option( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS, false );
		?>
		<label for="exelearning-proxy-assets">
			<input type="checkbox"
				id="exelearning-proxy-assets"
				name="<?php echo esc_attr( ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS ); ?>"
				value="1"
				<?php checked( $proxy_assets ); ?> />
			<?php esc_html_e( 'Serve package assets through the WordPress proxy', 'exelearning' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Use this option only if your web server returns incorrect MIME types for package assets, for example JavaScript files served as text/plain. When enabled, CSS, JavaScript, fonts, images and other package files are served through WordPress so the plugin can send explicit Content-Type headers. This can reduce performance because requests are handled by PHP instead of being served directly by the web server.', 'exelearning' ); ?>
		</p>
		<?php
	}

	/**
	 * Section callback: table of administrator-uploaded styles.
	 *
	 * The Enabled checkboxes are part of the main settings form and persist
	 * through the single Save Changes button; only the Delete row action posts
	 * elsewhere (a nonced admin-post link, because forms cannot nest).
	 */
	public function render_uploaded_styles_table() {
		$uploads = ExeLearning_Styles_Service::list_uploaded_styles();
		if ( empty( $uploads ) ) {
			echo '<p><em>' . esc_html__( 'No uploaded styles yet.', 'exelearning' ) . '</em></p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped" id="exelearning-styles-uploaded">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-title column-primary"><?php esc_html_e( 'Title', 'exelearning' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Id', 'exelearning' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Version', 'exelearning' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Installed', 'exelearning' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Enabled', 'exelearning' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $uploads as $style ) : ?>
					<?php $this->render_style_row( $style, false ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Section callback: table of built-in editor styles.
	 */
	public function render_builtin_styles_table() {
		$builtins = ExeLearning_Styles_Service::list_builtin_themes();
		if ( empty( $builtins ) ) {
			echo '<p><em>' . esc_html__( 'Built-in styles are not available because the embedded editor is not installed.', 'exelearning' ) . '</em></p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped" id="exelearning-styles-builtins">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-title column-primary"><?php esc_html_e( 'Title', 'exelearning' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Id', 'exelearning' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Version', 'exelearning' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Enabled', 'exelearning' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $builtins as $style ) : ?>
					<?php $this->render_style_row( $style, true ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Disabled built-in styles are hidden from the editor. Uploaded styles can be disabled or deleted at any time. Existing projects that reference a missing style fall back to the editor default.', 'exelearning' ); ?>
		</p>
		<?php
	}

	/**
	 * Render one style row shared by both tables.
	 *
	 * @param array $style      Style data (id, title, version, installed_at).
	 * @param bool  $is_builtin Whether the row belongs to the built-in table.
	 */
	private function render_style_row( $style, $is_builtin ) {
		$style_id   = isset( $style['id'] ) ? (string) $style['id'] : (string) ( $style['name'] ?? '' );
		$title      = isset( $style['title'] ) ? (string) $style['title'] : $style_id;
		$version    = isset( $style['version'] ) ? (string) $style['version'] : '';
		$enabled    = ! ExeLearning_Styles_Service::is_style_disabled( $style_id );
		$field_name = ExeLearning_Styles_Service::OPTION_DISABLED_STYLES . '[' . $style_id . ']';
		$input_id   = 'exelearning-style-enabled-' . $style_id;
		/* translators: %s: style title. */
		$aria_label = sprintf( __( 'Enable the %s style', 'exelearning' ), $title );
		?>
		<tr>
			<td class="title column-title has-row-actions column-primary" data-colname="<?php esc_attr_e( 'Title', 'exelearning' ); ?>">
				<strong><?php echo esc_html( $title ); ?></strong>
				<?php if ( ! $is_builtin ) : ?>
					<div class="row-actions">
						<span class="delete">
							<a href="<?php echo esc_url( $this->get_delete_style_url( $style_id ) ); ?>"
								class="submitdelete exelearning-delete-style"
								data-confirm="<?php esc_attr_e( 'Delete this style? This cannot be undone.', 'exelearning' ); ?>">
								<?php esc_html_e( 'Delete', 'exelearning' ); ?>
							</a>
						</span>
					</div>
				<?php endif; ?>
				<button type="button" class="toggle-row">
					<span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'exelearning' ); ?></span>
				</button>
			</td>
			<td data-colname="<?php esc_attr_e( 'Id', 'exelearning' ); ?>"><code><?php echo esc_html( $style_id ); ?></code></td>
			<td data-colname="<?php esc_attr_e( 'Version', 'exelearning' ); ?>"><?php echo esc_html( $version ); ?></td>
			<?php if ( ! $is_builtin ) : ?>
				<td data-colname="<?php esc_attr_e( 'Installed', 'exelearning' ); ?>"><?php echo esc_html( (string) ( $style['installed_at'] ?? '' ) ); ?></td>
			<?php endif; ?>
			<td data-colname="<?php esc_attr_e( 'Enabled', 'exelearning' ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="1" />
				<input type="checkbox"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $field_name ); ?>"
					value="0"
					aria-label="<?php echo esc_attr( $aria_label ); ?>"
					<?php checked( $enabled ); ?> />
			</td>
		</tr>
		<?php
	}

	/**
	 * Build the nonced admin-post URL that deletes an uploaded style.
	 *
	 * @param string $style_id Uploaded style slug.
	 * @return string URL.
	 */
	private function get_delete_style_url( $style_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => ExeLearning_Admin_Styles::ACTION_DELETE,
					'slug'   => rawurlencode( $style_id ),
				),
				admin_url( 'admin-post.php' )
			),
			ExeLearning_Admin_Styles::ACTION_DELETE . '_' . $style_id
		);
	}

	/**
	 * Render the style upload form.
	 *
	 * A separate form because it needs enctype="multipart/form-data" and
	 * cannot live inside the options.php form.
	 */
	private function render_upload_form() {
		$max_size = ExeLearning_Styles_Service::get_max_zip_size();
		?>
		<h2><?php esc_html_e( 'Upload a new style', 'exelearning' ); ?></h2>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( ExeLearning_Admin_Styles::ACTION_UPLOAD ); ?>" />
			<?php wp_nonce_field( ExeLearning_Admin_Styles::ACTION_UPLOAD ); ?>
			<p>
				<label class="screen-reader-text" for="exelearning-style-zip"><?php esc_html_e( 'Upload a new style', 'exelearning' ); ?></label>
				<input type="file" id="exelearning-style-zip" name="style_zip" accept=".zip,application/zip,application/x-zip-compressed" required />
				<?php
				// A distinct button name: the default "submit" would collide
				// with the settings form's Save button id on the same page.
				submit_button( __( 'Upload style', 'exelearning' ), 'secondary', 'exelearning-upload-style', false );
				?>
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
		<?php
	}

	/**
	 * Warn when the bundled editor is missing.
	 *
	 * The editor ships inside the plugin package (ADR-72-01), so in a normal
	 * installation there is nothing to show or do here; the card only appears
	 * when the bundle is absent (e.g. a development checkout that has not run
	 * `make build-editor`).
	 */
	private function render_editor_status_section() {
		if ( ExeLearning_Editor_Bundle::is_available() ) {
			return;
		}
		?>
		<div class="card exelearning-editor-status">
			<h2><?php esc_html_e( 'Embedded Editor', 'exelearning' ); ?></h2>
			<p>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'This installation does not include the embedded editor, so editing eXeLearning content is disabled. Official release packages include it; development checkouts must build it with "make build-editor".', 'exelearning' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the collapsible shortcode reference at the bottom of the page.
	 *
	 * The same overview lives in the screen's Help tab (the canonical
	 * WordPress location); it is duplicated here, collapsed by default,
	 * because Help tab discoverability is poor. Native <details> keeps the
	 * block keyboard accessible without any custom JavaScript.
	 */
	private function render_help_reference() {
		?>
		<details class="exelearning-help" id="exelearning-help">
			<summary><?php esc_html_e( 'Shortcode reference and examples', 'exelearning' ); ?></summary>

			<p class="description">
				<?php esc_html_e( 'Embed an uploaded .elpx package anywhere with the [exelearning] shortcode, referencing the file by its Media Library attachment ID.', 'exelearning' ); ?>
			</p>

			<h3><?php esc_html_e( 'Shortcode examples', 'exelearning' ); ?></h3>
			<p><code class="exelearning-shortcode-example">[exelearning id="123"]</code></p>
			<p><code class="exelearning-shortcode-example">[exelearning id="123" height="800" teacher_mode="1" screenshot="poster"]</code></p>
			<p><code class="exelearning-shortcode-example">[exelearning id="123" width="100%" height="75%" fullscreen="1"]</code></p>

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
						<td><code>width</code></td>
						<td><code>100%</code></td>
						<td><?php esc_html_e( 'Width of the preview: pixels (600, 600px) or percentages (75%, 100%).', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>height</code></td>
						<td><code>600</code></td>
						<td><?php esc_html_e( 'Height of the preview: pixels (600, 600px) or percentages (75%, 100%).', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>teacher_mode</code></td>
						<td><code>0</code></td>
						<td><?php esc_html_e( 'When enabled, the teacher layer selector is offered so viewers can reveal teacher content.', 'exelearning' ); ?></td>
					</tr>
					<tr>
						<td><code>teacher_mode_visible</code></td>
						<td><code>1</code></td>
						<td><?php esc_html_e( 'If disabled, the teacher layer selector is hidden in the embedded eXeLearning content.', 'exelearning' ); ?></td>
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
					<tr>
						<td><code>fullscreen</code></td>
						<td><code>0</code></td>
						<td><?php esc_html_e( 'Show the fullscreen button in the toolbar. Off by default; set to 1 to show it.', 'exelearning' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Developer hooks', 'exelearning' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'The shortcode output can be customized with the exelearning_shortcode_atts, exelearning_preview_url, and exelearning_shortcode_output filters, among other actions and filters.', 'exelearning' ); ?>
			</p>

			<p>
				<a href="https://github.com/exelearning/wp-exelearning/blob/main/docs/SHORTCODES.md" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Full shortcode reference', 'exelearning' ); ?>
				</a>
				&nbsp;|&nbsp;
				<a href="https://github.com/exelearning/wp-exelearning/blob/main/docs/HOOKS.md" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Developer hooks reference', 'exelearning' ); ?>
				</a>
			</p>
		</details>
		<?php
	}
}
