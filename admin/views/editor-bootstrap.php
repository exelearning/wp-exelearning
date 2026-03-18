<?php
/**
 * EXeLearning Static Editor Bootstrap
 *
 * Loads the static PWA version of eXeLearning editor with WordPress integration.
 * The static editor is built with `make build-editor` and placed in dist/static/.
 *
 * @package Exelearning
 */

// Security check - this file should only be loaded by WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'Security check failed' );
}

// Ensure clean output - discard any previous output/warnings.
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

// Get parameters - nonce verification is done in ExeLearning_Editor class before loading this template.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in class-exelearning-editor.php
$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;

// Get the ELP file URL and info.
$elp_url      = '';
$elp_filename = '';
if ( $attachment_id ) {
	$url = wp_get_attachment_url( $attachment_id );
	if ( $url ) {
		$elp_url = $url;
	}
	$file = get_attached_file( $attachment_id );
	if ( $file ) {
		$elp_filename = basename( $file );
	}
}

// Get attachment title (ensure it's never null).
$page_title = get_the_title( $attachment_id );
if ( empty( $page_title ) ) {
	$page_title = $elp_filename ? $elp_filename : 'Untitled';
}

// Remote editor URL (used when local dist/static/ assets are not available).
// Production static editor URL.
$remote_editor_url = 'https://app.exelearning.net/';
// Nightly (uncomment to use development/nightly builds):
// $remote_editor_url = 'https://static.exelearning.dev/';

// Plugin assets URL.
$plugin_assets_url = EXELEARNING_PLUGIN_URL . 'assets';

// REST API for saving.
$rest_url = rest_url( 'exelearning/v1' );
$nonce    = wp_create_nonce( 'wp_rest' );

// Get locale (ensure it's never null).
$site_locale  = get_locale();
$locale_short = $site_locale ? substr( $site_locale, 0, 2 ) : 'en';

// User data (ensure values are never null).
$user_data = wp_get_current_user();
$user_name = $user_data->display_name ? $user_data->display_name : 'User';
$user_id   = $user_data->ID ? $user_data->ID : 0;

// Check if static editor exists locally.
$static_index = EXELEARNING_PLUGIN_DIR . 'dist/static/index.html';
$uses_local = file_exists( $static_index );

if ( $uses_local ) {
	$editor_base_url = EXELEARNING_PLUGIN_URL . 'dist/static';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$template = file_get_contents( $static_index );
} else {
	$editor_base_url = rtrim( ExeLearning_Editor_Asset_Proxy::get_proxy_base_url(), '/' );
	$response = wp_remote_get(
		$remote_editor_url . 'index.html',
		array( 'timeout' => 15 )
	);
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		wp_die(
			esc_html__( 'Could not load remote eXeLearning editor.', 'exelearning' ),
			esc_html__( 'Editor Error', 'exelearning' ),
			array(
				'response'  => 500,
				'back_link' => true,
			)
		);
	}
	$template = wp_remote_retrieve_body( $response );
}

if ( false === $template || empty( $template ) ) {
	wp_die(
		esc_html__( 'Failed to load eXeLearning editor template.', 'exelearning' ),
		esc_html__( 'Template Error', 'exelearning' ),
		array( 'response' => 500 )
	);
}

// Translations for JavaScript.
$i18n = array(
	'saving'     => __( 'Saving...', 'exelearning' ),
	'saved'      => __( 'Saved to WordPress successfully', 'exelearning' ),
	'saveButton' => __( 'Save to WordPress', 'exelearning' ),
	'loading'    => __( 'Loading project...', 'exelearning' ),
	'error'      => __( 'Error', 'exelearning' ),
);

// Inject WordPress configuration BEFORE the closing </head> tag.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML page output, not a WordPress template.
$wp_config_script = sprintf(
	'
    <!-- WordPress Integration Configuration -->
    <script>
        // WordPress Integration Configuration
        window.__WP_EXE_CONFIG__ = {
            mode: "WordPress",
            attachmentId: %d,
            elpUrl: %s,
            projectId: %s,
            restUrl: %s,
            nonce: %s,
            locale: %s,
            userName: %s,
            userId: %d,
            editorBaseUrl: %s,
            i18n: %s
        };

        // Override static mode detection for WordPress
        window.__EXE_STATIC_MODE__ = true;
        window.__EXE_WP_MODE__ = true;

        // Embedding configuration for the editor.
        // The editor reads this in RuntimeConfig.fromEnvironment() and applies
        // basePath in App.initializeModeDetection(). UI hiding is done via
        // data attributes on <body> + CSS in main.scss.
        window.__EXE_EMBEDDING_CONFIG__ = {
            basePath: window.__WP_EXE_CONFIG__.editorBaseUrl,
            initialProjectUrl: window.__WP_EXE_CONFIG__.elpUrl || null,
            parentOrigin: window.location.origin,
            trustedOrigins: [window.location.origin],
            hideUI: {
                fileMenu: true,
                saveButton: true,
                userMenu: true,
            },
        };

        // TODO: Remove when editor ResourceFetcher handles 404 gracefully.
        // Patch fetch and jQuery AJAX to handle CSS/idevices 404s without breaking.
        (function() {
            var editorBaseUrl = (window.__WP_EXE_CONFIG__ && window.__WP_EXE_CONFIG__.editorBaseUrl) || "";
            var editorBasePathname = "";
            var originalServiceWorker = navigator.serviceWorker || null;
            var forceHideSelectors = [
                "#dropdownFile",
                "#head-top-save-button",
                "#head-bottom-user-logged",
                "#exe-concurrent-users",
                "#mobile-navbar-button-save",
                "#mobile-navbar-button-openuserodefiles"
            ];

            try {
                editorBasePathname = editorBaseUrl ? new URL(editorBaseUrl, window.location.origin).pathname : "";
            } catch (e) {
                editorBasePathname = "";
            }

            function forceHideEmbeddedUi() {
                for (var i = 0; i < forceHideSelectors.length; i += 1) {
                    var nodes = document.querySelectorAll(forceHideSelectors[i]);
                    for (var j = 0; j < nodes.length; j += 1) {
                        nodes[j].style.setProperty("display", "none", "important");
                        if (nodes[j].id === "dropdownFile") {
                            var fileNavItem = nodes[j].closest("li.nav-item");
                            if (fileNavItem) {
                                fileNavItem.style.setProperty("display", "none", "important");
                            }
                            var fileMenu = document.querySelector("ul[aria-labelledby=\"dropdownFile\"]");
                            if (fileMenu) {
                                fileMenu.style.setProperty("display", "none", "important");
                            }
                        }
                    }
                }
            }

            function normalizePreviewIframeSrc(url) {
                if (!url || !editorBaseUrl) {
                    return url;
                }

                var baseNoSlash = editorBaseUrl.replace(/\/$/, "");
                var raw = url;

                try {
                    if (raw.startsWith("http://") || raw.startsWith("https://")) {
                        raw = new URL(raw).pathname;
                    }
                } catch (e) {}

                if (raw.indexOf("/wp-admin/admin.php/viewer/") === 0) {
                    return baseNoSlash + "/viewer/" + raw.substring("/wp-admin/admin.php/viewer/".length);
                }
                if (raw.indexOf("/viewer/") === 0) {
                    return baseNoSlash + raw;
                }
                if (raw.indexOf("viewer/") === 0) {
                    return baseNoSlash + "/" + raw;
                }

                return url;
            }

            function ensurePreviewIframeSrc() {
                var previewIframe = document.getElementById("preview-iframe");
                if (!previewIframe) {
                    return;
                }

                var currentSrc = previewIframe.getAttribute("src") || previewIframe.src || "";
                var fixedSrc = normalizePreviewIframeSrc(currentSrc);
                if (fixedSrc && fixedSrc !== currentSrc) {
                    previewIframe.setAttribute("src", fixedSrc);
                }
            }

            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", forceHideEmbeddedUi);
                document.addEventListener("DOMContentLoaded", ensurePreviewIframeSrc);
            } else {
                forceHideEmbeddedUi();
                ensurePreviewIframeSrc();
            }

            var hideObserver = new MutationObserver(function() {
                forceHideEmbeddedUi();
                ensurePreviewIframeSrc();
            });
            hideObserver.observe(document.documentElement || document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ["src"]
            });

            // Fix preview service worker paths in WP mode.
            if (originalServiceWorker && editorBasePathname) {
                var registerOriginal = originalServiceWorker.register.bind(originalServiceWorker);
                var getRegistrationOriginal = originalServiceWorker.getRegistration.bind(originalServiceWorker);
                var fixedSwPath = editorBasePathname.replace(/\/$/, "") + "/preview-sw.js";
                var fixedScope = editorBasePathname.replace(/\/$/, "") + "/viewer/";

                originalServiceWorker.register = function(scriptURL, options) {
                    var nextScript = scriptURL;
                    var nextOptions = options || {};
                    if (typeof nextScript === "string" && nextScript.indexOf("preview-sw.js") !== -1) {
                        nextScript = fixedSwPath;
                        nextOptions = Object.assign({}, nextOptions, { scope: fixedScope });
                    }
                    return registerOriginal(nextScript, nextOptions);
                };

                originalServiceWorker.getRegistration = function(clientURL) {
                    var nextClientUrl = clientURL;
                    if (
                        !nextClientUrl ||
                        (typeof nextClientUrl === "string" && nextClientUrl.indexOf("/wp-admin/") === 0)
                    ) {
                        nextClientUrl = fixedScope;
                    }
                    return getRegistrationOriginal(nextClientUrl);
                };
            }

            function normalizeEditorAssetUrl(url) {
                if (!url || typeof url !== "string" || !editorBaseUrl) {
                    return url;
                }

                if (
                    url.startsWith("data:") ||
                    url.startsWith("blob:") ||
                    url.startsWith("http://") ||
                    url.startsWith("https://")
                ) {
                    return url;
                }

                var baseNoSlash = editorBaseUrl.replace(/\/$/, "");
                var wpAdminPrefix = "/wp-admin/admin.php/";
                if (url.indexOf(wpAdminPrefix) === 0) {
                    return baseNoSlash + "/" + url.substring(wpAdminPrefix.length);
                }

                var cleanUrl = url.replace(/^\.\//, "");
                if (
                    cleanUrl.startsWith("files/") ||
                    cleanUrl.startsWith("libs/") ||
                    cleanUrl.startsWith("app/") ||
                    cleanUrl.startsWith("style/") ||
                    cleanUrl.startsWith("images/") ||
                    cleanUrl === "CHANGELOG.md" ||
                    cleanUrl === "LICENSES.md" ||
                    cleanUrl === "README.md"
                ) {
                    return baseNoSlash + "/" + cleanUrl;
                }

                return url;
            }

            var originalFetch = window.fetch;
            window.fetch = function(input, init) {
                var url = typeof input === "string" ? input : (input && input.url) || "";
                var method = (init && init.method) || (input && input.method) || "GET";

                // Silently ignore cleanup-import DELETE requests (not supported in WP mode).
                if (method.toUpperCase() === "DELETE" && url.indexOf("cleanup-import") !== -1) {
                    return Promise.resolve(new Response("{}", { status: 200, headers: { "Content-Type": "application/json" } }));
                }

                var normalizedUrl = normalizeEditorAssetUrl(url);
                var fetchInput = input;
                if (typeof input === "string") {
                    fetchInput = normalizedUrl;
                } else if (input && input.url && normalizedUrl !== input.url) {
                    fetchInput = new Request(normalizedUrl, input);
                }

                return originalFetch.call(this, fetchInput, init).then(function(response) {
                    if (!response.ok && (url.includes(".css") || url.includes("idevices"))) {
                        console.warn("[WP Mode] Fetch 404 fallback:", url);
                        return new Response("/* empty fallback */", {
                            status: 200,
                            headers: { "Content-Type": "text/css" }
                        });
                    }
                    return response;
                }).catch(function(error) {
                    if (url.includes(".css") || url.includes("idevices")) {
                        console.warn("[WP Mode] Fetch error fallback:", url);
                        return new Response("/* empty fallback */", {
                            status: 200,
                            headers: { "Content-Type": "text/css" }
                        });
                    }
                    throw error;
                });
            };

            // Patch jQuery AJAX to handle 404s for CSS/idevice files (Playground compat).
            // Uses ajaxTransport to intercept at the XHR level and report 200 to jQuery,
            // so the deferred/promise resolves instead of rejecting.
            var patchJQuery = function($) {
                if (!$ || !$.ajaxTransport) return;
                $.ajaxTransport("+*", function(options) {
                    var url = options.url || "";
                    var normalizedUrl = normalizeEditorAssetUrl(url);
                    if (!(url.includes(".css") || url.includes("idevices"))) return;
                    return {
                        send: function(headers, completeCallback) {
                            var xhr = new XMLHttpRequest();
                            xhr.open(options.type || "GET", normalizedUrl, true);
                            xhr.onload = function() {
                                if (xhr.status >= 200 && xhr.status < 300) {
                                    completeCallback(xhr.status, xhr.statusText, { text: xhr.responseText });
                                } else {
                                    console.warn("[WP Mode] jQuery 404 fallback:", url);
                                    completeCallback(200, "OK", { text: "/* empty fallback */" });
                                }
                            };
                            xhr.onerror = function() {
                                console.warn("[WP Mode] jQuery error fallback:", url);
                                completeCallback(200, "OK", { text: "/* empty fallback */" });
                            };
                            xhr.send();
                        },
                        abort: function() {}
                    };
                });
            };
            if (window.jQuery) {
                patchJQuery(window.jQuery);
            } else {
                // jQuery may load after this script; patch when ready.
                Object.defineProperty(window, "jQuery", {
                    configurable: true,
                    set: function(val) {
                        Object.defineProperty(window, "jQuery", {
                            configurable: true, writable: true, enumerable: true, value: val
                        });
                        patchJQuery(val);
                    },
                    get: function() { return undefined; }
                });
            }
        })();
    </script>
    <script src="%s/js/wp-exe-bridge.js"></script>
',
	$attachment_id,
	wp_json_encode( $elp_url ),
	wp_json_encode( 'wp-attachment-' . $attachment_id ),
	wp_json_encode( $rest_url ),
	wp_json_encode( $nonce ),
	wp_json_encode( $locale_short ),
	wp_json_encode( $user_name ),
	$user_id,
	wp_json_encode( $editor_base_url ),
	wp_json_encode( $i18n ),
	esc_url( $plugin_assets_url )
);
// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

// WordPress-specific styles.
$page_styles = '
    <!-- WordPress-specific styles -->
    <style>
        /* WordPress-specific overrides */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        /* WordPress notification */
        .wp-exe-notification {
            position: fixed;
            top: 60px;
            right: 10px;
            z-index: 10001;
            padding: 12px 20px;
            border-radius: 4px;
            font-size: 14px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: opacity 0.3s ease;
        }
        .wp-exe-notification--success {
            background: #00a32a;
            color: white;
        }
        .wp-exe-notification--error {
            background: #d63638;
            color: white;
        }
        .wp-exe-notification--fade {
            opacity: 0;
        }

        /* Moodle-like embedded mode: hide File menu, top Save and user/profile menu. */
        #dropdownFile,
        #head-top-save-button,
        #head-bottom-user-logged,
        #exe-concurrent-users,
        #mobile-navbar-button-save,
        #mobile-navbar-button-openuserodefiles {
            display: none !important;
        }
    </style>
';

// Insert config script and styles before </head>.
$template = str_replace( '</head>', $wp_config_script . $page_styles . '</head>', $template );

// Add <base> tag to set the base URL for all relative paths.
// This ensures paths like "files/perm/..." resolve to the static editor directory.
$base_tag = sprintf( '<base href="%s/">', esc_url( $editor_base_url ) );
$template = preg_replace( '/(<head[^>]*>)/i', '$1' . $base_tag, $template );

// Fix asset paths: Replace relative paths with absolute plugin paths.
// The static build uses relative paths like "./app/", we need absolute paths.
// Note: The <base> tag handles most paths, but explicit "./" paths in attributes need fixing.
$template = preg_replace(
	'/(?<=["\'])\.\//',
	esc_url( $editor_base_url ) . '/',
	$template
);

// Send proper headers.
if ( ! headers_sent() ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
}

// Output the processed template.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $template;
