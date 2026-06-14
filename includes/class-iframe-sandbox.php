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
	 * The allow-forms token lets the form-based eXeLearning iDevices submit inside the
	 * sandbox; it is orthogonal to allow-same-origin and does not weaken isolation.
	 * Aligned with mod_exelearning's canonical token set (DEC-0059/DEC-0062).
	 *
	 * @var string
	 */
	const TOKENS_SECURE = 'allow-scripts allow-popups allow-forms';

	/**
	 * Sandbox tokens for legacy mode (same-origin). Mirrors the secure set plus
	 * allow-same-origin and allow-popups-to-escape-sandbox, matching mod_exelearning.
	 *
	 * @var string
	 */
	const TOKENS_LEGACY = 'allow-scripts allow-same-origin allow-popups allow-forms allow-popups-to-escape-sandbox';

	/**
	 * Script handle for the parent-page embed relay.
	 *
	 * @var string
	 */
	const HANDLE_RELAY = 'exelearning-embed-relay';

	/**
	 * Default host whitelist for external embeds promoted to the parent page.
	 *
	 * In secure mode the content runs opaque, so YouTube/Vimeo players load blank.
	 * Iframes whose src host is on this list are replaced by a placeholder in the
	 * content and rendered as a real player by the host page (see the embed JS).
	 * Anything not on the list is left sandboxed.
	 *
	 * @var string[]
	 */
	const DEFAULT_EMBED_HOSTS = array(
		'www.youtube.com',
		'youtube.com',
		'www.youtube-nocookie.com',
		'youtube-nocookie.com',
		'player.vimeo.com',
		'vimeo.com',
		'www.dailymotion.com',
		'dailymotion.com',
		'geo.dailymotion.com',
		'mediateca.educa.madrid.org',
	);

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

	/**
	 * Normalized host whitelist for external embeds.
	 *
	 * @return string[] Lowercase, trimmed, de-duplicated hostnames.
	 */
	public static function embed_whitelist() {
		/**
		 * Filters the host whitelist for external embeds promoted to the parent.
		 *
		 * @param string[] $hosts Default allowed hostnames.
		 */
		$hosts = apply_filters( 'exelearning_embed_whitelist', self::DEFAULT_EMBED_HOSTS );
		$clean = array();
		foreach ( (array) $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( '' !== $host ) {
				$clean[ $host ] = true;
			}
		}
		return array_keys( $clean );
	}

	/**
	 * Enqueue the parent-page embed relay (secure mode only).
	 *
	 * Called lazily when a shortcode/block renders a content iframe. The relay
	 * finds content iframes by message source, so a single enqueue covers every
	 * embed instance on the page. No-op in legacy mode (content is same-origin,
	 * so external players already render inline).
	 */
	public static function enqueue_embed_relay() {
		if ( ! self::is_secure() ) {
			return;
		}
		if ( wp_script_is( self::HANDLE_RELAY, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE_RELAY,
			plugins_url( 'assets/js/exe-embed-relay.js', EXELEARNING_PLUGIN_FILE ),
			array(),
			EXELEARNING_VERSION,
			true
		);
		wp_add_inline_script(
			self::HANDLE_RELAY,
			'window.ExeEmbedRelayConfig=' . wp_json_encode( array( 'whitelist' => self::embed_whitelist() ) ) . ';',
			'before'
		);
	}
}
