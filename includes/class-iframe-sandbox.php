<?php
/**
 * Iframe sandbox mode for embedded eXeLearning content.
 *
 * Single source of truth for the option name and the per-mode iframe `sandbox`
 * tokens. In `secure` mode the content iframe omits `allow-same-origin`, so the
 * arbitrary author HTML/JS of an .elpx runs in an opaque origin and cannot read
 * the WordPress page's cookies/DOM or reach `window.parent`. `legacy` restores
 * `allow-same-origin` for environments that need it (e.g. WordPress Playground,
 * whose service worker only serves same-origin documents).
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Iframe_Sandbox.
 */
class ExeLearning_Iframe_Sandbox {

	/**
	 * Option that stores the selected mode.
	 *
	 * @var string
	 */
	const OPTION = 'exelearning_iframe_sandbox_mode';

	/**
	 * Secure (opaque-origin) mode.
	 *
	 * @var string
	 */
	const MODE_SECURE = 'secure';

	/**
	 * Legacy (same-origin) mode.
	 *
	 * @var string
	 */
	const MODE_LEGACY = 'legacy';

	/**
	 * Sandbox tokens for secure mode (no allow-same-origin: opaque origin).
	 *
	 * @var string
	 */
	const TOKENS_SECURE = 'allow-scripts allow-popups';

	/**
	 * Sandbox tokens for legacy mode (same-origin).
	 *
	 * @var string
	 */
	const TOKENS_LEGACY = 'allow-scripts allow-same-origin allow-popups';

	/**
	 * Resolve the configured mode, normalized and fail-safe to secure.
	 *
	 * @return string Either MODE_SECURE or MODE_LEGACY.
	 */
	public static function mode() {
		$value = get_option( self::OPTION, self::MODE_SECURE );
		return self::MODE_LEGACY === $value ? self::MODE_LEGACY : self::MODE_SECURE;
	}

	/**
	 * Whether the secure (opaque-origin) mode is active.
	 *
	 * @return bool
	 */
	public static function is_secure() {
		return self::MODE_SECURE === self::mode();
	}

	/**
	 * Sandbox attribute value for the current mode.
	 *
	 * @return string
	 */
	public static function sandbox_tokens() {
		return self::is_secure() ? self::TOKENS_SECURE : self::TOKENS_LEGACY;
	}
}
