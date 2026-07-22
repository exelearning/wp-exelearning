<?php
/**
 * Plugin for eXeLearning WordPress integration.
 *
 * @link https://github.com/exelearning/wp-exelearning
 * @package Exelearning
 *
 * @wordpress-plugin
 * Plugin Name:       eXeLearning
 * Plugin URI:        https://github.com/exelearning/wp-exelearning
 * Description:       Plugin to support eXeLearning .elp files in WordPress. Upload, manage and embed eXeLearning content.
 * Version:           0.0.0
 * Author:            INTEF
 * Author URI:        https://exelearning.net/
 * License:           AGPL-3.0+
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:       exelearning
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Start output buffering early for the editor page to capture any PHP warnings/notices.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['page'] ) && 'exelearning-editor' === $_GET['page'] ) {
	ob_start();
}

define( 'EXELEARNING_VERSION', '0.0.0' );
define( 'EXELEARNING_PLUGIN_FILE', __FILE__ );
define( 'EXELEARNING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXELEARNING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core classes.
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-activator.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-exelearning.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-hooks.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-i18n.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-filters.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-post-types.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-mime-types.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-elp-list-table.php';

// Load the eXeLearning file upload handler.
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-elp-upload-handler.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-download-formats.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-download-button-renderer.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-elp-upload-block.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-viewer-enhancements.php';

// Styles management (uploaded/builtin registry).
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-style-package.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-styles-service.php';

// One-time DB migrations keyed on the stored schema version.
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-upgrader.php';

// Admin classes.
require_once EXELEARNING_PLUGIN_DIR . 'admin/class-admin-settings.php';
require_once EXELEARNING_PLUGIN_DIR . 'admin/class-admin-styles.php';
require_once EXELEARNING_PLUGIN_DIR . 'admin/class-admin-upload.php';
// Public classes.
require_once EXELEARNING_PLUGIN_DIR . 'public/class-shortcodes.php';

// Integration classes.
require_once EXELEARNING_PLUGIN_DIR . 'includes/integrations/class-media-library.php';

// ELP File Service (validates, parses and extracts .elp files).
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-elp-file-service.php';

// Reprocessor for existing attachments (reused by the REST API and entry points).
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-elp-reprocessor.php';

// Editor classes.
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-exelearning-editor.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-export-bootstrap.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-exelearning-rest-api.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-content-hash-aliases.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-content-proxy.php';
require_once EXELEARNING_PLUGIN_DIR . 'includes/class-static-editor-installer.php';


// WP-CLI commands (batch reprocessing for large sites).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once EXELEARNING_PLUGIN_DIR . 'includes/class-cli-command.php';
	WP_CLI::add_command( 'exelearning', 'ExeLearning_CLI_Command' );
}

// Register activation and deactivation hooks.
register_activation_hook( __FILE__, array( 'ExeLearning_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ExeLearning_Deactivator', 'deactivate' ) );


/**
 * Starts the plugin.
 */
function exelearning_run() {
	$viewer_enhancements = new ExeLearning_Viewer_Enhancements();
	$viewer_enhancements->register_hooks();

	$plugin = new ExeLearning();
	$plugin->run();
}

exelearning_run();
