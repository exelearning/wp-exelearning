<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code to run during the plugin's activation.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Activator.
 *
 * Handles plugin activation tasks.
 */
class ExeLearning_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::schedule_preview_cleanup();
	}

	/**
	 * Schedule the recurring idle-preview-session sweep.
	 *
	 * WP-Cron is traffic-dependent, so the store also enforces the idle TTL at
	 * request time; this sweep reclaims sessions that are never touched again.
	 * The custom recurrence is registered by ExeLearning_Preview_Proxy, whose
	 * `cron_schedules` filter is attached when the plugin loads (this request
	 * included), so the schedule resolves here.
	 */
	private static function schedule_preview_cleanup() {
		if ( ! wp_next_scheduled( ExeLearning_Preview_Proxy::CRON_HOOK ) ) {
			wp_schedule_event(
				time() + ( 15 * MINUTE_IN_SECONDS ),
				ExeLearning_Preview_Proxy::CRON_SCHEDULE,
				ExeLearning_Preview_Proxy::CRON_HOOK
			);
		}
	}
}
