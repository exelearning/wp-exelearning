<?php
/**
 * Iframe sandbox mode for embedded eXeLearning content.
 *
 * Single source of truth for the iframe `sandbox` tokens and the content CSP. The content
 * iframe always omits `allow-same-origin`, so the arbitrary author HTML/JS of an .elpx runs
 * in an opaque origin and cannot read the WordPress page's cookies/DOM or reach
 * `window.parent`. The previous same-origin `legacy` admin mode was removed: it is now only
 * reachable through a dev-only escape hatch (the `EXELEARNING_UNSAFE_LEGACY_IFRAME` constant,
 * default off, never exposed in the admin UI) used by environments whose service worker
 * cannot serve opaque subframes, e.g. the WordPress Playground (php-wasm) demo.
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
	 * Option that stores the external-embed policy (open|strict). Mirrors
	 * mod_exelearning's canonical embedmode setting (DEC-0061).
	 *
	 * @var string
	 */
	const EMBED_OPTION = 'exelearning_embed_mode';

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
	 * Open embeds: promote any cross-origin https iframe (DEC-0061).
	 *
	 * @var string
	 */
	const EMBED_OPEN = 'open';

	/**
	 * Strict embeds: only the maintained host allowlist with per-provider
	 * canonical-URL reconstruction.
	 *
	 * @var string
	 */
	const EMBED_STRICT = 'strict';

	/**
	 * Strict CSP profile (default): no bare https: token-exfiltration channels.
	 *
	 * @var string
	 */
	const CSP_STRICT = 'strict';

	/**
	 * Compatible CSP profile: allows https: img/media/script for external author assets.
	 *
	 * @var string
	 */
	const CSP_COMPATIBLE = 'compatible';

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
	 * Resolve the iframe mode. Production is always secure (opaque origin); the same-origin
	 * admin option was removed. A dev-only escape hatch (the EXELEARNING_UNSAFE_LEGACY_IFRAME
	 * constant, set outside the admin UI, e.g. by the WordPress Playground blueprint) restores
	 * same-origin where an opaque subframe cannot be served. It defaults off.
	 *
	 * @return string Either MODE_SECURE or MODE_LEGACY.
	 */
	public static function mode() {
		return self::is_unsafe_legacy() ? self::MODE_LEGACY : self::MODE_SECURE;
	}

	/**
	 * Whether the dev-only unsafe legacy (same-origin) escape hatch is enabled. Never exposed
	 * in the admin UI; intended only for environments whose service worker cannot serve an
	 * opaque subframe (the php-wasm WordPress Playground). Defaults off.
	 *
	 * @return bool
	 */
	public static function is_unsafe_legacy() {
		return defined( 'EXELEARNING_UNSAFE_LEGACY_IFRAME' ) && true === EXELEARNING_UNSAFE_LEGACY_IFRAME;
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
	 * Resolve the external-embed policy (DEC-0061). Default 'strict' restricts promotion to
	 * the maintained provider allowlist with canonical URL reconstruction; 'open' is an
	 * explicit opt-in that promotes any cross-origin https iframe (the player is sandboxed +
	 * cross-origin, so SOP isolates it from the host page). Any unset or unrecognised value
	 * fails safe to 'strict' (toward the more restrictive policy).
	 *
	 * @return string Either EMBED_OPEN or EMBED_STRICT.
	 */
	public static function embed_mode() {
		$value = get_option( self::EMBED_OPTION, self::EMBED_STRICT );
		return self::EMBED_OPEN === $value ? self::EMBED_OPEN : self::EMBED_STRICT;
	}

	/**
	 * Resolve the content CSP profile. 'strict' (default) blocks bare https: exfiltration
	 * channels; 'compatible' re-opens img/media/script to https: for content that loads
	 * external author assets (third-party images, a MathJax CDN) and is documented weaker.
	 * Opt in via the `exelearning_csp_profile` filter; any other value fails safe to strict.
	 *
	 * @return string Either CSP_STRICT or CSP_COMPATIBLE.
	 */
	public static function csp_profile() {
		/**
		 * Filters the content CSP profile (strict|compatible). Strict is the default.
		 *
		 * @param string $profile The CSP profile name.
		 */
		$profile = apply_filters( 'exelearning_csp_profile', self::CSP_STRICT );
		return self::CSP_COMPATIBLE === $profile ? self::CSP_COMPATIBLE : self::CSP_STRICT;
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
		// The relay only consults the host whitelist in 'strict' mode (the default); in the
		// opt-in 'open' mode any cross-origin https iframe is promoted, so skip the list.
		$mode = self::embed_mode();
		wp_add_inline_script(
			self::HANDLE_RELAY,
			'window.ExeEmbedRelayConfig=' . wp_json_encode(
				array(
					'mode'      => $mode,
					'whitelist' => self::EMBED_STRICT === $mode ? self::embed_whitelist() : array(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Enqueue the parent-page media host for the interactive-video iDevice (secure mode
	 * only). The opaque content cannot run a nested YouTube/Vimeo player, so eXeLearning's
	 * interactive-video iDevice drives playback through window.exeMediaBridge (the child
	 * runtime baked into the package by eXeLearning); this host completes that capability
	 * handshake (window identity + per-view nonce + a transferred MessageChannel port) and
	 * plays the real provider video in an accessible modal, controlled by RAW postMessage
	 * (no YouTube IFrame API / Vimeo SDK loaded on this page). Separate message namespace
	 * ('exe-media') from the embed relay ('exe-embed'), so both coexist. No-op in legacy
	 * mode. Mirrors mod_exelearning's view.php wiring (DEC-0067).
	 */
	public static function enqueue_media_host() {
		if ( ! self::is_secure() ) {
			return;
		}
		if ( wp_script_is( 'exelearning-media-host', 'enqueued' ) ) {
			return;
		}
		// Policy first; the host reads window.exeMediaPolicy at evaluation.
		wp_enqueue_script(
			'exelearning-media-policy',
			plugins_url( 'assets/js/exe-media-policy.js', EXELEARNING_PLUGIN_FILE ),
			array(),
			EXELEARNING_VERSION,
			true
		);
		wp_enqueue_script(
			'exelearning-media-host',
			plugins_url( 'assets/js/exe-media-host.js', EXELEARNING_PLUGIN_FILE ),
			array( 'exelearning-media-policy' ),
			EXELEARNING_VERSION,
			true
		);
		// Attach the host to every content iframe (promoted players excluded). attach() is
		// harmless on non-eXe iframes (no hello arrives), so scanning mirrors the relay's
		// source-discovery without needing a fixed iframe id.
		wp_add_inline_script(
			'exelearning-media-host',
			'(function(){function a(){if(!window.exeMediaHost)return;'
			. 'var f=document.getElementsByTagName("iframe");for(var i=0;i<f.length;i++){'
			. 'if(f[i].getAttribute("data-exe-embed-player"))continue;'
			. 'if(f[i].getAttribute("data-exe-media-attached"))continue;'
			. 'f[i].setAttribute("data-exe-media-attached","1");window.exeMediaHost.attach(f[i],{});}}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",a);}else{a();}})();',
			'after'
		);
	}
}
