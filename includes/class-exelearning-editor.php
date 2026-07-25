<?php
/**
 * Editor integration class for eXeLearning.
 *
 * Handles the fullscreen editor modal for editing .elp files.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Editor.
 *
 * Manages the eXeLearning editor integration in WordPress.
 */
class ExeLearning_Editor {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_editor_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_scripts' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_scripts_for_blocks' ) );
		add_action( 'admin_footer', array( $this, 'render_editor_modal_container' ) );
		add_action( 'admin_notices', array( $this, 'maybe_warn_preview_permalinks' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'add_edit_capability' ), 10, 3 );

		// Start output buffering early if we're on the editor page.
		// This must happen before any output to capture deprecation warnings.
		$this->maybe_start_buffer();
	}

	/**
	 * Start output buffering if we're on the editor page.
	 */
	private function maybe_start_buffer() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || 'exelearning-editor' !== $_GET['page'] ) {
			return;
		}

		// Suppress error display for this request to prevent output corruption.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.display_errors_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Required to prevent output corruption in standalone editor page.
		@ini_set( 'display_errors', '0' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
		@error_reporting( 0 );

		// Start output buffering to capture any warnings/notices.
		ob_start();

		// Register to render the page and discard buffered output (very early priority).
		add_action( 'admin_init', array( $this, 'render_editor_page_and_exit' ), -999 );
	}

	/**
	 * Render the editor page and exit, discarding any buffered output.
	 */
	public function render_editor_page_and_exit() {
		// Suppress error display for this request to prevent output corruption.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.display_errors_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged -- Required to prevent output corruption in standalone editor page.
		@ini_set( 'display_errors', '0' );

		// Discard any buffered output (warnings, notices, etc.).
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$this->render_editor_page();
	}

	/**
	 * Register a hidden admin page for the editor.
	 */
	public function register_editor_page() {
		// Use empty string instead of null to avoid PHP 8.x deprecation warnings.
		// Empty string creates a hidden page the same way null does.
		add_submenu_page(
			'', // No parent - hidden page (empty string instead of null for PHP 8.x compatibility).
			__( 'eXeLearning Editor', 'exelearning' ),
			__( 'eXeLearning Editor', 'exelearning' ),
			'upload_files',
			'exelearning-editor',
			array( $this, 'editor_page_placeholder' )
		);
	}

	/**
	 * Placeholder callback for the hidden editor page.
	 *
	 * The actual rendering is handled by render_editor_page_and_exit() in admin_init.
	 * This is just a placeholder to avoid WordPress internal issues with string callbacks.
	 */
	public function editor_page_placeholder() {
		// This should never be called because render_editor_page_and_exit() exits early.
		// But if it is called, output nothing.
	}

	/**
	 * Render the editor page (serves the bootstrap HTML).
	 */
	public function render_editor_page() {
		// Clean any output that may have been generated (e.g., deprecation warnings).
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'exelearning_editor' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'exelearning' ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'exelearning' ) );
		}

		// Get attachment ID.
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'No attachment specified.', 'exelearning' ) );
		}

		// Verify it's an ELP file.
		$file = get_attached_file( $attachment_id );
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( 'elpx' !== $ext ) {
			wp_die( esc_html__( 'This file is not an eXeLearning file (.elpx).', 'exelearning' ) );
		}

		// Check user can edit this attachment.
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this file.', 'exelearning' ) );
		}

		// Load the editor bootstrap page.
		include EXELEARNING_PLUGIN_DIR . 'admin/views/editor-bootstrap.php';
		exit;
	}

	/**
	 * Enqueue editor modal scripts on relevant admin pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_editor_scripts( $hook ) {
		// Only load on media pages, post edit screens, and site editor.
		$allowed_hooks = array( 'upload.php', 'post.php', 'post-new.php', 'media.php', 'site-editor.php' );

		if ( ! in_array( $hook, $allowed_hooks, true ) && ! did_action( 'wp_enqueue_media' ) ) {
			return;
		}

		wp_enqueue_script(
			'exelearning-editor',
			EXELEARNING_PLUGIN_URL . 'assets/js/exelearning-editor.js',
			array( 'jquery' ),
			EXELEARNING_VERSION,
			true
		);

		wp_localize_script(
			'exelearning-editor',
			'exelearningEditorVars',
			array(
				'editorPageUrl' => admin_url( 'admin.php?page=exelearning-editor' ),
				'restUrl'       => rest_url( 'exelearning/v1' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'editorNonce'   => wp_create_nonce( 'exelearning_editor' ),
				'i18n'          => array(
					'editInExelearning' => __( 'Edit in eXeLearning', 'exelearning' ),
					'close'             => __( 'Close', 'exelearning' ),
					'saveToWordPress'   => __( 'Save to WordPress', 'exelearning' ),
					'saving'            => __( 'Saving...', 'exelearning' ),
					'pleaseWait'        => __( 'Please wait while the file is being saved.', 'exelearning' ),
					'unsavedChanges'    => __( 'You have unsaved changes. Are you sure you want to close?', 'exelearning' ),
				),
			)
		);
	}

	/**
	 * Enqueue editor scripts specifically for the block editor.
	 * This ensures the ExeLearningEditor is available when the block is loaded.
	 */
	public function enqueue_editor_scripts_for_blocks() {
		wp_enqueue_script(
			'exelearning-editor',
			EXELEARNING_PLUGIN_URL . 'assets/js/exelearning-editor.js',
			array( 'jquery' ),
			EXELEARNING_VERSION,
			true
		);

		wp_localize_script(
			'exelearning-editor',
			'exelearningEditorVars',
			array(
				'editorPageUrl' => admin_url( 'admin.php?page=exelearning-editor' ),
				'restUrl'       => rest_url( 'exelearning/v1' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'editorNonce'   => wp_create_nonce( 'exelearning_editor' ),
				'i18n'          => array(
					'editInExelearning' => __( 'Edit in eXeLearning', 'exelearning' ),
					'close'             => __( 'Close', 'exelearning' ),
					'saveToWordPress'   => __( 'Save to WordPress', 'exelearning' ),
					'saving'            => __( 'Saving...', 'exelearning' ),
					'pleaseWait'        => __( 'Please wait while the file is being saved.', 'exelearning' ),
					'unsavedChanges'    => __( 'You have unsaved changes. Are you sure you want to close?', 'exelearning' ),
				),
			)
		);
	}

	/**
	 * Render the editor modal container in admin footer.
	 */
	public function render_editor_modal_container() {
		$screen = get_current_screen();

		// Only on relevant screens (media library, post editor, site editor).
		$allowed_screens = array( 'upload', 'post', 'attachment', 'site-editor' );
		if ( ! $screen || ! in_array( $screen->base, $allowed_screens, true ) ) {
			return;
		}
		?>
		<div id="exelearning-editor-modal" class="exelearning-editor-modal" style="display: none;">
			<div class="exelearning-editor-header">
				<div class="exelearning-editor-title">
					<span class="dashicons dashicons-edit"></span>
					<span><?php esc_html_e( 'Edit eXeLearning File', 'exelearning' ); ?></span>
				</div>
				<div class="exelearning-editor-actions">
					<button type="button" id="exelearning-editor-save" class="button button-primary" disabled>
						<span class="dashicons dashicons-wordpress"></span>
						<?php esc_html_e( 'Save to WordPress', 'exelearning' ); ?>
					</button>
					<button type="button" id="exelearning-editor-close" class="button">
						<?php esc_html_e( 'Close', 'exelearning' ); ?>
					</button>
				</div>
			</div>
			<iframe id="exelearning-editor-iframe" class="exelearning-editor-iframe" src="about:blank"></iframe>
		</div>
		<?php
	}

	/**
	 * Add edit capability information to attachment JS data.
	 *
	 * @param array   $response   Attachment data for JS.
	 * @param WP_Post $attachment Attachment post object.
	 * @param array   $meta       Attachment metadata (unused, required by WordPress filter API).
	 * @return array Modified response.
	 */
	public function add_edit_capability( $response, $attachment, $meta ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter API.
		// Skip if attachment is not valid.
		if ( ! $attachment || ! isset( $attachment->ID ) || empty( $attachment->ID ) ) {
			return $response;
		}

		// Check if this is an ELP file.
		$file = get_attached_file( $attachment->ID );

		// Skip if file path is not available.
		if ( ! $file || ! is_string( $file ) ) {
			return $response;
		}

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( 'elpx' !== $ext ) {
			return $response;
		}

		// Add edit capability flag.
		if ( current_user_can( 'edit_post', $attachment->ID ) ) {
			$response['exelearningCanEdit'] = true;
			$response['exelearningEditUrl'] = $this->get_editor_url( $attachment->ID );
		}

		return $response;
	}

	/**
	 * Generate editor URL for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Editor URL.
	 */
	public function get_editor_url( $attachment_id ) {
		return add_query_arg(
			array(
				'page'          => 'exelearning-editor',
				'attachment_id' => $attachment_id,
				'_wpnonce'      => wp_create_nonce( 'exelearning_editor' ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Generate project ID for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Project ID.
	 */
	public function get_project_id( $attachment_id ) {
		return 'wp-attachment-' . $attachment_id;
	}

	/**
	 * Whether WordPress runs with pretty permalinks.
	 *
	 * The HTTP preview serving base is appended with `/{previewId}/{path}`; under
	 * plain permalinks `rest_url()` yields the `?rest_route=` form, which cannot
	 * carry that suffix, so the transport requires pretty permalinks.
	 *
	 * @return bool
	 */
	public static function pretty_permalinks_enabled() {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Purge caches left by an earlier editor build.
	 *
	 * A stale Service Worker from a previous build can serve outdated editor
	 * assets and drive the preview into a refresh loop. This purges the caches
	 * on load; it does NOT block registration, because the current editor build
	 * needs its own preview worker (see the inline comment for why that is safe
	 * and why the opaque snapshot never travels over it).
	 *
	 * Returned WITHOUT `<script>` tags so callers can wrap and place it first.
	 *
	 * @return string Inline JS (an IIFE).
	 */
	public static function purge_stale_editor_caches_script() {
		return <<<'JS'
(function () {
    // One-time hygiene: purge any Service Worker + caches left by an EARLIER
    // editor build (a legacy preview-sw.js / service-worker.js on this origin).
    // A stale worker can serve outdated editor assets and drive the preview into
    // a refresh loop (flicker). We do NOT block new registrations: the current
    // trust-boundary editor build registers its own preview worker, which serves
    // ONLY DOMPurify-sanitized ("filtered") preview content same-origin — safe by
    // construction (no author JS), and REQUIRED so whitelisted external videos
    // (YouTube/Vimeo/Dailymotion) get a real same-origin referrer and play inline
    // (a blob: transport is rejected by YouTube with Error 153). The opaque,
    // UNFILTERED author snapshot NEVER travels over this worker: it is served by
    // the plugin's REST snapshot route (`/wp-json/exelearning/v1/preview…`) under
    // a sandbox CSP, outside the worker's `/dist/static/…` scope.
    try {
        if (window.caches && caches.keys) {
            caches.keys().then(function (keys) {
                keys.forEach(function (k) { caches.delete(k); });
            }).catch(function () {});
        }
    } catch (e) { void e; }
})();
JS;
	}

	/**
	 * Warn on the editor's host screens when plain permalinks disable the live
	 * preview, so the admin can fix it before opening a project.
	 *
	 * @return void
	 */
	public function maybe_warn_preview_permalinks() {
		if ( ! current_user_can( 'upload_files' ) || self::pretty_permalinks_enabled() ) {
			return;
		}

		$screen = get_current_screen();
		// Screens carrying the "Edit in eXeLearning" affordance, plus the plugin
		// settings page — the places an admin acts on this warning.
		$allowed = array( 'upload', 'post', 'attachment', 'site-editor', 'settings_page_exelearning-settings' );
		if ( ! $screen || ! in_array( $screen->base, $allowed, true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'eXeLearning live preview requires pretty permalinks. Set a permalink structure other than Plain to enable the in-editor preview.', 'exelearning' ),
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Change Permalink Settings', 'exelearning' )
		);
	}
}
