/**
 * Lightweight bridge for WordPress embedded editor page.
 *
 * The parent modal orchestrates all OPEN_FILE / REQUEST_EXPORT / EXPORT_FILE
 * protocol calls. This script only reports readiness and keyboard shortcuts.
 *
 * @package Exelearning
 */
( function() {
	'use strict';

	const config = window.__WP_EXE_CONFIG__ || {};
	const targetOrigin = window.__EXE_EMBEDDING_CONFIG__?.parentOrigin || '*';
	const rawCapabilities = [ 'WP_REQUEST_SAVE', 'WP_REQUEST_EXPORT', 'GET_PROJECT_INFO', 'CONFIGURE' ];
	let documentLoadedNotified = false;

	function notifyParent( type, data ) {
		if ( window.parent && window.parent !== window ) {
			window.parent.postMessage(
				{
					source: 'wp-exe-editor',
					type,
					data: data || {},
				},
				targetOrigin
			);
		}
	}

	function postProtocolMessage( message ) {
		if ( window.parent && window.parent !== window ) {
			window.parent.postMessage( message, targetOrigin );
		}
	}

	async function getApp( timeoutMs ) {
		const timeout = typeof timeoutMs === 'number' ? timeoutMs : 15000;
		const start = Date.now();
		while ( Date.now() - start < timeout ) {
			const app = window.eXeLearning?.app;
			if ( app ) {
				return app;
			}
			await new Promise( function( resolve ) {
				setTimeout( resolve, 100 );
			} );
		}
		throw new Error( 'App not ready' );
	}

	/**
	 * Wait for the lazily-loaded exporters bundle to publish window.SharedExporters.
	 *
	 * exporters.bundle.js is loaded by yjs-loader.js in a later group than the
	 * one that signals editor readiness, so an export requested right after
	 * EXELEARNING_READY/DOCUMENT_LOADED can arrive before the bundle is parsed.
	 * Poll (mirroring getApp) instead of failing on the first miss.
	 *
	 * @param {number} [timeoutMs] Max time to wait in milliseconds.
	 * @return {Promise<Object>} The window.SharedExporters object.
	 */
	async function waitForSharedExporters( timeoutMs ) {
		const timeout = typeof timeoutMs === 'number' ? timeoutMs : 15000;
		const start = Date.now();
		while ( Date.now() - start < timeout ) {
			if ( window.SharedExporters?.quickExport ) {
				return window.SharedExporters;
			}
			await new Promise( function( resolve ) {
				setTimeout( resolve, 100 );
			} );
		}
		throw new Error( 'SharedExporters bundle not loaded' );
	}

	async function exportToBytes() {
		const app = await getApp();
		const project = app.project;
		let blob;
		let filename = 'project.elpx';

		// Prefer SharedExporters + Yjs bridge in embedded WP mode because it
		// includes asset blobs from the active AssetManager reliably. Wait for
		// the lazily-loaded bundle so the first save/export does not race it.
		const hasYjsBridge = !! project?._yjsBridge?.documentManager;
		let sharedExporters = null;
		if ( hasYjsBridge ) {
			try {
				sharedExporters = await waitForSharedExporters();
			} catch ( error ) {
				sharedExporters = null;
			}
		}

		if (
			sharedExporters?.createExporter &&
			project?._yjsBridge?.documentManager
		) {
			const yjsBridge = project._yjsBridge;
			const exporter = sharedExporters.createExporter(
				'elpx',
				yjsBridge.documentManager,
				yjsBridge.assetCache || null,
				yjsBridge.resourceFetcher || null,
				yjsBridge.assetManager || null
			);
			const result = await exporter.export( {} );
			if ( ! result?.success || ! result?.data ) {
				throw new Error( result?.error || 'Export failed' );
			}

			blob = new Blob( [ result.data ], { type: 'application/zip' } );
			filename = result.filename || filename;
		} else if ( project && typeof project.exportToElpxBlob === 'function' ) {
			blob = await project.exportToElpxBlob();
			filename = project.getExportFilename?.() || filename;
		} else if ( project?._yjsBridge?.exporter ) {
			blob = await project._yjsBridge.exporter.exportToBlob();
			filename = project._yjsBridge.exporter.buildFilename?.() || filename;
		} else {
			throw new Error( 'Export not available' );
		}

		const bytes = await blob.arrayBuffer();
		return {
			bytes,
			filename,
			mimeType: blob.type || 'application/zip',
		};
	}

	/**
	 * Wait for the Yjs document manager to be available.
	 *
	 * An export can be requested right after EXELEARNING_READY, before the
	 * project finishes loading, so poll for the documentManager rather than
	 * failing immediately.
	 *
	 * @param {number} [timeoutMs] Max time to wait in milliseconds.
	 * @return {Promise<Object>} The Yjs bridge whose documentManager is ready.
	 */
	async function waitForDocumentManager( timeoutMs ) {
		const timeout = typeof timeoutMs === 'number' ? timeoutMs : 15000;
		const start = Date.now();
		while ( Date.now() - start < timeout ) {
			const app = window.eXeLearning?.app;
			const yjsBridge = app?.project?._yjsBridge;
			if ( yjsBridge?.documentManager ) {
				return yjsBridge;
			}
			await new Promise( function( resolve ) {
				setTimeout( resolve, 100 );
			} );
		}
		throw new Error( 'Document not ready' );
	}

	async function exportFormat( format ) {
		await getApp();
		// Wait for the lazily-loaded exporters bundle and the project document
		// (throws after the timeout) so the first export does not race them.
		const sharedExporters = await waitForSharedExporters();
		const yjsBridge = await waitForDocumentManager();

		const result = await sharedExporters.quickExport(
			format,
			yjsBridge.documentManager,
			yjsBridge.assetCache || null,
			yjsBridge.resourceFetcher || null,
			{},
			yjsBridge.assetManager || null
		);
		if ( ! result?.success || ! result?.data ) {
			throw new Error( result?.error || 'Export failed' );
		}

		const mime = ( format === 'epub3' || format === 'epub' )
			? 'application/epub+zip'
			: 'application/zip';
		const blob = new Blob( [ result.data ], { type: mime } );
		const bytes = await blob.arrayBuffer();
		return {
			bytes,
			filename: result.filename || ( 'project.' + format ),
			mimeType: blob.type,
		};
	}

	async function getProjectInfo() {
		const app = await getApp();
		const project = app.project;
		const manager = project?._yjsBridge?.documentManager;
		if ( ! manager ) {
			throw new Error( 'No project loaded' );
		}

		const metadata = manager.getMetadata?.();
		const navigation = manager.getNavigation?.();
		return {
			projectId: project?._yjsBridge?.projectId || window.eXeLearning?.projectId || null,
			title: metadata?.get?.( 'title' ) || 'Untitled',
			author: metadata?.get?.( 'author' ) || '',
			description: metadata?.get?.( 'description' ) || '',
			language: metadata?.get?.( 'language' ) || 'en',
			theme: metadata?.get?.( 'theme' ) || 'base',
			pageCount: Array.isArray( navigation ) ? navigation.length : 0,
		};
	}

	function applyHideUI( hideUI ) {
		if ( ! hideUI || typeof hideUI !== 'object' ) {
			return;
		}
		const body = document.body;
		if ( ! body ) {
			return;
		}
		if ( hideUI.fileMenu ) {
			body.setAttribute( 'data-exe-hide-file-menu', 'true' );
		}
		if ( hideUI.saveButton ) {
			body.setAttribute( 'data-exe-hide-save', 'true' );
		}
		if ( hideUI.userMenu ) {
			body.setAttribute( 'data-exe-hide-user-menu', 'true' );
		}
	}

	async function notifyWhenDocumentLoaded() {
		try {
			const timeout = 30000;
			const start = Date.now();
			while ( Date.now() - start < timeout ) {
				const app = window.eXeLearning?.app;
				const manager = app?.project?._yjsBridge?.documentManager;
				if ( manager && ! documentLoadedNotified ) {
					documentLoadedNotified = true;
					postProtocolMessage( { type: 'DOCUMENT_LOADED' } );
					monitorDocumentChanges();
					return;
				}
				await new Promise( function( resolve ) {
					setTimeout( resolve, 150 );
				} );
			}
		} catch ( error ) {
			console.warn( '[WP-EXE Bridge] DOCUMENT_LOADED monitor failed:', error );
		}
	}

	function monitorDocumentChanges() {
		try {
			const app = window.eXeLearning?.app;
			const yjsBridge = app?.project?._yjsBridge;
			const dm = yjsBridge?.documentManager;
			const ydoc = dm?.ydoc;
			if ( ydoc && typeof ydoc.on === 'function' ) {
				let changeNotified = false;
				ydoc.on( 'update', function() {
					if ( ! changeNotified ) {
						changeNotified = true;
						postProtocolMessage( { type: 'DOCUMENT_CHANGED' } );
					}
				} );
			}
		} catch ( error ) {
			console.warn( '[WP-EXE Bridge] Change monitor failed:', error );
		}
	}

	async function handleProtocolMessage( event ) {
		const message = event?.data || {};
		if ( ! message.type || message.source === 'wp-exe-editor' ) {
			return;
		}

		try {
			switch ( message.type ) {
				case 'WP_REQUEST_SAVE': {
					const exported = await exportToBytes();
					postProtocolMessage( {
						type: 'WP_SAVE_FILE',
						requestId: message.requestId,
						bytes: exported.bytes,
						filename: exported.filename,
						mimeType: exported.mimeType,
						size: exported.bytes.byteLength,
					} );
					break;
				}

				case 'WP_REQUEST_EXPORT': {
					const format = String( message.data?.format || message.format || '' );
					if ( ! format ) {
						throw new Error( 'Missing export format' );
					}
					const exported = await exportFormat( format );
					postProtocolMessage( {
						type: 'WP_EXPORT_FILE',
						requestId: message.requestId,
						format,
						bytes: exported.bytes,
						filename: exported.filename,
						mimeType: exported.mimeType,
						size: exported.bytes.byteLength,
					} );
					break;
				}

				case 'GET_PROJECT_INFO': {
					const info = await getProjectInfo();
					postProtocolMessage( {
						type: 'PROJECT_INFO',
						requestId: message.requestId,
						...info,
					} );
					break;
				}

				case 'CONFIGURE':
					applyHideUI( message.data?.hideUI || {} );
					postProtocolMessage( {
						type: 'CONFIGURE_SUCCESS',
						requestId: message.requestId,
					} );
					break;

				case 'WP_SAVE_CONFIRMED': {
					const app = window.eXeLearning?.app;
					const manager = app?.project?._yjsBridge?.documentManager;
					if ( manager && typeof manager.markClean === 'function' ) {
						manager.markClean();
					}
					window.onbeforeunload = null;
					postProtocolMessage( {
						type: 'WP_SAVE_CONFIRMED_ACK',
						requestId: message.requestId,
					} );
					break;
				}
			}
		} catch ( error ) {
			const type = message.type || 'UNKNOWN';
			postProtocolMessage( {
				type: `${ type }_ERROR`,
				requestId: message.requestId,
				error: error?.message || 'Unknown error',
			} );
		}
	}

	async function init() {
		try {
			if ( window.eXeLearning?.ready ) {
				await window.eXeLearning.ready;
			}

			window.addEventListener( 'message', handleProtocolMessage );

			postProtocolMessage( {
				type: 'EXELEARNING_READY',
				version: window.eXeLearning?.version || 'unknown',
				capabilities: rawCapabilities,
			} );
			notifyWhenDocumentLoaded();

			notifyParent( 'editor-ready', {
				attachmentId: config.attachmentId || null,
			} );

			document.addEventListener( 'keydown', ( event ) => {
				if ( ( event.ctrlKey || event.metaKey ) && event.key === 's' ) {
					event.preventDefault();
					notifyParent( 'request-save' );
				}
			} );
		} catch ( error ) {
			console.error( '[WP-EXE Bridge] Initialization failed:', error );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.wpExeBridge = {
		config,
		requestSave() {
			notifyParent( 'request-save' );
		},
	};
} )();
