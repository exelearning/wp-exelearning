<?php
/**
 * Static editor installer for eXeLearning.
 *
 * Downloads and installs the static eXeLearning editor from GitHub Releases
 * when local dist/static/ assets are not present.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Static_Editor_Installer.
 *
 * Handles downloading, validating, and installing the static eXeLearning editor
 * from GitHub Releases into the plugin's dist/static/ directory.
 */
class ExeLearning_Static_Editor_Installer {

	/**
	 * GitHub API URL for latest release.
	 *
	 * @var string
	 */
	const GITHUB_API_URL = 'https://api.github.com/repos/exelearning/exelearning/releases/latest';

	/**
	 * GitHub Releases API URL for a specific tag (append "v{version}").
	 *
	 * @var string
	 */
	const GITHUB_RELEASE_BY_TAG_URL = 'https://api.github.com/repos/exelearning/exelearning/releases/tags/';

	/**
	 * Asset filename prefix.
	 *
	 * @var string
	 */
	const ASSET_PREFIX = 'exelearning-static-v';

	/**
	 * Option name for storing installed editor metadata.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'exelearning_static_editor';

	/**
	 * admin-post action (and nonce action) for installing/updating the editor.
	 *
	 * @var string
	 */
	const ACTION = 'exelearning_install_editor';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_install_post' ) );
	}

	/**
	 * Check if the static editor is installed locally.
	 *
	 * @return bool True if installed.
	 */
	public static function is_editor_installed() {
		return file_exists( self::get_editor_path() . 'index.html' );
	}

	/**
	 * Get the local editor directory path.
	 *
	 * @return string Path to dist/static/.
	 */
	public static function get_editor_path() {
		return EXELEARNING_PLUGIN_DIR . 'dist/static/';
	}

	/**
	 * Get installed editor metadata.
	 *
	 * @return array|false Array with 'version' and 'installed_at' keys, or false.
	 */
	public static function get_installed_version() {
		$data = get_option( self::OPTION_NAME );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return false;
		}
		return $data;
	}

	/**
	 * Handle the admin-post install/update request from the settings page.
	 *
	 * Runs the installation synchronously, reports the outcome through the
	 * Settings API notices (add_settings_error + the settings_errors
	 * transient) and redirects back to the settings page — or straight to
	 * the editor when the user originally came from an attachment.
	 */
	public function handle_install_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to install the editor.', 'exelearning' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$return_attachment = isset( $_POST['return_attachment'] ) ? absint( $_POST['return_attachment'] ) : 0;

		// Buffer any stray output (e.g. PHP warnings surfaced by
		// WP_DEBUG_DISPLAY during the install) so it cannot break the
		// redirect below with "headers already sent".
		ob_start();

		// Prevent concurrent installs.
		if ( get_transient( 'exelearning_installing_editor' ) ) {
			$this->redirect_with_notice( __( 'An installation is already in progress. Please wait.', 'exelearning' ), 'warning' );
		}
		set_transient( 'exelearning_installing_editor', true, 300 );

		$result = $this->install_latest_editor();

		delete_transient( 'exelearning_installing_editor' );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( $result->get_error_message(), 'error' );
		}

		// When the user was redirected here from an attachment because the
		// editor was missing, send them straight back to that editor.
		if ( $return_attachment && self::is_editor_installed() ) {
			$this->finish_request(
				add_query_arg(
					array(
						'page'          => 'exelearning-editor',
						'attachment_id' => $return_attachment,
						'_wpnonce'      => wp_create_nonce( 'exelearning_editor' ),
					),
					admin_url( 'admin.php' )
				)
			);
		}

		$this->redirect_with_notice(
			sprintf(
				/* translators: %s: editor version */
				__( 'eXeLearning editor v%s installed successfully.', 'exelearning' ),
				$result['version']
			),
			'success'
		);
	}

	/**
	 * Register a settings notice, persist it across the redirect, and send
	 * the user back to the settings page (same pattern core options.php uses).
	 *
	 * @param string $message User-facing message.
	 * @param string $type    Notice type: 'success', 'error', 'warning' or 'info'.
	 */
	private function redirect_with_notice( $message, $type ) {
		add_settings_error( 'exelearning_editor', 'exelearning_editor_install', $message, $type );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		$this->finish_request(
			add_query_arg(
				array(
					'page'             => 'exelearning-settings',
					'settings-updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
	}

	/**
	 * Redirect and terminate the request.
	 *
	 * Split into its own method so tests can override it instead of letting
	 * exit end the PHP process.
	 *
	 * @param string $location Redirect target URL.
	 */
	protected function finish_request( $location ) {
		// Discard the buffer opened by handle_install_post() (and anything
		// the install wrote into it) so the redirect header can be sent.
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		wp_safe_redirect( $location );
		exit;
	}

	/**
	 * Install the latest static editor from GitHub Releases.
	 *
	 * @return array|WP_Error Array with 'version' key on success, WP_Error on failure.
	 */
	public function install_latest_editor() {
		$version = $this->discover_latest_version();
		if ( is_wp_error( $version ) ) {
			$this->fire_install_failed( $version );
			return $version;
		}

		/**
		 * Fires before the static editor installation starts.
		 *
		 * Observation point only. It runs after the target version has been
		 * resolved and must NOT be used to change the download URL, skip checksum
		 * or archive validation, or alter the trusted install directory.
		 *
		 * @since 1.0.0
		 *
		 * @param string $version Requested editor version.
		 */
		do_action( 'exelearning_before_editor_install', $version );

		$result = $this->perform_editor_install( $version );

		if ( is_wp_error( $result ) ) {
			$this->fire_install_failed( $result );
			return $result;
		}

		/**
		 * Fires after the static editor has been installed successfully.
		 *
		 * @since 1.0.0
		 *
		 * @param array $metadata Installed editor metadata (version, installed_at).
		 */
		do_action( 'exelearning_after_editor_install', $result );

		return $result;
	}

	/**
	 * Fire the editor-install failure action.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error $error The failure that aborted installation.
	 */
	private function fire_install_failed( $error ) {
		/**
		 * Fires when the static editor installation fails.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Error $error WP_Error describing the failure.
		 */
		do_action( 'exelearning_editor_install_failed', $error );
	}

	/**
	 * Download, verify, and install the editor for a resolved version.
	 *
	 * @param string $version Resolved editor version.
	 * @return array|WP_Error Installed metadata on success, WP_Error on failure.
	 */
	private function perform_editor_install( $version ) {
		$asset_url = $this->get_asset_url( $version );

		$tmp_file = $this->download_asset( $asset_url );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$valid = $this->validate_zip( $tmp_file );
		if ( is_wp_error( $valid ) ) {
			$this->cleanup_temp_file( $tmp_file );
			return $valid;
		}

		// Verify the download against the SHA-256 digest GitHub publishes for the
		// release asset, so the editor we install (and then serve to authors in
		// an iframe) is bound to release metadata, not just transport TLS.
		$integrity = $this->verify_asset_integrity( $version, $tmp_file );
		if ( is_wp_error( $integrity ) ) {
			$this->cleanup_temp_file( $tmp_file );
			return $integrity;
		}

		$tmp_dir = $this->extract_zip( $tmp_file );
		$this->cleanup_temp_file( $tmp_file );

		if ( is_wp_error( $tmp_dir ) ) {
			return $tmp_dir;
		}

		$source_dir = $this->normalize_extraction( $tmp_dir );
		if ( is_wp_error( $source_dir ) ) {
			$this->cleanup_temp_dir( $tmp_dir );
			return $source_dir;
		}

		$valid = $this->validate_editor_contents( $source_dir );
		if ( is_wp_error( $valid ) ) {
			$this->cleanup_temp_dir( $tmp_dir );
			return $valid;
		}

		$result = $this->safe_install( $source_dir );
		$this->cleanup_temp_dir( $tmp_dir );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$metadata = array(
			'version'      => $version,
			'installed_at' => current_time( 'mysql' ),
		);
		update_option( self::OPTION_NAME, $metadata );

		return $metadata;
	}

	/**
	 * Discover the latest release version from GitHub.
	 *
	 * @return string|WP_Error Version string or error.
	 */
	public function discover_latest_version() {
		$response = wp_remote_get(
			self::GITHUB_API_URL,
			array(
				'timeout'   => 30,
				'headers'   => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; eXeLearning Plugin',
				),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'github_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not connect to GitHub: %s', 'exelearning' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'github_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GitHub API returned status %d. Please try again later.', 'exelearning' ),
					$status_code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return new WP_Error(
				'github_parse_error',
				__( 'Could not parse the latest release information from GitHub.', 'exelearning' )
			);
		}

		$version = ltrim( $body['tag_name'], 'v' );

		// Validate version format (must start with digits and a dot).
		if ( ! preg_match( '/^\d+\.\d+/', $version ) ) {
			return new WP_Error(
				'github_parse_error',
				sprintf(
					/* translators: %s: tag name */
					__( 'Unexpected release tag format: %s', 'exelearning' ),
					$body['tag_name']
				)
			);
		}

		return $version;
	}

	/**
	 * Build the download URL for the static editor asset.
	 *
	 * @param string $version Version string (without leading 'v').
	 * @return string Download URL.
	 */
	public function get_asset_url( $version ) {
		$filename = self::ASSET_PREFIX . $version . '.zip';
		return 'https://github.com/exelearning/exelearning/releases/download/v' . $version . '/' . $filename;
	}

	/**
	 * Download the asset ZIP file.
	 *
	 * @param string $url Download URL.
	 * @return string|WP_Error Path to temporary file or error.
	 */
	public function download_asset( $url ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp_file = download_url( $url, 300 );

		if ( is_wp_error( $tmp_file ) ) {
			return new WP_Error(
				'download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to download the editor package: %s', 'exelearning' ),
					$tmp_file->get_error_message()
				)
			);
		}

		return $tmp_file;
	}

	/**
	 * Verify a downloaded asset against the SHA-256 digest GitHub publishes for
	 * the release. GitHub's Releases API exposes an asset `digest` field
	 * (`sha256:<hex>`); when present we require an exact match before extracting.
	 * When the release predates GitHub digests (none published), we proceed —
	 * `download_url()` already validates TLS — so older releases keep installing.
	 *
	 * @param string $version  Version string (without leading 'v').
	 * @param string $tmp_file Path to the downloaded ZIP.
	 * @return true|WP_Error True if verified or no digest is published; error on mismatch/read failure.
	 */
	public function verify_asset_integrity( $version, $tmp_file ) {
		$expected = $this->fetch_asset_sha256( $version );
		if ( null === $expected ) {
			// No published digest for this release; rely on TLS (download_url).
			return true;
		}

		$actual = hash_file( 'sha256', $tmp_file );
		if ( false === $actual || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			return new WP_Error(
				'editor_digest_mismatch',
				__( 'The downloaded editor package failed its integrity (SHA-256) check and was discarded.', 'exelearning' )
			);
		}

		return true;
	}

	/**
	 * Fetch the GitHub-published SHA-256 digest for the static-editor asset of a
	 * given release tag.
	 *
	 * @param string $version Version string (without leading 'v').
	 * @return string|null Lowercase 64-char hex digest, or null if unavailable.
	 */
	public function fetch_asset_sha256( $version ) {
		$response = wp_remote_get(
			self::GITHUB_RELEASE_BY_TAG_URL . 'v' . rawurlencode( $version ),
			array(
				'timeout'   => 30,
				'headers'   => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; eXeLearning Plugin',
				),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return $this->extract_asset_sha256_from_release_api(
			wp_remote_retrieve_body( $response ),
			self::ASSET_PREFIX . $version . '.zip'
		);
	}

	/**
	 * Extract the SHA-256 digest for one asset from GitHub release JSON.
	 *
	 * @param string $json       GitHub Releases API JSON body.
	 * @param string $asset_name Expected asset filename.
	 * @return string|null Lowercase 64-char hex digest, or null if absent/invalid.
	 */
	public function extract_asset_sha256_from_release_api( $json, $asset_name ) {
		$body = json_decode( (string) $json, true );
		if ( ! is_array( $body ) || empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
			return null;
		}

		foreach ( $body['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ( isset( $asset['name'] ) ? $asset['name'] : '' ) !== $asset_name ) {
				continue;
			}
			$digest = strtolower( (string) ( isset( $asset['digest'] ) ? $asset['digest'] : '' ) );
			if ( preg_match( '/^sha256:([a-f0-9]{64})$/', $digest, $m ) ) {
				return $m[1];
			}
			return null;
		}

		return null;
	}

	/**
	 * Validate that a file is a ZIP archive by checking PK magic bytes.
	 *
	 * @param string $file_path Path to the file.
	 * @return true|WP_Error True if valid, error otherwise.
	 */
	public function validate_zip( $file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading first 4 bytes for magic number validation.
		$header = file_get_contents( $file_path, false, null, 0, 4 );
		if ( "PK\x03\x04" !== $header ) {
			return new WP_Error(
				'invalid_zip',
				__( 'The downloaded file is not a valid ZIP archive.', 'exelearning' )
			);
		}

		return true;
	}

	/**
	 * Extract a ZIP file to a temporary directory.
	 *
	 * @param string $zip_file Path to the ZIP file.
	 * @return string|WP_Error Path to temporary directory or error.
	 */
	public function extract_zip( $zip_file ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		WP_Filesystem();

		$tmp_dir = trailingslashit( get_temp_dir() ) . 'exelearning-editor-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			return new WP_Error(
				'mkdir_failed',
				__( 'Could not create temporary directory for extraction.', 'exelearning' )
			);
		}

		$result = unzip_file( $zip_file, $tmp_dir );

		if ( is_wp_error( $result ) ) {
			$this->cleanup_temp_dir( $tmp_dir );
			return new WP_Error(
				'extract_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to extract the editor package: %s', 'exelearning' ),
					$result->get_error_message()
				)
			);
		}

		return $tmp_dir;
	}

	/**
	 * Normalize extraction layout.
	 *
	 * The ZIP may contain files directly or inside a top-level directory.
	 * This method finds the actual source directory containing the editor.
	 *
	 * @param string $tmp_dir Path to extracted directory.
	 * @return string|WP_Error Path to the directory containing the editor files.
	 */
	public function normalize_extraction( $tmp_dir ) {
		if ( file_exists( trailingslashit( $tmp_dir ) . 'index.html' ) ) {
			return trailingslashit( $tmp_dir );
		}

		$entries = array_diff( scandir( $tmp_dir ), array( '.', '..' ) );
		if ( 1 === count( $entries ) ) {
			$single_entry = trailingslashit( $tmp_dir ) . reset( $entries );
			if ( is_dir( $single_entry ) && file_exists( trailingslashit( $single_entry ) . 'index.html' ) ) {
				return trailingslashit( $single_entry );
			}
		}

		// Check one more level deep for nested wrapper directories.
		foreach ( $entries as $entry ) {
			$entry_path = trailingslashit( $tmp_dir ) . $entry;
			if ( is_dir( $entry_path ) ) {
				$sub_entries = array_diff( scandir( $entry_path ), array( '.', '..' ) );
				if ( 1 === count( $sub_entries ) ) {
					$sub_entry = trailingslashit( $entry_path ) . reset( $sub_entries );
					if ( is_dir( $sub_entry ) && file_exists( trailingslashit( $sub_entry ) . 'index.html' ) ) {
						return trailingslashit( $sub_entry );
					}
				}
			}
		}

		return new WP_Error(
			'invalid_layout',
			__( 'The downloaded package does not contain the expected editor files. Could not find index.html.', 'exelearning' )
		);
	}

	/**
	 * Validate that extracted contents look like a valid static editor.
	 *
	 * Callable independently of normalize_extraction for direct validation.
	 *
	 * @param string $source_dir Path to the source directory.
	 * @return true|WP_Error True if valid, error otherwise.
	 */
	public function validate_editor_contents( $source_dir ) {
		$source_dir = trailingslashit( $source_dir );

		if ( ! file_exists( $source_dir . 'index.html' ) ) {
			return new WP_Error(
				'missing_index',
				__( 'The editor package is missing index.html.', 'exelearning' )
			);
		}

		$expected_dirs = array( 'app', 'libs', 'files' );
		$found_dir     = false;
		foreach ( $expected_dirs as $dir ) {
			if ( is_dir( $source_dir . $dir ) ) {
				$found_dir = true;
				break;
			}
		}

		if ( ! $found_dir ) {
			return new WP_Error(
				'missing_assets',
				__( 'The editor package is missing expected asset directories (app, libs, or files).', 'exelearning' )
			);
		}

		return true;
	}

	/**
	 * Install the editor with rollback on failure.
	 *
	 * Strategy: rename existing dir to backup, move/copy new files, remove backup on success.
	 * If install fails, restore backup.
	 *
	 * @param string $source_dir Path to validated source files.
	 * @return true|WP_Error True on success.
	 */
	public function safe_install( $source_dir ) {
		global $wp_filesystem;

		$target_dir = self::get_editor_path();
		$parent_dir = dirname( rtrim( $target_dir, '/' ) );
		$backup_dir = $parent_dir . '/static-backup-' . time();

		if ( ! is_dir( $parent_dir ) && ! wp_mkdir_p( $parent_dir ) ) {
			return new WP_Error(
				'mkdir_failed',
				__( 'Could not create the dist directory.', 'exelearning' )
			);
		}

		$had_existing = is_dir( rtrim( $target_dir, '/' ) );
		if ( $had_existing ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic rename for backup.
			if ( ! rename( rtrim( $target_dir, '/' ), $backup_dir ) ) {
				return new WP_Error(
					'backup_failed',
					__( 'Could not back up the existing editor installation.', 'exelearning' )
				);
			}
		}

		// Try rename first (fast, same-filesystem). Fall back to copy_dir.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Attempting efficient rename before copy fallback.
		$installed = rename( rtrim( $source_dir, '/' ), rtrim( $target_dir, '/' ) );

		if ( ! $installed ) {
			// Fallback: use WordPress copy_dir() which integrates with WP_Filesystem.
			wp_mkdir_p( rtrim( $target_dir, '/' ) );
			$copy_result = copy_dir( rtrim( $source_dir, '/' ), rtrim( $target_dir, '/' ) );
			$installed   = ! is_wp_error( $copy_result );
		}

		if ( ! $installed ) {
			// Restore backup on failure.
			if ( $had_existing && is_dir( $backup_dir ) ) {
				// Clean partial install attempt.
				if ( is_dir( rtrim( $target_dir, '/' ) ) && $wp_filesystem ) {
					$wp_filesystem->delete( rtrim( $target_dir, '/' ), true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Restoring backup after failed install.
				rename( $backup_dir, rtrim( $target_dir, '/' ) );
			}
			return new WP_Error(
				'copy_failed',
				__( 'Failed to copy editor files to the plugin directory.', 'exelearning' )
			);
		}

		// Clean up backup.
		if ( $had_existing && is_dir( $backup_dir ) && $wp_filesystem ) {
			$wp_filesystem->delete( $backup_dir, true );
		}

		return true;
	}

	/**
	 * Clean up a temporary file.
	 *
	 * @param string $file Path to temporary file.
	 */
	private function cleanup_temp_file( $file ) {
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Clean up a temporary directory.
	 *
	 * @param string $dir Path to temporary directory.
	 */
	private function cleanup_temp_dir( $dir ) {
		global $wp_filesystem;

		if ( is_dir( $dir ) ) {
			if ( $wp_filesystem ) {
				$wp_filesystem->delete( $dir, true );
			}
		}
	}
}
