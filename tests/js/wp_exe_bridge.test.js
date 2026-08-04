// Unit tests for assets/js/wp-exe-bridge.js.
//
// The bridge is the editor's half of the postMessage protocol: it runs inside the
// embedded eXeLearning page (the export bootstrap and the editor bootstrap both load
// it) and answers the parent modal's WP_REQUEST_SAVE / WP_REQUEST_EXPORT /
// GET_PROJECT_INFO / CONFIGURE calls. Everything it does is observable as a message
// posted to window.parent, so the whole file can be driven through a stubbed parent.
//
// What is real here and what is not: the editor itself is stubbed -- there is no
// eXeLearning app under Vitest -- but the bridge's own logic is real, and it is the
// part that breaks. A requestId that is not echoed back, an error that is swallowed
// instead of reported, DOCUMENT_LOADED announced twice, or a message the bridge
// answers when it should have ignored it all leave the parent modal hanging forever.
//
// The script is an IIFE that reads its config and runs init() at import time, so each
// test imports a fresh copy under a cache-busting URL. Its listeners are recorded
// during that import and unbound afterwards, otherwise every instance would keep
// answering messages meant for the next test.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const BRIDGE = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/wp-exe-bridge.js' )
).href;

let loadCount = 0;
/** Everything the bridge posted to the parent frame, in order. */
let posted = [];
/** Listeners the bridge bound during import, so they can be unbound after the test. */
let bound = [];
let consoleWarnings = [];
let consoleErrors = [];

let originalParentDescriptor;
let originalConsoleWarn;
let originalConsoleError;
let originalWindowAdd;
let originalDocumentAdd;

beforeAll( () => {
	originalParentDescriptor = Object.getOwnPropertyDescriptor( window, 'parent' );
	originalConsoleWarn = console.warn;
	originalConsoleError = console.error;
	console.warn = ( ...args ) => consoleWarnings.push( args.join( ' ' ) );
	console.error = ( ...args ) => consoleErrors.push( args.join( ' ' ) );
} );

afterAll( () => {
	if ( originalParentDescriptor ) {
		Object.defineProperty( window, 'parent', originalParentDescriptor );
	} else {
		delete window.parent;
	}
	console.warn = originalConsoleWarn;
	console.error = originalConsoleError;
} );

beforeEach( () => {
	posted = [];
	bound = [];
	consoleWarnings = [];
	consoleErrors = [];
	// Fake timers keep the polling loops (getApp, waitForSharedExporters,
	// notifyWhenDocumentLoaded) suspended unless a test advances the clock. A loop
	// left pending by one test is discarded with the fake clock rather than waking
	// up inside the next one.
	vi.useFakeTimers();
	embedIn( { postMessage: ( message ) => posted.push( message ) } );

	// Recorded for the whole test, not just for the import: init() is async, so a
	// bridge that waits on window.eXeLearning.ready binds its listeners well after
	// the import resolved. Missing those leaves an instance answering messages in
	// every later test.
	originalWindowAdd = window.addEventListener;
	originalDocumentAdd = document.addEventListener;
	window.addEventListener = function ( type, fn, options ) {
		bound.push( { target: window, type, fn } );
		return originalWindowAdd.call( this, type, fn, options );
	};
	document.addEventListener = function ( type, fn, options ) {
		bound.push( { target: document, type, fn } );
		return originalDocumentAdd.call( this, type, fn, options );
	};
} );

afterEach( () => {
	window.addEventListener = originalWindowAdd;
	document.addEventListener = originalDocumentAdd;
	bound.forEach( ( entry ) => entry.target.removeEventListener( entry.type, entry.fn ) );
	vi.useRealTimers();
	document.body.innerHTML = '';
	document.body.removeAttribute( 'data-exe-hide-file-menu' );
	document.body.removeAttribute( 'data-exe-hide-save' );
	document.body.removeAttribute( 'data-exe-hide-user-menu' );
	delete window.eXeLearning;
	delete window.SharedExporters;
	delete window.__WP_EXE_CONFIG__;
	delete window.__EXE_EMBEDDING_CONFIG__;
	delete window.wpExeBridge;
	window.onbeforeunload = null;
} );

/**
 * Put the page inside a frame with the given parent window.
 *
 * The bridge stays silent unless window.parent is another window, so a stubbed
 * parent is what makes any of its output observable.
 *
 * @param {Object|null} parentWindow Stub parent, or null to be the top-level page.
 */
function embedIn( parentWindow ) {
	Object.defineProperty( window, 'parent', {
		configurable: true,
		value: null === parentWindow ? window : parentWindow,
	} );
}

/**
 * Import a fresh bridge, recording the listeners it binds.
 *
 * @return {Promise<void>} Resolves once init() has run to completion.
 */
async function loadBridge() {
	await import( /* @vite-ignore */ `${ BRIDGE }?load=${ ++loadCount }` );
	await settle();
}

/** Let the bridge's promise chains settle without waiting on real time. */
async function settle() {
	for ( let i = 0; i < 8; i++ ) {
		await Promise.resolve();
		await vi.advanceTimersByTimeAsync( 0 );
	}
}

/** Deliver a protocol message from the parent and let the bridge answer it. */
async function send( data ) {
	window.dispatchEvent( new window.MessageEvent( 'message', { data } ) );
	await settle();
}

/** The first message of a given type the bridge posted, if any. */
function messageOf( type ) {
	return posted.find( ( message ) => message.type === type );
}

/**
 * Build a stub editor whose project exports through the Yjs + SharedExporters path.
 *
 * @param {Object} [overrides] Parts of the Yjs bridge to replace.
 * @return {Object} The documentManager the stub exposes.
 */
function installEditorWithDocument( overrides ) {
	const documentManager = {
		getMetadata: () => new Map( [ [ 'title', 'Mi proyecto' ], [ 'author', 'Ada' ] ] ),
		getNavigation: () => [ {}, {}, {} ],
		markClean: vi.fn(),
		...( overrides?.documentManager || {} ),
	};

	window.eXeLearning = {
		version: '3.1.0',
		projectId: 'proj-1',
		app: {
			project: {
				_yjsBridge: {
					projectId: 'proj-1',
					documentManager,
					...( overrides?.yjsBridge || {} ),
				},
			},
		},
	};

	return documentManager;
}

describe( 'wp-exe-bridge: announcing itself to the parent', () => {
	it( 'reports readiness with its version and the protocol it speaks', async () => {
		installEditorWithDocument();

		await loadBridge();

		expect( messageOf( 'EXELEARNING_READY' ) ).toEqual( {
			type: 'EXELEARNING_READY',
			version: '3.1.0',
			capabilities: [ 'WP_REQUEST_SAVE', 'WP_REQUEST_EXPORT', 'GET_PROJECT_INFO', 'CONFIGURE' ],
		} );
	} );

	it( 'passes the attachment id back so the modal knows which file answered', async () => {
		window.__WP_EXE_CONFIG__ = { attachmentId: 77 };
		installEditorWithDocument();

		await loadBridge();

		expect( posted ).toContainEqual( {
			source: 'wp-exe-editor',
			type: 'editor-ready',
			data: { attachmentId: 77 },
		} );
	} );

	it( 'waits for the editor to be ready before announcing anything', async () => {
		let releaseEditor;
		installEditorWithDocument();
		window.eXeLearning.ready = new Promise( ( resolve ) => {
			releaseEditor = resolve;
		} );

		await loadBridge();
		expect( posted ).toEqual( [] );

		releaseEditor();
		await settle();

		expect( messageOf( 'EXELEARNING_READY' ) ).toBeDefined();
	} );

	it( 'reports an unknown version rather than failing without an editor', async () => {
		await loadBridge();

		expect( messageOf( 'EXELEARNING_READY' ).version ).toBe( 'unknown' );
	} );

	it( 'stays silent when it is not embedded at all', async () => {
		// window.parent === window on a top-level page. Posting there would make the
		// editor answer its own protocol messages.
		embedIn( null );
		installEditorWithDocument();

		await loadBridge();

		expect( posted ).toEqual( [] );
	} );

	it( 'exposes requestSave and the config it was given', async () => {
		window.__WP_EXE_CONFIG__ = { attachmentId: 5 };
		installEditorWithDocument();

		await loadBridge();
		posted = [];
		window.wpExeBridge.requestSave();

		expect( window.wpExeBridge.config ).toEqual( { attachmentId: 5 } );
		expect( posted ).toEqual( [
			{ source: 'wp-exe-editor', type: 'request-save', data: {} },
		] );
	} );
} );

describe( 'wp-exe-bridge: the target origin', () => {
	it( 'posts to the embedding parent origin when one was configured', async () => {
		const origins = [];
		embedIn( { postMessage: ( message, origin ) => origins.push( origin ) } );
		window.__EXE_EMBEDDING_CONFIG__ = { parentOrigin: 'https://wp.example' };
		installEditorWithDocument();

		await loadBridge();

		expect( origins.length ).toBeGreaterThan( 0 );
		expect( new Set( origins ) ).toEqual( new Set( [ 'https://wp.example' ] ) );
	} );

	it( 'falls back to any origin when the embedder did not declare one', async () => {
		const origins = [];
		embedIn( { postMessage: ( message, origin ) => origins.push( origin ) } );
		installEditorWithDocument();

		await loadBridge();

		expect( new Set( origins ) ).toEqual( new Set( [ '*' ] ) );
	} );
} );

describe( 'wp-exe-bridge: announcing the loaded document', () => {
	it( 'reports DOCUMENT_LOADED once the project is there', async () => {
		installEditorWithDocument();

		await loadBridge();

		expect( messageOf( 'DOCUMENT_LOADED' ) ).toEqual( { type: 'DOCUMENT_LOADED' } );
	} );

	it( 'waits for a project that arrives late instead of giving up', async () => {
		window.eXeLearning = { app: { project: {} } };

		await loadBridge();
		expect( messageOf( 'DOCUMENT_LOADED' ) ).toBeUndefined();

		installEditorWithDocument();
		await vi.advanceTimersByTimeAsync( 200 );

		expect( messageOf( 'DOCUMENT_LOADED' ) ).toBeDefined();
	} );

	it( 'gives up after thirty seconds without announcing a document', async () => {
		window.eXeLearning = { app: { project: {} } };

		await loadBridge();
		await vi.advanceTimersByTimeAsync( 31000 );

		expect( messageOf( 'DOCUMENT_LOADED' ) ).toBeUndefined();
	} );

	it( 'reports the first edit and then stops repeating itself', async () => {
		// The modal only needs to know the document became dirty; one notification
		// per edit would be a message storm on every keystroke.
		const listeners = [];
		installEditorWithDocument( {
			documentManager: {
				ydoc: { on: ( event, fn ) => listeners.push( { event, fn } ) },
			},
		} );

		await loadBridge();
		expect( listeners ).toHaveLength( 1 );
		expect( listeners[ 0 ].event ).toBe( 'update' );

		listeners[ 0 ].fn();
		listeners[ 0 ].fn();
		listeners[ 0 ].fn();

		expect( posted.filter( ( m ) => m.type === 'DOCUMENT_CHANGED' ) ).toHaveLength( 1 );
	} );

	it( 'survives a document manager whose ydoc cannot be watched', async () => {
		const documentManager = installEditorWithDocument();
		// Defined after the fact: a throwing getter in an object literal would blow
		// up in the spread inside the helper instead of inside the bridge.
		Object.defineProperty( documentManager, 'ydoc', {
			get() {
				throw new Error( 'detached' );
			},
		} );

		await loadBridge();

		expect( messageOf( 'DOCUMENT_LOADED' ) ).toBeDefined();
		expect( consoleWarnings.join( ' ' ) ).toContain( 'Change monitor failed' );
	} );
} );

describe( 'wp-exe-bridge: messages it must not answer', () => {
	it( 'ignores its own messages so it cannot talk to itself', async () => {
		installEditorWithDocument();
		await loadBridge();
		posted = [];

		await send( { source: 'wp-exe-editor', type: 'WP_REQUEST_SAVE', requestId: 'r1' } );

		expect( posted ).toEqual( [] );
	} );

	it( 'ignores anything without a type', async () => {
		installEditorWithDocument();
		await loadBridge();
		posted = [];

		await send( { requestId: 'r1', data: {} } );

		expect( posted ).toEqual( [] );
	} );

	it( 'ignores a type it does not implement, without erroring back', async () => {
		installEditorWithDocument();
		await loadBridge();
		posted = [];

		await send( { type: 'SOMETHING_ELSE', requestId: 'r1' } );

		expect( posted ).toEqual( [] );
	} );
} );

describe( 'wp-exe-bridge: WP_REQUEST_SAVE', () => {
	it( 'exports through the Yjs exporter and answers with the bytes', async () => {
		const documentManager = installEditorWithDocument();
		// The save path waits on quickExport before reaching for createExporter, so
		// the stub carries both entry points exactly as the real bundle does.
		window.SharedExporters = {
			quickExport: vi.fn(),
			createExporter: vi.fn( () => ( {
				export: async () => ( {
					success: true,
					data: new Uint8Array( [ 1, 2, 3, 4 ] ),
					filename: 'mi-proyecto.elpx',
				} ),
			} ) ),
		};

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_SAVE', requestId: 'save-1' } );

		const answer = messageOf( 'WP_SAVE_FILE' );
		expect( answer.requestId ).toBe( 'save-1' );
		expect( answer.filename ).toBe( 'mi-proyecto.elpx' );
		expect( answer.mimeType ).toBe( 'application/zip' );
		expect( answer.size ).toBe( 4 );
		expect( new Uint8Array( answer.bytes ) ).toEqual( new Uint8Array( [ 1, 2, 3, 4 ] ) );
		expect( window.SharedExporters.createExporter ).toHaveBeenCalledWith(
			'elpx',
			documentManager,
			null,
			null,
			null
		);
	} );

	it( 'reports the exporter\'s own error message back to the modal', async () => {
		installEditorWithDocument();
		window.SharedExporters = {
			quickExport: vi.fn(),
			createExporter: () => ( {
				export: async () => ( { success: false, error: 'Asset missing' } ),
			} ),
		};

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_SAVE', requestId: 'save-2' } );

		expect( messageOf( 'WP_REQUEST_SAVE_ERROR' ) ).toEqual( {
			type: 'WP_REQUEST_SAVE_ERROR',
			requestId: 'save-2',
			error: 'Asset missing',
		} );
	} );

	it( 'falls back to the project blob exporter when there is no Yjs bridge', async () => {
		window.eXeLearning = {
			app: {
				project: {
					exportToElpxBlob: async () =>
						new Blob( [ new Uint8Array( [ 9, 9 ] ) ], { type: 'application/zip' } ),
					getExportFilename: () => 'fallback.elpx',
				},
			},
		};

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_SAVE', requestId: 'save-3' } );

		const answer = messageOf( 'WP_SAVE_FILE' );
		expect( answer.filename ).toBe( 'fallback.elpx' );
		expect( answer.size ).toBe( 2 );
	} );

	it( 'names the file project.elpx when the project will not name it', async () => {
		window.eXeLearning = {
			app: {
				project: {
					exportToElpxBlob: async () => new Blob( [ new Uint8Array( [ 1 ] ) ] ),
				},
			},
		};

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_SAVE', requestId: 'save-4' } );

		const answer = messageOf( 'WP_SAVE_FILE' );
		expect( answer.filename ).toBe( 'project.elpx' );
		expect( answer.mimeType ).toBe( 'application/zip' );
	} );

	it( 'says so when the project cannot export at all', async () => {
		window.eXeLearning = { app: { project: {} } };

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_SAVE', requestId: 'save-5' } );

		expect( messageOf( 'WP_REQUEST_SAVE_ERROR' ).error ).toBe( 'Export not available' );
	} );
} );

describe( 'wp-exe-bridge: WP_REQUEST_EXPORT', () => {
	/** Install a quickExport stub that succeeds with the given payload. */
	function installQuickExport( result ) {
		window.SharedExporters = { quickExport: vi.fn( async () => result ) };
	}

	it( 'exports the requested format and answers with the bytes', async () => {
		const documentManager = installEditorWithDocument();
		installQuickExport( {
			success: true,
			data: new Uint8Array( [ 7, 7, 7 ] ),
			filename: 'curso_scorm.zip',
		} );

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_EXPORT', requestId: 'exp-1', data: { format: 'scorm12' } } );

		const answer = messageOf( 'WP_EXPORT_FILE' );
		expect( answer.requestId ).toBe( 'exp-1' );
		expect( answer.format ).toBe( 'scorm12' );
		expect( answer.filename ).toBe( 'curso_scorm.zip' );
		expect( answer.mimeType ).toBe( 'application/zip' );
		expect( answer.size ).toBe( 3 );
		expect( window.SharedExporters.quickExport ).toHaveBeenCalledWith(
			'scorm12',
			documentManager,
			null,
			null,
			{},
			null
		);
	} );

	it( 'accepts the format at the top level as well as under data', async () => {
		installEditorWithDocument();
		installQuickExport( { success: true, data: new Uint8Array( [ 1 ] ) } );

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_EXPORT', requestId: 'exp-2', format: 'ims' } );

		expect( messageOf( 'WP_EXPORT_FILE' ).format ).toBe( 'ims' );
	} );

	it( 'labels an EPUB with the EPUB media type, not a generic zip', async () => {
		installEditorWithDocument();
		installQuickExport( { success: true, data: new Uint8Array( [ 1 ] ) } );

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_EXPORT', requestId: 'exp-3', data: { format: 'epub3' } } );

		const answer = messageOf( 'WP_EXPORT_FILE' );
		expect( answer.mimeType ).toBe( 'application/epub+zip' );
		expect( answer.filename ).toBe( 'project.epub3' );
	} );

	it( 'refuses a request that names no format', async () => {
		installEditorWithDocument();
		installQuickExport( { success: true, data: new Uint8Array( [ 1 ] ) } );

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_EXPORT', requestId: 'exp-4', data: {} } );

		expect( messageOf( 'WP_REQUEST_EXPORT_ERROR' ) ).toEqual( {
			type: 'WP_REQUEST_EXPORT_ERROR',
			requestId: 'exp-4',
			error: 'Missing export format',
		} );
	} );

	it( 'reports a failed export instead of answering with nothing', async () => {
		installEditorWithDocument();
		installQuickExport( { success: false } );

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_EXPORT', requestId: 'exp-5', data: { format: 'html5' } } );

		expect( messageOf( 'WP_REQUEST_EXPORT_ERROR' ).error ).toBe( 'Export failed' );
	} );

	it( 'gives up on an exporters bundle that never loads', async () => {
		// exporters.bundle.js is lazily loaded in a later group than the readiness
		// signal, so the bridge polls for it. It must still fail rather than hang.
		installEditorWithDocument();

		await loadBridge();
		posted = [];
		const answered = send( {
			type: 'WP_REQUEST_EXPORT',
			requestId: 'exp-6',
			data: { format: 'html5' },
		} );
		await vi.advanceTimersByTimeAsync( 16000 );
		await answered;

		expect( messageOf( 'WP_REQUEST_EXPORT_ERROR' ).error ).toBe(
			'SharedExporters bundle not loaded'
		);
	} );
} );

describe( 'wp-exe-bridge: GET_PROJECT_INFO', () => {
	it( 'answers with the project metadata the modal shows', async () => {
		installEditorWithDocument();

		await loadBridge();
		posted = [];
		await send( { type: 'GET_PROJECT_INFO', requestId: 'info-1' } );

		expect( messageOf( 'PROJECT_INFO' ) ).toEqual( {
			type: 'PROJECT_INFO',
			requestId: 'info-1',
			projectId: 'proj-1',
			title: 'Mi proyecto',
			author: 'Ada',
			description: '',
			language: 'en',
			theme: 'base',
			pageCount: 3,
		} );
	} );

	it( 'falls back to sane defaults when the document has no metadata', async () => {
		installEditorWithDocument( {
			documentManager: { getMetadata: () => undefined, getNavigation: () => undefined },
		} );

		await loadBridge();
		posted = [];
		await send( { type: 'GET_PROJECT_INFO', requestId: 'info-2' } );

		const answer = messageOf( 'PROJECT_INFO' );
		expect( answer.title ).toBe( 'Untitled' );
		expect( answer.language ).toBe( 'en' );
		expect( answer.theme ).toBe( 'base' );
		expect( answer.pageCount ).toBe( 0 );
	} );

	it( 'reports that nothing is loaded rather than inventing a project', async () => {
		window.eXeLearning = { app: { project: {} } };

		await loadBridge();
		posted = [];
		await send( { type: 'GET_PROJECT_INFO', requestId: 'info-3' } );

		expect( messageOf( 'GET_PROJECT_INFO_ERROR' ).error ).toBe( 'No project loaded' );
	} );
} );

describe( 'wp-exe-bridge: CONFIGURE', () => {
	it( 'hides the parts of the editor chrome WordPress provides itself', async () => {
		installEditorWithDocument();

		await loadBridge();
		posted = [];
		await send( {
			type: 'CONFIGURE',
			requestId: 'cfg-1',
			data: { hideUI: { fileMenu: true, saveButton: true, userMenu: true } },
		} );

		expect( document.body.getAttribute( 'data-exe-hide-file-menu' ) ).toBe( 'true' );
		expect( document.body.getAttribute( 'data-exe-hide-save' ) ).toBe( 'true' );
		expect( document.body.getAttribute( 'data-exe-hide-user-menu' ) ).toBe( 'true' );
		expect( messageOf( 'CONFIGURE_SUCCESS' ) ).toEqual( {
			type: 'CONFIGURE_SUCCESS',
			requestId: 'cfg-1',
		} );
	} );

	it( 'leaves alone whatever it was not asked to hide', async () => {
		installEditorWithDocument();

		await loadBridge();
		await send( { type: 'CONFIGURE', requestId: 'cfg-2', data: { hideUI: { saveButton: true } } } );

		expect( document.body.hasAttribute( 'data-exe-hide-file-menu' ) ).toBe( false );
		expect( document.body.getAttribute( 'data-exe-hide-save' ) ).toBe( 'true' );
	} );

	it( 'still acknowledges a CONFIGURE that hides nothing', async () => {
		installEditorWithDocument();

		await loadBridge();
		posted = [];
		await send( { type: 'CONFIGURE', requestId: 'cfg-3' } );

		expect( messageOf( 'CONFIGURE_SUCCESS' ) ).toBeDefined();
		expect( document.body.hasAttribute( 'data-exe-hide-save' ) ).toBe( false );
	} );
} );

describe( 'wp-exe-bridge: WP_SAVE_CONFIRMED', () => {
	it( 'marks the document clean and drops the unload warning', async () => {
		const documentManager = installEditorWithDocument();
		window.onbeforeunload = () => 'unsaved';

		await loadBridge();
		posted = [];
		await send( { type: 'WP_SAVE_CONFIRMED', requestId: 'ok-1' } );

		expect( documentManager.markClean ).toHaveBeenCalled();
		expect( window.onbeforeunload ).toBeNull();
		expect( messageOf( 'WP_SAVE_CONFIRMED_ACK' ) ).toEqual( {
			type: 'WP_SAVE_CONFIRMED_ACK',
			requestId: 'ok-1',
		} );
	} );

	it( 'acknowledges even when there is no document to mark clean', async () => {
		window.eXeLearning = { app: { project: {} } };
		window.onbeforeunload = () => 'unsaved';

		await loadBridge();
		posted = [];
		await send( { type: 'WP_SAVE_CONFIRMED', requestId: 'ok-2' } );

		expect( window.onbeforeunload ).toBeNull();
		expect( messageOf( 'WP_SAVE_CONFIRMED_ACK' ) ).toBeDefined();
	} );
} );

describe( 'wp-exe-bridge: giving up instead of hanging', () => {
	// Every one of these ends in an answer. A request the bridge simply never
	// replies to leaves the parent modal spinning with no way out, which is worse
	// than a visible error.

	it( 'reports that the app never appeared', async () => {
		await loadBridge();
		posted = [];

		const answered = send( { type: 'WP_REQUEST_SAVE', requestId: 'slow-1' } );
		await vi.advanceTimersByTimeAsync( 16000 );
		await answered;

		expect( messageOf( 'WP_REQUEST_SAVE_ERROR' ).error ).toBe( 'App not ready' );
	} );

	it( 'reports that the document never finished loading', async () => {
		window.eXeLearning = { app: { project: {} } };
		window.SharedExporters = { quickExport: vi.fn() };

		await loadBridge();
		posted = [];

		const answered = send( {
			type: 'WP_REQUEST_EXPORT',
			requestId: 'slow-2',
			data: { format: 'html5' },
		} );
		await vi.advanceTimersByTimeAsync( 16000 );
		await answered;

		expect( messageOf( 'WP_REQUEST_EXPORT_ERROR' ).error ).toBe( 'Document not ready' );
	} );

	it( 'falls through to the plain exporters when the bundle never arrives', async () => {
		// A save waits for the bundle, then carries on without it rather than
		// failing outright -- here nothing else can export either, so it says so.
		installEditorWithDocument();

		await loadBridge();
		posted = [];

		const answered = send( { type: 'WP_REQUEST_SAVE', requestId: 'slow-3' } );
		await vi.advanceTimersByTimeAsync( 16000 );
		await answered;

		expect( messageOf( 'WP_REQUEST_SAVE_ERROR' ).error ).toBe( 'Export not available' );
	} );
} );

describe( 'wp-exe-bridge: the remaining export routes', () => {
	it( 'uses the Yjs bridge exporter when that is all the project has', async () => {
		window.eXeLearning = {
			app: {
				project: {
					_yjsBridge: {
						exporter: {
							exportToBlob: async () =>
								new Blob( [ new Uint8Array( [ 5, 5, 5 ] ) ], { type: 'application/zip' } ),
							buildFilename: () => 'desde-yjs.elpx',
						},
					},
				},
			},
		};

		await loadBridge();
		posted = [];
		await send( { type: 'WP_REQUEST_SAVE', requestId: 'save-6' } );

		const answer = messageOf( 'WP_SAVE_FILE' );
		expect( answer.filename ).toBe( 'desde-yjs.elpx' );
		expect( answer.size ).toBe( 3 );
	} );

	it( 'ignores a hideUI that is not a set of flags', async () => {
		installEditorWithDocument();

		await loadBridge();
		posted = [];
		await send( { type: 'CONFIGURE', requestId: 'cfg-4', data: { hideUI: 'everything' } } );

		expect( document.body.hasAttribute( 'data-exe-hide-save' ) ).toBe( false );
		expect( messageOf( 'CONFIGURE_SUCCESS' ) ).toBeDefined();
	} );
} );

describe( 'wp-exe-bridge: starting up', () => {
	it( 'waits for DOMContentLoaded when it is parsed before the document is ready', async () => {
		const readyState = Object.getOwnPropertyDescriptor(
			window.Document.prototype,
			'readyState'
		);
		Object.defineProperty( document, 'readyState', {
			configurable: true,
			get: () => 'loading',
		} );
		installEditorWithDocument();

		try {
			await loadBridge();
			expect( posted ).toEqual( [] );

			document.dispatchEvent( new window.Event( 'DOMContentLoaded' ) );
			await settle();

			expect( messageOf( 'EXELEARNING_READY' ) ).toBeDefined();
		} finally {
			delete document.readyState;
			if ( readyState ) {
				Object.defineProperty( window.Document.prototype, 'readyState', readyState );
			}
		}
	} );

	it( 'logs and carries on when the editor never becomes ready', async () => {
		const ready = Promise.reject( new Error( 'editor exploded' ) );
		// The bridge only attaches its await after the import, which is a tick too
		// late for Node: park a no-op handler on the original so the rejection is
		// not reported as unhandled. init() still sees it throw.
		ready.catch( () => {} );
		window.eXeLearning = { ready };

		await loadBridge();

		expect( posted ).toEqual( [] );
		expect( consoleErrors.join( ' ' ) ).toContain( 'Initialization failed' );
	} );
} );

describe( 'wp-exe-bridge: the save shortcut', () => {
	it( 'asks the modal to save on Ctrl+S and swallows the browser dialog', async () => {
		installEditorWithDocument();
		await loadBridge();
		posted = [];

		const event = new window.KeyboardEvent( 'keydown', {
			key: 's',
			ctrlKey: true,
			bubbles: true,
			cancelable: true,
		} );
		document.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( true );
		expect( posted ).toEqual( [
			{ source: 'wp-exe-editor', type: 'request-save', data: {} },
		] );
	} );

	it( 'works with Cmd+S too', async () => {
		installEditorWithDocument();
		await loadBridge();
		posted = [];

		document.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 's',
				metaKey: true,
				bubbles: true,
				cancelable: true,
			} )
		);

		expect( posted ).toHaveLength( 1 );
	} );

	it( 'leaves a plain s alone so typing still works', async () => {
		installEditorWithDocument();
		await loadBridge();
		posted = [];

		const event = new window.KeyboardEvent( 'keydown', {
			key: 's',
			bubbles: true,
			cancelable: true,
		} );
		document.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( false );
		expect( posted ).toEqual( [] );
	} );
} );
