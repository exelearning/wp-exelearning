// Unit tests for assets/js/exelearning-media-modal.js.
//
// The script decorates the Media Library: it swaps .elpx thumbnails for scaled live
// previews, fills the attachment details panel with metadata, and adds the "Edit in
// eXeLearning" and "Process as eXeLearning" buttons. All of it is built by string
// concatenation and injected with jQuery .html()/.after(), which is why esc() exists
// and why a good part of this file is about it: an attachment title or a piece of
// .elpx metadata is attacker-controlled -- a contributor who can upload gets to choose
// both -- and it lands in markup that runs in the reviewing administrator's session.
//
// The rest is idempotence and failure handling. The script is re-run by a
// MutationObserver, by the modal's `open` event and by four timers, so every function
// has a "already did this" guard; if one of those guards is wrong the panel grows a
// duplicate button on every mouse move. And the reprocess call must put the button
// back and say what happened rather than leaving a disabled control behind.
//
// The script is a jQuery ready callback with no exports, so each test imports a fresh
// copy under a cache-busting URL. Two things it touches are global and outlive the
// module: jQuery's document handlers (dropped with $(document).off()) and the
// MutationObserver, which is stubbed here so a stale instance cannot re-run updates
// during a later test -- and so a test can decide when a re-render happens.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const SCRIPT = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/exelearning-media-modal.js' )
).href;

// WordPress puts jQuery on the page as a global; the script closes over it by name.
const $ = require( 'jquery' );
global.jQuery = $;
global.$ = $;

let loadCount = 0;
/** Attachments wp.media.attachment() will hand out, by id. */
let attachments = new Map();
/** MutationObserver instances the script created. */
let observers = [];
/** Handlers the script bound to the media modal's `open` event. */
let modalOpenHandlers = [];
/** Requests made through the stubbed fetch. */
let requests = [];
/** Windows opened through the stubbed window.open. */
let openedWindows = [];

let originalMutationObserver;
let originalWindowOpen;

const STRINGS = {
	info: 'eXeLearning Info',
	version: 'Versión:',
	license: 'Licencia:',
	language: 'Idioma:',
	type: 'Tipo:',
	editInExe: 'Editar en eXeLearning',
	previewNewTab: 'Vista previa en pestaña nueva',
	noPreview: 'Sin vista previa',
	noPreviewDesc: 'Es un archivo fuente v2.',
	processAsExe: 'Procesar como eXeLearning',
	processing: 'Procesando…',
	processFailed: 'No se pudo procesar este archivo.',
	notProcessed: 'Archivo eXeLearning (sin procesar)',
	sourceFile: '(archivo fuente)',
	exported: '(exportado)',
};

beforeAll( () => {
	originalMutationObserver = window.MutationObserver;
	originalWindowOpen = window.open;
} );

afterAll( () => {
	window.MutationObserver = originalMutationObserver;
	global.MutationObserver = originalMutationObserver;
	window.open = originalWindowOpen;
} );

beforeEach( () => {
	attachments = new Map();
	observers = [];
	modalOpenHandlers = [];
	requests = [];
	openedWindows = [];

	vi.useFakeTimers();

	// Inert by default: the real observer would re-run every update on each of the
	// script's own DOM writes, and a stale one would keep firing in later tests.
	class StubMutationObserver {
		constructor( callback ) {
			this.callback = callback;
			this.records = [];
			observers.push( this );
		}
		observe( target, options ) {
			this.target = target;
			this.options = options;
		}
		disconnect() {
			this.disconnected = true;
		}
		/** Test hook: report a DOM change to this observer. */
		trigger() {
			this.callback( [], this );
		}
	}
	window.MutationObserver = StubMutationObserver;
	global.MutationObserver = StubMutationObserver;

	window.open = ( url, target, features ) => {
		openedWindows.push( { url, target, features } );
		return null;
	};

	global.wp = {
		media: {
			attachment: ( id ) => attachmentFor( id ),
			frame: null,
			view: {
				Modal: {
					prototype: {
						on: ( event, handler ) => modalOpenHandlers.push( { event, handler } ),
					},
				},
			},
		},
	};

	window.exelearningMediaStrings = { ...STRINGS };
	window.exelearningMediaSettings = {
		restUrl: '/wp-json/exelearning/v1',
		nonce: 'rest-nonce-123',
	};
} );

afterEach( () => {
	// jQuery keeps one native listener per event type on document, so a handler bound
	// by a previous copy of the script would still fire during the next test.
	$( document ).off();
	vi.useRealTimers();
	document.body.innerHTML = '';
	delete global.wp;
	delete window.exelearningMediaStrings;
	delete window.exelearningMediaSettings;
	delete window.ExeLearningEditor;
	delete window.fetch;
	// Some tests park an ?item= on the URL for the id-resolution fallbacks.
	window.history.replaceState( {}, '', '/wp-admin/upload.php' );
} );

/**
 * Build a Backbone-ish attachment model of the shape wp.media hands out.
 *
 * @param {number} id    Attachment id, or 0 for a model that has not loaded yet.
 * @param {Object} attrs Prepared attachment attributes.
 * @return {Object} The stub model.
 */
function makeAttachment( id, attrs ) {
	const data = { ...attrs };
	if ( id ) {
		data.id = id;
	}

	const model = {
		data,
		fetchCount: 0,
		get: ( key ) => data[ key ],
		set: ( key, value ) => {
			data[ key ] = value;
		},
		fetch() {
			model.fetchCount++;
			const deferred = $.Deferred();
			model.settleFetch = ( loaded ) => {
				Object.assign( data, loaded || {} );
				deferred.resolve();
			};
			model.failFetch = () => deferred.reject();
			return deferred.promise();
		},
	};

	return model;
}

/** Register the model wp.media.attachment(id) will return. */
function registerAttachment( id, attrs ) {
	const model = makeAttachment( id, attrs );
	attachments.set( String( id ), model );
	return model;
}

/**
 * wp.media.attachment(): a model that has never been seen is one that has not
 * loaded yet, which is what sends the script down its fetch path.
 *
 * @param {number|string} id Attachment id.
 * @return {Object} The stub model.
 */
function attachmentFor( id ) {
	const key = String( id );
	if ( ! attachments.has( key ) ) {
		attachments.set( key, makeAttachment( 0, {} ) );
	}
	return attachments.get( key );
}

/** Metadata of a fully processed v3 .elpx with a live preview. */
function previewMetadata( overrides ) {
	return {
		version: 3,
		has_preview: true,
		preview_url: 'http://example.test/uploads/exelearning/abc123/index.html',
		...overrides,
	};
}

/** Import a fresh copy of the script and let its ready callback run. */
async function loadScript() {
	await import( /* @vite-ignore */ `${ SCRIPT }?load=${ ++loadCount }` );
	await settle();
}

/** Flush the script's promise chains and any timer due right now. */
async function settle() {
	for ( let i = 0; i < 6; i++ ) {
		await Promise.resolve();
		await vi.advanceTimersByTimeAsync( 0 );
	}
}

/** The grid markup the media library renders for one attachment tile. */
function gridMarkup( attachmentId ) {
	return (
		'<li class="attachment" data-id="' + attachmentId + '">' +
			'<div class="attachment-preview type-application">' +
				'<div class="thumbnail"><div class="centered"><img alt=""></div></div>' +
			'</div>' +
		'</li>'
	);
}

/** The single-column attachment details markup. */
function detailsMarkup() {
	return (
		'<div class="attachment-details">' +
			'<div class="thumbnail"><img alt=""></div>' +
		'</div>'
	);
}

/** The two-column attachment-info markup, with an actions row. */
function attachmentInfoMarkup( extra ) {
	return (
		'<div class="attachment-details">' +
			'<div class="attachment-info">' +
				'<div class="actions">' + ( extra || '' ) + '</div>' +
			'</div>' +
		'</div>'
	);
}

/** Install a fetch stub that answers the reprocess endpoint. */
function stubFetch( response ) {
	window.fetch = ( url, options ) => {
		requests.push( { url, options } );
		if ( response.networkError ) {
			return Promise.reject( new Error( 'offline' ) );
		}
		return Promise.resolve( {
			ok: response.ok !== false,
			json: () => Promise.resolve( response.body || {} ),
		} );
	};
}

describe( 'exelearning-media-modal: escaping attacker-controlled text', () => {
	// A contributor who can upload chooses the filename and the .elpx metadata. Both
	// are concatenated into markup and injected with .html()/.after(). If esc() ever
	// stops escaping, this is stored XSS running as whoever opens the Media Library.

	it( 'escapes the filename in the grid overlay instead of running it', async () => {
		registerAttachment( 42, {
			exelearning: previewMetadata(),
			filename: '<img src=x onerror="window.__pwned = true">.elpx',
		} );
		document.body.innerHTML = gridMarkup( 42 );

		await loadScript();
		await vi.advanceTimersByTimeAsync( 50 );

		const overlay = document.querySelector( '.exelearning-filename-overlay' );
		expect( overlay.querySelector( 'img' ) ).toBeNull();
		expect( overlay.textContent ).toBe( '<img src=x onerror="window.__pwned = true">.elpx' );
		expect( window.__pwned ).toBeUndefined();
	} );

	it( 'escapes the version badge on a file with no preview', async () => {
		registerAttachment( 43, {
			exelearning: { version: '<script>window.__pwned = true</script>', has_preview: false },
		} );
		document.body.innerHTML = gridMarkup( 43 );

		await loadScript();

		const badge = document.querySelector( '.exelearning-version-badge' );
		expect( badge.querySelector( 'script' ) ).toBeNull();
		expect( badge.textContent ).toContain( '<script>window.__pwned = true</script>' );
		expect( window.__pwned ).toBeUndefined();
	} );

	it( 'renders an absent value as nothing rather than the word undefined', async () => {
		registerAttachment( 45, {
			exelearning: previewMetadata(),
			filename: undefined,
			title: undefined,
		} );
		document.body.innerHTML = gridMarkup( 45 );

		await loadScript();
		await vi.advanceTimersByTimeAsync( 50 );

		expect(
			document.querySelector( '.exelearning-filename-overlay' ).textContent
		).toBe( '' );
	} );
} );

/** A wp.media.frame whose current selection is the given attachment. */
function frameSelecting( id ) {
	return {
		state: () => ( {
			get: ( what ) =>
				'selection' === what
					? { first: () => attachmentFor( id ) }
					: null,
		} ),
	};
}

describe( 'exelearning-media-modal: the grid thumbnail', () => {
	it( 'replaces the thumbnail with a scaled, sandboxed preview iframe', async () => {
		registerAttachment( 50, { exelearning: previewMetadata(), filename: 'curso.elpx' } );
		document.body.innerHTML = gridMarkup( 50 );

		await loadScript();
		await vi.advanceTimersByTimeAsync( 50 );

		const iframe = document.querySelector( '.exelearning-preview-wrapper iframe' );
		expect( iframe.getAttribute( 'sandbox' ) ).toBe( 'allow-scripts allow-same-origin' );
		expect( iframe.getAttribute( 'referrerpolicy' ) ).toBe( 'no-referrer' );
		expect( iframe.getAttribute( 'src' ) ).toContain( '_cb=' );
		expect( document.querySelector( '.attachment' ).classList )
			.toContain( 'exelearning-attachment' );
	} );

	it( 'appends the cache buster with & when the preview URL already has a query', async () => {
		registerAttachment( 51, {
			exelearning: previewMetadata( {
				preview_url: 'http://example.test/preview.html?v=2',
			} ),
		} );
		document.body.innerHTML = gridMarkup( 51 );

		await loadScript();
		await vi.advanceTimersByTimeAsync( 50 );

		const src = document.querySelector( '.exelearning-preview-wrapper iframe' ).getAttribute( 'src' );
		expect( src ).toMatch( /\?v=2&_cb=\d+$/ );
	} );

	it( 'shows a source-file badge instead of a preview for a v2 file', async () => {
		registerAttachment( 52, { exelearning: { version: 2, has_preview: false } } );
		document.body.innerHTML = gridMarkup( 52 );

		await loadScript();

		expect( document.querySelector( '.exelearning-version-badge' ).textContent )
			.toBe( 'eXe v2 (source)' );
		expect( document.querySelector( '.thumbnail' ).classList )
			.toContain( 'exelearning-no-preview' );
	} );

	it( 'does not decorate the same thumbnail twice', async () => {
		registerAttachment( 53, { exelearning: previewMetadata(), filename: 'curso.elpx' } );
		document.body.innerHTML = gridMarkup( 53 );

		await loadScript();
		await vi.advanceTimersByTimeAsync( 50 );
		// The observer fires on the script's own DOM writes; without the guard this
		// is where the preview would be rebuilt on every mutation.
		observers[ 0 ].trigger();
		await vi.advanceTimersByTimeAsync( 50 );

		expect( document.querySelectorAll( '.exelearning-preview-wrapper' ) ).toHaveLength( 1 );
	} );

	it( 'leaves attachments that are not eXeLearning files alone', async () => {
		registerAttachment( 54, { filename: 'documento.pdf' } );
		document.body.innerHTML = gridMarkup( 54 );

		await loadScript();
		await vi.advanceTimersByTimeAsync( 50 );

		expect( document.querySelector( '.exelearning-preview-wrapper' ) ).toBeNull();
		expect( document.querySelector( '.attachment' ).classList )
			.not.toContain( 'exelearning-attachment' );
	} );

	it( 'leaves a tile with no attachment id alone', async () => {
		document.body.innerHTML =
			'<li class="attachment"><div class="attachment-preview type-application">' +
			'<div class="thumbnail"><div class="centered"></div></div></div></li>';

		await loadScript();
		await vi.advanceTimersByTimeAsync( 50 );

		expect( document.querySelector( '.exelearning-preview-wrapper' ) ).toBeNull();
	} );
} );

describe( 'exelearning-media-modal: the details panel', () => {
	// The panel mirrors the native attachment UI: the preview replaces the
	// thumbnail itself, with a single "Edit in eXeLearning" action below it.
	// There is no bespoke metadata block and no "preview in new tab" link.

	it( 'replaces the thumbnail with the preview and puts the edit action below', async () => {
		registerAttachment( 60, {
			exelearning: previewMetadata(),
			exelearningCanEdit: true,
			exelearningEditUrl: '/wp-admin/admin.php?page=exelearning-editor&attachment_id=60',
		} );
		global.wp.media.frame = frameSelecting( 60 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		const thumbnail = document.querySelector( '.attachment-details .thumbnail' );
		expect( thumbnail.querySelector( 'iframe' ) ).not.toBeNull();

		// The action follows the preview, the way "Edit image" follows an image.
		const action = document.querySelector( '.exelearning-edit-action' );
		expect( action.previousElementSibling ).toBe( thumbnail );
	} );

	it( 'emits a link the editor script binds to, with the attachment on it', async () => {
		// exelearning-editor.js opens the in-page modal from this class and
		// reads data-attachment-id; the href is its fallback. That behaviour is
		// covered in exelearning_editor.test.js -- what matters here is that
		// this script emits the markup it binds to.
		registerAttachment( 61, {
			exelearning: previewMetadata(),
			exelearningCanEdit: true,
			exelearningEditUrl: '/wp-admin/admin.php?page=exelearning-editor&attachment_id=61',
		} );
		global.wp.media.frame = frameSelecting( 61 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		const link = document.querySelector( '.exelearning-edit-page-button' );
		expect( link.getAttribute( 'href' ) ).toBe(
			'/wp-admin/admin.php?page=exelearning-editor&attachment_id=61'
		);
		expect( link.getAttribute( 'data-attachment-id' ) ).toBe( '61' );
	} );

	it( 'offers no edit action to a user who cannot edit the file', async () => {
		registerAttachment( 62, {
			exelearning: previewMetadata(),
			exelearningCanEdit: false,
			exelearningEditUrl: '/edit/62',
		} );
		global.wp.media.frame = frameSelecting( 62 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		expect( document.querySelector( '.exelearning-edit-action' ) ).toBeNull();
	} );

	it( 'sandboxes the preview it embeds', async () => {
		registerAttachment( 63, { exelearning: previewMetadata() } );
		global.wp.media.frame = frameSelecting( 63 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		const iframe = document.querySelector( '.attachment-details .thumbnail iframe' );
		expect( iframe.getAttribute( 'sandbox' ) ).toContain( 'allow-scripts' );
		expect( iframe.getAttribute( 'referrerpolicy' ) ).toBe( 'no-referrer' );
	} );

	it( 'says so instead of previewing a v2 source file', async () => {
		registerAttachment( 64, { exelearning: { version: 2, has_preview: false } } );
		global.wp.media.frame = frameSelecting( 64 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		expect( document.querySelector( '.exelearning-no-preview-notice' ) ).not.toBeNull();
		expect( document.querySelector( '.attachment-details .thumbnail iframe' ) ).toBeNull();
	} );

	it( 'does not rebuild the panel when the updates run again', async () => {
		registerAttachment( 65, {
			exelearning: previewMetadata(),
			exelearningCanEdit: true,
			exelearningEditUrl: '/edit/65',
		} );
		global.wp.media.frame = frameSelecting( 65 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();
		observers[ 0 ].trigger();
		await settle();
		await vi.advanceTimersByTimeAsync( 1500 );

		expect( document.querySelectorAll( '.attachment-details .thumbnail iframe' ) ).toHaveLength( 1 );
		expect( document.querySelectorAll( '.exelearning-edit-action' ) ).toHaveLength( 1 );
	} );

	it( 'loads an attachment it does not have yet and then renders it', async () => {
		global.wp.media.frame = null;
		window.history.replaceState( {}, '', '/wp-admin/upload.php?item=66' );
		const model = attachmentFor( 66 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		expect( model.fetchCount ).toBe( 1 );
		expect( document.querySelector( '.attachment-details .thumbnail iframe' ) ).toBeNull();

		model.settleFetch( { id: 66, exelearning: previewMetadata() } );
		await vi.advanceTimersByTimeAsync( 100 );

		expect( document.querySelector( '.attachment-details .thumbnail iframe' ) ).not.toBeNull();
	} );

	it( 'reads the id from the details wrapper when nothing else names it', async () => {
		// No selection and no ?item=: the data-id on the wrapper is the last source.
		global.wp.media.frame = null;
		registerAttachment( 67, { exelearning: previewMetadata() } );
		document.body.innerHTML =
			'<div class="attachment-details" data-id="67"><div class="thumbnail"></div></div>';

		await loadScript();

		expect( document.querySelector( '.attachment-details .thumbnail iframe' ) ).not.toBeNull();
	} );

	it( 'does nothing when there is no details panel on the screen', async () => {
		document.body.innerHTML = '<div class="unrelated"></div>';

		await expect( loadScript() ).resolves.toBeUndefined();
		expect( document.querySelector( '.exelearning-edit-action' ) ).toBeNull();
	} );
} );

describe( 'exelearning-media-modal: processing a file that is not an .elpx yet', () => {
	/** Details panel showing an unprocessed but reprocessable attachment. */
	async function loadReprocessable( id ) {
		const model = registerAttachment( id, { exelearningReprocessable: true } );
		global.wp.media.frame = frameSelecting( id );
		document.body.innerHTML = detailsMarkup();
		await loadScript();
		return model;
	}

	it( 'offers the button with a hint about what the file is', async () => {
		await loadReprocessable( 90 );

		expect( document.querySelector( '.exelearning-process-button' ).textContent )
			.toContain( 'Procesar como eXeLearning' );
		expect( document.querySelector( '.exelearning-process-hint' ).textContent )
			.toBe( 'Archivo eXeLearning (sin procesar)' );
	} );

	it( 'does not offer it for a file that is already processed', async () => {
		registerAttachment( 91, {
			exelearning: previewMetadata(),
			exelearningReprocessable: true,
		} );
		global.wp.media.frame = frameSelecting( 91 );
		document.body.innerHTML = attachmentInfoMarkup();

		await loadScript();

		expect( document.querySelector( '.exelearning-process-button-actions' ) ).toBeNull();
	} );

	it( 'does not offer it for a file that cannot be reprocessed', async () => {
		registerAttachment( 92, { exelearningReprocessable: false } );
		global.wp.media.frame = frameSelecting( 92 );
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		expect( document.querySelector( '.exelearning-process-button' ) ).toBeNull();
	} );

	it( 'posts to the reprocess endpoint with the REST nonce', async () => {
		await loadReprocessable( 93 );
		stubFetch( { ok: true, body: { success: true } } );

		document.querySelector( '.exelearning-process-button' ).click();
		await settle();

		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].url ).toBe( '/wp-json/exelearning/v1/reprocess/93' );
		expect( requests[ 0 ].options.method ).toBe( 'POST' );
		expect( requests[ 0 ].options.headers[ 'X-WP-Nonce' ] ).toBe( 'rest-nonce-123' );
		expect( requests[ 0 ].options.credentials ).toBe( 'same-origin' );
	} );

	it( 'disables the button while the request is in flight', async () => {
		await loadReprocessable( 94 );
		let release;
		window.fetch = () => new Promise( ( resolve ) => {
			release = () => resolve( { ok: true, json: () => Promise.resolve( { success: true } ) } );
		} );

		const button = document.querySelector( '.exelearning-process-button' );
		button.click();
		await settle();

		expect( button.disabled ).toBe( true );
		expect( button.textContent ).toBe( 'Procesando…' );

		release();
		await settle();
	} );

	it( 'reloads the attachment and re-renders once the file is processed', async () => {
		const model = await loadReprocessable( 95 );
		stubFetch( { ok: true, body: { success: true } } );

		document.querySelector( '.exelearning-process-button' ).click();
		await settle();

		expect( model.get( 'exelearningReprocessable' ) ).toBe( false );
		expect( model.fetchCount ).toBe( 1 );

		model.settleFetch( { exelearning: previewMetadata() } );
		await settle();

		expect( document.querySelector( '.exelearning-process-button' ) ).toBeNull();
		expect( document.querySelector( '.attachment-details .thumbnail iframe' ) ).not.toBeNull();
	} );

	it( 'reports the server\'s own message and gives the button back', async () => {
		await loadReprocessable( 96 );
		stubFetch( {
			ok: false,
			body: { code: 'not_an_elp', message: 'El archivo no es un paquete válido.' },
		} );

		const button = document.querySelector( '.exelearning-process-button' );
		button.click();
		await settle();

		expect( document.querySelector( '.exelearning-process-error' ).textContent )
			.toBe( 'El archivo no es un paquete válido.' );
		expect( button.disabled ).toBe( false );
		expect( button.textContent ).toContain( 'Procesar como eXeLearning' );
	} );

	it( 'treats a 200 carrying an error code as a failure', async () => {
		// The REST route answers 200 with a WP_Error-shaped body in some paths, so
		// resp.ok on its own is not enough to call it a success.
		await loadReprocessable( 97 );
		stubFetch( { ok: true, body: { code: 'no_index', message: 'Sin index.html.' } } );

		document.querySelector( '.exelearning-process-button' ).click();
		await settle();

		expect( document.querySelector( '.exelearning-process-error' ).textContent )
			.toBe( 'Sin index.html.' );
	} );

	it( 'falls back to a generic message when the server sends none', async () => {
		await loadReprocessable( 98 );
		stubFetch( { ok: false, body: {} } );

		document.querySelector( '.exelearning-process-button' ).click();
		await settle();

		expect( document.querySelector( '.exelearning-process-error' ).textContent )
			.toBe( 'No se pudo procesar este archivo.' );
	} );

	it( 'reports a request that never reached the server', async () => {
		await loadReprocessable( 99 );
		stubFetch( { networkError: true } );

		const button = document.querySelector( '.exelearning-process-button' );
		button.click();
		await settle();

		expect( document.querySelector( '.exelearning-process-error' ).textContent )
			.toBe( 'No se pudo procesar este archivo.' );
		expect( button.disabled ).toBe( false );
	} );

	it( 'replaces the previous error rather than stacking them up', async () => {
		await loadReprocessable( 100 );
		stubFetch( { ok: false, body: { message: 'Primer fallo.' } } );

		const button = document.querySelector( '.exelearning-process-button' );
		button.click();
		await settle();
		stubFetch( { ok: false, body: { message: 'Segundo fallo.' } } );
		button.click();
		await settle();

		const errors = document.querySelectorAll( '.exelearning-process-error' );
		expect( errors ).toHaveLength( 1 );
		expect( errors[ 0 ].textContent ).toBe( 'Segundo fallo.' );
	} );

	it( 'does nothing at all when the REST settings were not localized', async () => {
		window.exelearningMediaSettings = {};
		const model = registerAttachment( 101, { exelearningReprocessable: true } );
		global.wp.media.frame = frameSelecting( 101 );
		document.body.innerHTML = detailsMarkup();
		await loadScript();

		window.fetch = () => {
			throw new Error( 'should not be called' );
		};
		document.querySelector( '.exelearning-process-button' ).click();
		await settle();

		expect( model.fetchCount ).toBe( 0 );
		expect( document.querySelector( '.exelearning-process-error' ) ).toBeNull();
	} );




	it( 'does not duplicate the details button when the updates run again', async () => {
		await loadReprocessable( 104 );

		observers[ 0 ].trigger();
		await settle();

		expect( document.querySelectorAll( '.exelearning-process-button' ) ).toHaveLength( 1 );
	} );

	it( 'skips the actions row entirely when the panel has none', async () => {
		registerAttachment( 105, { exelearningReprocessable: true } );
		global.wp.media.frame = frameSelecting( 105 );
		document.body.innerHTML =
			'<div class="attachment-details"><div class="attachment-info"></div></div>';

		await loadScript();

		expect( document.querySelector( '.exelearning-process-button-actions' ) ).toBeNull();
	} );
} );

describe( 'exelearning-media-modal: when the updates run', () => {
	it( 'watches the document for attachments arriving later', async () => {
		document.body.innerHTML = detailsMarkup();

		await loadScript();

		expect( observers ).toHaveLength( 1 );
		expect( observers[ 0 ].target ).toBe( document.body );
		expect( observers[ 0 ].options ).toEqual( { childList: true, subtree: true } );
	} );

	it( 'refreshes shortly after the media modal opens', async () => {
		document.body.innerHTML = gridMarkup( 110 );

		await loadScript();
		expect( modalOpenHandlers ).toEqual( [
			{ event: 'open', handler: expect.any( Function ) },
		] );

		// The tile only becomes an eXeLearning file once the modal has loaded it.
		registerAttachment( 110, { exelearning: previewMetadata(), filename: 'curso.elpx' } );
		modalOpenHandlers[ 0 ].handler();
		await vi.advanceTimersByTimeAsync( 150 );
		await vi.advanceTimersByTimeAsync( 50 );

		expect( document.querySelector( '.exelearning-preview-wrapper' ) ).not.toBeNull();
	} );

	it( 'keeps retrying on a timer for content that loads asynchronously', async () => {
		document.body.innerHTML = gridMarkup( 111 );

		await loadScript();
		expect( document.querySelector( '.exelearning-preview-wrapper' ) ).toBeNull();

		registerAttachment( 111, { exelearning: previewMetadata(), filename: 'curso.elpx' } );
		await vi.advanceTimersByTimeAsync( 1500 );
		await vi.advanceTimersByTimeAsync( 50 );

		expect( document.querySelector( '.exelearning-preview-wrapper' ) ).not.toBeNull();
	} );
} );
