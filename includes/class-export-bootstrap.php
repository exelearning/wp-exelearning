<?php
/**
 * Public export-only bootstrap for the embedded eXeLearning editor.
 *
 * Hooks `template_redirect` so that any visitor (logged-in or not) can load
 * a stripped-down version of the static editor inside a hidden iframe. The
 * page exposes only the protocol needed to invoke `SharedExporters.quickExport()`
 * for the requested format and post the resulting bytes back to the opener
 * window via `postMessage`. There is no save endpoint and no user data.
 *
 * Front-end script `wp-exe-download.js` is the only intended caller.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Export_Bootstrap.
 */
class ExeLearning_Export_Bootstrap {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	/**
	 * Register the public-facing query var and rewrite rule.
	 */
	public function register_endpoint() {
		add_rewrite_rule(
			'^exelearning/export/?$',
			'index.php?exe_export=1',
			'top'
		);
		add_rewrite_tag( '%exe_export%', '1' );
	}

	/**
	 * Build the URL that loads the export bootstrap for a given attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function url_for( $attachment_id ) {
		return add_query_arg(
			array(
				'exe_export'    => '1',
				'attachment_id' => absint( $attachment_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Render the bootstrap page if the request matches.
	 */
	public function maybe_render() {
		if ( ! $this->request_matches() ) {
			return;
		}

		$attachment_id = $this->resolve_attachment_id();
		$this->assert_valid_attachment( $attachment_id );

		$elp_url         = wp_get_attachment_url( $attachment_id );
		$editor_base_url = EXELEARNING_PLUGIN_URL . 'dist/static';
		$template        = $this->load_editor_template();

		$template = $this->inject_bootstrap_payload( $template, $attachment_id, $elp_url, $editor_base_url );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bootstrap HTML composed from trusted plugin template + sanitized inputs.
		echo $template;
		exit;
	}

	/**
	 * Whether the current request targets the export endpoint.
	 *
	 * @return bool
	 */
	private function request_matches() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public endpoint, no state mutation.
		if ( ! empty( $_GET['exe_export'] ) ) {
			return true;
		}
		return ! empty( get_query_var( 'exe_export' ) );
	}

	/**
	 * Resolve and validate the requested attachment id, dying on missing input.
	 *
	 * @return int
	 */
	private function resolve_attachment_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'No attachment specified.', 'exelearning' ), '', array( 'response' => 400 ) );
		}
		return $attachment_id;
	}

	/**
	 * Ensure the attachment exists and is an eXeLearning source file.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function assert_valid_attachment( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			wp_die( esc_html__( 'Attachment not found.', 'exelearning' ), '', array( 'response' => 404 ) );
		}

		$file = get_attached_file( $attachment_id );
		$ext  = $file ? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
		if ( 'elpx' !== $ext ) {
			wp_die( esc_html__( 'This file is not an eXeLearning file (.elpx).', 'exelearning' ), '', array( 'response' => 400 ) );
		}
	}

	/**
	 * Load the static editor HTML template from disk.
	 *
	 * @return string
	 */
	private function load_editor_template() {
		$static_index = EXELEARNING_PLUGIN_DIR . 'dist/static/index.html';
		if ( ! file_exists( $static_index ) ) {
			wp_die( esc_html__( 'Static eXeLearning editor not installed.', 'exelearning' ), '', array( 'response' => 503 ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$template = file_get_contents( $static_index );
		if ( false === $template ) {
			wp_die( esc_html__( 'Failed to load editor template.', 'exelearning' ), '', array( 'response' => 500 ) );
		}
		return $template;
	}

	/**
	 * Inject the WordPress export configuration, base tag and asset URL
	 * rewrites into the static editor template.
	 *
	 * @param string $template        Raw static editor HTML.
	 * @param int    $attachment_id   Attachment ID.
	 * @param string $elp_url         URL of the .elpx attachment to import.
	 * @param string $editor_base_url Absolute plugin URL of the static editor.
	 * @return string Patched HTML ready to send to the browser.
	 */
	private function inject_bootstrap_payload( $template, $attachment_id, $elp_url, $editor_base_url ) {
		$config = array(
			'mode'          => 'WordPressExport',
			'attachmentId'  => $attachment_id,
			'elpUrl'        => $elp_url,
			'editorBaseUrl' => $editor_base_url,
		);

		$bridge_url = EXELEARNING_PLUGIN_URL . 'assets/js/wp-exe-bridge.js?ver=' . EXELEARNING_VERSION;

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML page output, not a WordPress template; scripts must be inline.
		$inject = sprintf(
			'<script>
                window.__WP_EXE_CONFIG__ = %1$s;
                window.__EXE_STATIC_MODE__ = true;
                window.__EXE_WP_MODE__ = true;
                window.__EXE_EXPORT_MODE__ = true;
                window.__EXE_EMBEDDING_CONFIG__ = {
                    basePath: %2$s,
                    initialProjectUrl: %3$s,
                    parentOrigin: window.location.origin,
                    trustedOrigins: [window.location.origin],
                    hideUI: { fileMenu: true, saveButton: true, userMenu: true },
                };
            </script>
            <script src="%4$s" defer></script>',
			wp_json_encode( $config ),
			wp_json_encode( $editor_base_url ),
			wp_json_encode( $elp_url ),
			esc_url( $bridge_url )
		);
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		// Inject config and our bridge before </head>.
		$template = str_replace( '</head>', $inject . '</head>', $template );

		// Add <base> tag so relative asset paths resolve to the static editor
		// directory rather than the current page URL. Mirrors the same pattern
		// used by admin/views/editor-bootstrap.php.
		$base_tag = sprintf( '<base href="%s/">', esc_url( $editor_base_url ) );
		$template = preg_replace( '/(<head[^>]*>)/i', '$1' . $base_tag, $template, 1 );

		// Rewrite explicit `./` relative paths to absolute plugin URLs.
		return preg_replace(
			'/(?<=["\'])\.\//',
			esc_url( $editor_base_url ) . '/',
			$template
		);
	}
}
