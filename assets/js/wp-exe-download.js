/**
 * Public frontend download orchestrator for eXeLearning embeds.
 *
 * Wires up the split-button rendered by ExeLearning_Download_Button_Renderer:
 *
 *   - `.elpx` items download the raw attachment URL directly.
 *   - Any other format triggers a client-side export by lazy-loading the
 *     static editor in a hidden iframe (served by ExeLearning_Export_Bootstrap)
 *     and asking it via postMessage to run `SharedExporters.quickExport()`.
 *
 * The editor iframe is reused across downloads within the same page and is
 * disposed of after a short idle period.
 *
 * @package Exelearning
 */
( function() {
	'use strict';

	var CONFIG = window.wpExeDownloadConfig || {};
	var EDITOR_BASE = CONFIG.editorUrl || '';
	var I18N = CONFIG.i18n || {};

	var iframeEl = null;
	var iframeReady = false;
	var iframeLoadedAttachment = null;
	var pendingExports = Object.create( null );
	var nextRequestId = 1;
	var disposeTimer = null;

	function l10n( key, fallback ) {
		return ( I18N[ key ] && String( I18N[ key ] ) ) || fallback;
	}

	function exportBootstrapUrl( attachmentId ) {
		// We never embed this URL in markup directly; build it on demand.
		// Build on the WordPress home URL (exportBase) so subdirectory installs
		// (e.g. /wp) resolve correctly; fall back to the origin root if absent.
		var base = CONFIG.exportBase || ( window.location.origin + '/' );
		var u = new URL( base, window.location.href );
		u.searchParams.set( 'exe_export', '1' );
		u.searchParams.set( 'attachment_id', String( attachmentId ) );
		return u.toString();
	}

	function ensureIframe( attachmentId ) {
		if ( iframeEl && iframeLoadedAttachment === attachmentId ) {
			return Promise.resolve();
		}

		disposeIframe();

		return new Promise( function( resolve, reject ) {
			iframeEl = document.createElement( 'iframe' );
			iframeEl.setAttribute( 'aria-hidden', 'true' );
			iframeEl.setAttribute( 'tabindex', '-1' );
			iframeEl.setAttribute( 'title', 'eXeLearning export worker' );
			iframeEl.style.cssText = 'position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;border:0;visibility:hidden;';
			iframeEl.src = exportBootstrapUrl( attachmentId );
			iframeLoadedAttachment = attachmentId;
			iframeReady = false;

			var readyHandler = function( event ) {
				var data = event.data || {};
				if ( data.type === 'EXELEARNING_READY' || data.type === 'DOCUMENT_LOADED' ) {
					if ( event.source === iframeEl.contentWindow ) {
						iframeReady = true;
						window.removeEventListener( 'message', readyHandler );
						resolve();
					}
				}
			};

			window.addEventListener( 'message', readyHandler );

			iframeEl.addEventListener( 'error', function() {
				window.removeEventListener( 'message', readyHandler );
				reject( new Error( 'iframe failed to load' ) );
			} );

			document.body.appendChild( iframeEl );

			setTimeout( function() {
				if ( ! iframeReady ) {
					window.removeEventListener( 'message', readyHandler );
					reject( new Error( 'export iframe timeout' ) );
				}
			}, 45000 );
		} );
	}

	function disposeIframe() {
		if ( iframeEl && iframeEl.parentNode ) {
			iframeEl.parentNode.removeChild( iframeEl );
		}
		iframeEl = null;
		iframeReady = false;
		iframeLoadedAttachment = null;
		pendingExports = Object.create( null );
	}

	function scheduleDispose() {
		if ( disposeTimer ) {
			clearTimeout( disposeTimer );
		}
		disposeTimer = setTimeout( disposeIframe, 60000 );
	}

	window.addEventListener( 'message', function( event ) {
		if ( ! iframeEl || event.source !== iframeEl.contentWindow ) {
			return;
		}
		var data = event.data || {};
		var requestId = data.requestId;
		if ( ! requestId || ! pendingExports[ requestId ] ) {
			return;
		}
		var pending = pendingExports[ requestId ];
		delete pendingExports[ requestId ];

		if ( data.type === 'WP_EXPORT_FILE' && data.bytes ) {
			pending.resolve( {
				bytes: data.bytes,
				filename: data.filename,
				mimeType: data.mimeType,
			} );
		} else if ( data.type === 'WP_REQUEST_EXPORT_ERROR' || data.error ) {
			pending.reject( new Error( data.error || 'Export failed' ) );
		}
	} );

	function requestExport( format ) {
		return new Promise( function( resolve, reject ) {
			if ( ! iframeEl || ! iframeReady || ! iframeEl.contentWindow ) {
				reject( new Error( 'Editor iframe not ready' ) );
				return;
			}
			var requestId = 'exe-' + ( nextRequestId++ );
			pendingExports[ requestId ] = { resolve: resolve, reject: reject };
			iframeEl.contentWindow.postMessage(
				{ type: 'WP_REQUEST_EXPORT', requestId: requestId, data: { format: format } },
				'*'
			);
			setTimeout( function() {
				if ( pendingExports[ requestId ] ) {
					delete pendingExports[ requestId ];
					reject( new Error( 'Export timed out' ) );
				}
			}, 120000 );
		} );
	}

	function triggerDownload( blob, filename ) {
		var url = URL.createObjectURL( blob );
		var link = document.createElement( 'a' );
		link.href = url;
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		setTimeout( function() {
			URL.revokeObjectURL( url );
		}, 1000 );
	}

	function linkDownload( url, filename ) {
		var link = document.createElement( 'a' );
		link.href = url;
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
	}

	function setBusy( container, busy ) {
		if ( ! container ) {
			return;
		}
		if ( busy ) {
			container.setAttribute( 'data-busy', '1' );
		} else {
			container.removeAttribute( 'data-busy' );
		}
	}

	function showStatus( container, message ) {
		var existing = container.querySelector( '.exelearning-download__status' );
		if ( ! message ) {
			if ( existing ) {
				existing.remove();
			}
			return;
		}
		if ( ! existing ) {
			existing = document.createElement( 'span' );
			existing.className = 'exelearning-download__status';
			container.appendChild( existing );
		}
		existing.textContent = message;
	}

	function closeAllMenus() {
		var open = document.querySelectorAll( '.exelearning-download__menu[data-open="1"]' );
		open.forEach( function( menu ) {
			menu.hidden = true;
			menu.removeAttribute( 'data-open' );
			var toggle = menu.parentNode && menu.parentNode.querySelector( '.exelearning-download__toggle' );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	/**
	 * Download a single format. Shared by the frontend delegated click handler
	 * and the Gutenberg edit-mode toolbar (window.wpExeDownload.downloadFormat).
	 *
	 * `.elpx` downloads the raw attachment directly; any other format runs a
	 * client-side export inside the hidden editor iframe.
	 *
	 * @param {Object}      params               Download parameters.
	 * @param {string}      params.format        Format id (e.g. 'elpx', 'scorm12').
	 * @param {string}      [params.suffix]      Filename suffix (e.g. '_scorm.zip').
	 * @param {number}      params.attachmentId  Attachment post ID.
	 * @param {string}      [params.elpUrl]      Raw attachment URL (for '.elpx').
	 * @param {string}      [params.slug]        Filename base.
	 * @param {Element}     [params.container]   Element used for busy/status UI.
	 * @return {Promise} Resolves when the download is triggered.
	 */
	function downloadFormat( params ) {
		params = params || {};
		var format = params.format;
		var suffix = params.suffix || '';
		var attachmentId = params.attachmentId;
		var elpUrl = params.elpUrl;
		var slug = params.slug || ( 'project-' + attachmentId );
		var container = params.container || null;

		if ( format === 'elpx' ) {
			// The .elpx is the uploaded attachment itself — no editor/export
			// needed. Prefer fetch + blob over a plain `<a download>` because a
			// download navigation can bypass the environment that serves the
			// upload (e.g. WordPress Playground serves uploads from a service
			// worker that a top-level download request never reaches, yielding
			// "the file isn't available on the site"). A page fetch goes through
			// that worker, so the blob download works everywhere; fall back to a
			// direct link if fetch is unavailable or fails.
			var elpxName = slug + suffix;
			if ( ! elpUrl ) {
				return Promise.reject( new Error( 'Missing .elpx URL' ) );
			}
			if ( typeof window.fetch !== 'function' ) {
				linkDownload( elpUrl, elpxName );
				return Promise.resolve();
			}
			setBusy( container, true );
			return window.fetch( elpUrl, { credentials: 'same-origin' } )
				.then( function( resp ) {
					if ( ! resp.ok ) {
						throw new Error( 'HTTP ' + resp.status );
					}
					return resp.blob();
				} )
				.then( function( blob ) {
					triggerDownload( blob, elpxName );
				} )
				.catch( function() {
					// Last resort: let the browser try the direct attachment URL.
					linkDownload( elpUrl, elpxName );
				} )
				.finally( function() {
					setBusy( container, false );
				} );
		}

		if ( ! attachmentId || ! EDITOR_BASE ) {
			console.error( '[wp-exe-download] Missing attachment id or editor URL' );
			return Promise.reject( new Error( 'Missing attachment id or editor URL' ) );
		}

		setBusy( container, true );

		return ensureIframe( attachmentId )
			.then( function() {
				return requestExport( format );
			} )
			.then( function( result ) {
				var mime = result.mimeType || ( format === 'epub3' ? 'application/epub+zip' : 'application/zip' );
				var blob = new Blob( [ result.bytes ], { type: mime } );
				triggerDownload( blob, slug + suffix );
			} )
			.catch( function( err ) {
				console.error( '[wp-exe-download]', err );
				if ( container ) {
					showStatus( container, l10n( 'failed', 'Download failed. Please try again.' ) );
					setTimeout( function() { showStatus( container, '' ); }, 4000 );
				}
				throw err;
			} )
			.finally( function() {
				setBusy( container, false );
				if ( container && ! container.querySelector( '.exelearning-download__status' ) ) {
					showStatus( container, '' );
				}
				scheduleDispose();
			} );
	}

	function onClick( event ) {
		var target = event.target.closest( '[data-format]' );
		var toggle = event.target.closest( '.exelearning-download__toggle' );
		var container = event.target.closest( '.exelearning-download' );

		if ( toggle && container ) {
			event.preventDefault();
			var menu = container.querySelector( '.exelearning-download__menu' );
			if ( ! menu ) {
				return;
			}
			var isOpen = menu.getAttribute( 'data-open' ) === '1';
			closeAllMenus();
			if ( ! isOpen ) {
				menu.hidden = false;
				menu.setAttribute( 'data-open', '1' );
				toggle.setAttribute( 'aria-expanded', 'true' );
			}
			return;
		}

		if ( ! target || ! container ) {
			closeAllMenus();
			return;
		}

		var format = target.getAttribute( 'data-format' );
		var suffix = target.getAttribute( 'data-suffix' ) || '';
		var attachmentId = parseInt( container.getAttribute( 'data-attachment-id' ), 10 );
		var elpUrl = container.getAttribute( 'data-elp-url' );
		var slug = container.getAttribute( 'data-slug' ) || ( 'project-' + attachmentId );
		closeAllMenus();
		event.preventDefault();

		downloadFormat( {
			format: format,
			suffix: suffix,
			attachmentId: attachmentId,
			elpUrl: elpUrl,
			slug: slug,
			container: container,
		} );
	}

	// Public API so the block editor can reuse the exact same export pipeline.
	window.wpExeDownload = {
		downloadFormat: downloadFormat,
	};

	function init() {
		document.addEventListener( 'click', onClick );
		document.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Escape' ) {
				closeAllMenus();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
