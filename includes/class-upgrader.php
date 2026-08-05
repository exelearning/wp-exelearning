<?php
/**
 * Database schema upgrades for the eXeLearning plugin.
 *
 * Runs one-time migrations keyed on a stored DB version option so stored
 * option shapes can evolve between plugin releases.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Upgrader.
 */
class ExeLearning_Upgrader {

	/**
	 * Option storing the currently applied DB schema version.
	 */
	const OPTION_DB_VERSION = 'exelearning_db_version';

	/**
	 * Latest DB schema version shipped with this plugin build.
	 */
	const DB_VERSION = 2;

	/**
	 * Run any pending migrations. Hooked on admin_init; cheap no-op once the
	 * stored version is current.
	 */
	public static function maybe_upgrade() {
		$current = (int) get_option( self::OPTION_DB_VERSION, 0 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}

		if ( $current < 1 ) {
			self::migrate_disabled_styles_option();
		}
		if ( $current < 2 ) {
			self::remove_editor_installer_state();
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * DB version 1: consolidate style enable/disable state into the flat
	 * exelearning_disabled_styles option.
	 *
	 * Previously the styles registry option carried a `disabled_builtins`
	 * list plus a per-upload `enabled` flag, each written by its own AJAX
	 * endpoint. Both are folded into a single list of disabled ids so the
	 * settings form can save them in one request. The legacy registry keys
	 * are left in place (they are simply no longer read), which keeps the
	 * data intact if the site is rolled back to an older plugin version.
	 */
	private static function migrate_disabled_styles_option() {
		$registry = ExeLearning_Styles_Service::get_registry();
		$disabled = ExeLearning_Styles_Service::get_disabled_styles();

		foreach ( $registry['disabled_builtins'] as $id ) {
			$id = ExeLearning_Styles_Service::normalize_slug( $id );
			if ( '' !== $id && ! in_array( $id, $disabled, true ) ) {
				$disabled[] = $id;
			}
		}
		foreach ( $registry['uploaded'] as $slug => $meta ) {
			if ( is_array( $meta ) && isset( $meta['enabled'] ) && ! $meta['enabled'] && ! in_array( (string) $slug, $disabled, true ) ) {
				$disabled[] = (string) $slug;
			}
		}

		sort( $disabled );
		update_option( ExeLearning_Styles_Service::OPTION_DISABLED_STYLES, $disabled, false );
	}

	/**
	 * DB version 2: drop the runtime editor-installer state.
	 *
	 * The embedded editor became a release artifact bundled inside the plugin
	 * package (ADR-72-01), so the runtime installer and its bookkeeping are
	 * gone. Only the stored metadata is removed; a previously self-installed
	 * dist/static/ copy is simply replaced on the next plugin update.
	 */
	private static function remove_editor_installer_state() {
		delete_option( 'exelearning_static_editor' );
		delete_transient( 'exelearning_installing_editor' );
	}
}
