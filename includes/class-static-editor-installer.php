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
	 * AJAX action name.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'exelearning_install_editor';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_install_request' ) );
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
	 * Handle the AJAX install request.
	 */
	public function handle_install_request() {
		if ( ! check_ajax_referer( self::AJAX_ACTION, '_nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'exelearning' ) ),
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to install the editor.', 'exelearning' ) ),
				403
			);
		}

		// Prevent concurrent installs.
		if ( get_transient( 'exelearning_installing_editor' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'An installation is already in progress. Please wait.', 'exelearning' ) ),
				409
			);
		}
		set_transient( 'exelearning_installing_editor', true, 300 );

		$result = $this->install_latest_editor();

		delete_transient( 'exelearning_installing_editor' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: editor version */
					__( 'eXeLearning editor v%s installed successfully.', 'exelearning' ),
					$result['version']
				),
				'version' => $result['version'],
			)
		);
	}

	/**
	 * Install the latest static editor from GitHub Releases.
	 *
	 * @return array|WP_Error Array with 'version' key on success, WP_Error on failure.
	 */
	public function install_latest_editor() {
		$version = $this->discover_latest_version();
		if ( is_wp_error( $version ) ) {
			return $version;
		}

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
				'timeout'    => 30,
				'headers'    => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; eXeLearning Plugin',
				),
				'sslverify'  => true,
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
