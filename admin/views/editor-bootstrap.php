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

// All top-level variables in this template are prefixed with `exelearning_`
// to satisfy WordPress.NamingConventions.PrefixAllGlobals (Plugin Check).

// Ensure clean output - discard any previous output/warnings.
while ( ob_get_level() > 0 ) {
	ob_end_clean();
}

// Get parameters - nonce verification is done in ExeLearning_Editor class before loading this template.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in class-exelearning-editor.php
$exelearning_attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;

// Get the ELP file URL and info.
$exelearning_elp_url      = '';
$exelearning_elp_filename = '';
if ( $exelearning_attachment_id ) {
	$exelearning_url = wp_get_attachment_url( $exelearning_attachment_id );
	if ( $exelearning_url ) {
		$exelearning_elp_url = $exelearning_url;
	}
	$exelearning_file = get_attached_file( $exelearning_attachment_id );
	if ( $exelearning_file ) {
		$exelearning_elp_filename = basename( $exelearning_file );
	}
}

// Get attachment title (ensure it's never null).
$exelearning_page_title = get_the_title( $exelearning_attachment_id );
if ( empty( $exelearning_page_title ) ) {
	$exelearning_page_title = $exelearning_elp_filename ? $exelearning_elp_filename : 'Untitled';
}

// Plugin assets URL.
$exelearning_plugin_assets_url = EXELEARNING_PLUGIN_URL . 'assets';

// REST API for saving.
$exelearning_rest_url = rest_url( 'exelearning/v1' );
$exelearning_nonce    = wp_create_nonce( 'wp_rest' );

// Get locale (ensure it's never null).
$exelearning_site_locale  = get_locale();
$exelearning_locale_short = $exelearning_site_locale ? substr( $exelearning_site_locale, 0, 2 ) : 'en';

// User data (ensure values are never null).
$exelearning_user_data = wp_get_current_user();
$exelearning_user_name = $exelearning_user_data->display_name ? $exelearning_user_data->display_name : 'User';
$exelearning_user_id   = $exelearning_user_data->ID ? $exelearning_user_data->ID : 0;

// Check if static editor exists locally.
$exelearning_static_index = EXELEARNING_PLUGIN_DIR . 'dist/static/index.html';

if ( ! file_exists( $exelearning_static_index ) ) {
	// Redirect to the installer screen instead of failing or loading remotely.
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'exelearning-settings',
				'editor-missing'    => '1',
				'return_attachment' => $exelearning_attachment_id,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

$exelearning_editor_base_url = EXELEARNING_PLUGIN_URL . 'dist/static';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$exelearning_template = file_get_contents( $exelearning_static_index );

if ( false === $exelearning_template || empty( $exelearning_template ) ) {
	wp_die(
		esc_html__( 'Failed to load eXeLearning editor template.', 'exelearning' ),
		esc_html__( 'Template Error', 'exelearning' ),
		array( 'response' => 500 )
	);
}

// Translations for JavaScript.
$exelearning_i18n = array(
	'saving'     => __( 'Saving...', 'exelearning' ),
	'saved'      => __( 'Saved to WordPress successfully', 'exelearning' ),
	'saveButton' => __( 'Save to WordPress', 'exelearning' ),
	'loading'    => __( 'Loading project...', 'exelearning' ),
	'error'      => __( 'Error', 'exelearning' ),
);

// Build the approved style registry that the static editor will consume
// via `window.eXeLearning.config.themeRegistryOverride`.
$exelearning_theme_registry_override = class_exists( 'ExeLearning_Styles_Service' )
	? ExeLearning_Styles_Service::build_theme_registry_override()
	: array(
		'disabledBuiltins'   => array(),
		'uploaded'           => array(),
		'blockImportInstall' => true,
		'fallbackTheme'      => 'base',
	);

$exelearning_preview_delete_url = rest_url(
	'exelearning/v1/preview-session/' . $exelearning_attachment_id . '/__PREVIEW_ID__'
);
$exelearning_preview_snapshot   = array(
	'managementUrl'    => rest_url( 'exelearning/v1/preview-session/' . $exelearning_attachment_id ),
	'servingBaseUrl'   => rest_url( 'exelearning/v1/preview/' ),
	'deleteUrlTemplate' => str_replace( '__PREVIEW_ID__', '{previewId}', $exelearning_preview_delete_url ),
	'managementHeaders' => array( 'X-WP-Nonce' => $exelearning_nonce ),
);

// Inject WordPress configuration BEFORE the closing </head> tag.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML page output, not a WordPress template.
$exelearning_wp_config_script = sprintf(
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

        // Approved style registry for the embedded editor. The editor
        // merges disabledBuiltins/uploaded at bundle load time and
        // refuses any install-from-content path while blockImportInstall
        // is truthy. See exelearning/exelearning#1722.
        //
        // `userStyles` is the pre-existing ONLINE_THEMES_INSTALL flag the
        // editor consults before showing the "install this project theme"
        // modal. We mirror blockImportInstall onto it so the modal is also
        // suppressed end-to-end.
        //
        // The static editor boot sequence repeatedly reassigns
        // `window.eXeLearning` and `window.eXeLearning.config` (the inline
        // script in index.html resets the whole object, and app.bundle.js
        // later parses `config` from a JSON string back into an object).
        // We trap both assignments so our override survives every reset.
        (function() {
            var OVERRIDE = %s;
            function injectConfig(cfg) {
                if (!cfg || typeof cfg !== "object" || Array.isArray(cfg)) return cfg;
                cfg.themeRegistryOverride = OVERRIDE;
                cfg.userStyles = OVERRIDE && OVERRIDE.blockImportInstall ? 0 : 1;
                return cfg;
            }
            function trapConfig(target) {
                if (!target || typeof target !== "object") return;
                var stored = injectConfig(target.config);
                try {
                    Object.defineProperty(target, "config", {
                        configurable: true,
                        enumerable: true,
                        get: function() { return stored; },
                        set: function(v) { stored = injectConfig(v); }
                    });
                } catch (e) {
                    target.config = stored;
                }
            }
            var rootValue = window.eXeLearning;
            trapConfig(rootValue);
            try {
                Object.defineProperty(window, "eXeLearning", {
                    configurable: true,
                    get: function() { return rootValue; },
                    set: function(v) { rootValue = v; trapConfig(v); }
                });
            } catch (e) {
                window.eXeLearning = rootValue || {};
                trapConfig(window.eXeLearning);
            }
        })();

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
            previewSnapshot: %s,
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

                // The editor always computes asset paths as
                // `symfonyURL + theme.url`. For admin-uploaded styles served
                // from an absolute URL (e.g. /wp-content/uploads/...),
                // that concatenation produces `<editorBaseUrl><absolute>`.
                // Detect a second `http(s)://` inside the URL and strip
                // the editor prefix so the absolute URL is used verbatim.
                var secondScheme = url.indexOf("http://", 8);
                if (secondScheme < 0) {
                    secondScheme = url.indexOf("https://", 8);
                }
                if (secondScheme > 0) {
                    return url.substring(secondScheme);
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
	$exelearning_attachment_id,
	wp_json_encode( $exelearning_elp_url ),
	wp_json_encode( 'wp-attachment-' . $exelearning_attachment_id ),
	wp_json_encode( $exelearning_rest_url ),
	wp_json_encode( $exelearning_nonce ),
	wp_json_encode( $exelearning_locale_short ),
	wp_json_encode( $exelearning_user_name ),
	$exelearning_user_id,
	wp_json_encode( $exelearning_editor_base_url ),
	wp_json_encode( $exelearning_i18n ),
	wp_json_encode( $exelearning_theme_registry_override ),
	wp_json_encode( $exelearning_preview_snapshot ),
	esc_url( $exelearning_plugin_assets_url )
);
// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

// WordPress-specific styles.
$exelearning_page_styles = '
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
$exelearning_template = str_replace( '</head>', $exelearning_wp_config_script . $exelearning_page_styles . '</head>', $exelearning_template );

// Add <base> tag to set the base URL for all relative paths.
// This ensures paths like "files/perm/..." resolve to the static editor directory.
$exelearning_base_tag = sprintf( '<base href="%s/">', esc_url( $exelearning_editor_base_url ) );
$exelearning_template = preg_replace( '/(<head[^>]*>)/i', '$1' . $exelearning_base_tag, $exelearning_template );

// Fix asset paths: Replace relative paths with absolute plugin paths.
// The static build uses relative paths like "./app/", we need absolute paths.
// Note: The <base> tag handles most paths, but explicit "./" paths in attributes need fixing.
$exelearning_template = preg_replace(
	'/(?<=["\'])\.\//',
	esc_url( $exelearning_editor_base_url ) . '/',
	$exelearning_template
);

// Send proper headers.
if ( ! headers_sent() ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
}

// Output the processed template.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $exelearning_template;
