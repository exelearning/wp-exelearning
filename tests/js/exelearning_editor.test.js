// Unit tests for the "Edit in eXeLearning" trigger in assets/js/exelearning-editor.js.
//
// The button is printed by the attachment meta box (includes/integrations/class-media-library.php)
// as a real <a href> to the standalone editor page, carrying data-attachment-id. On any screen
// that already has the editor modal, following that href throws away the modal the plugin
// just rendered; on a screen without one, the href is the only thing that works. Both halves
// are asserted here, because the fix is only correct if it keeps the fallback.
//
// exelearning-editor.js is a jQuery IIFE with no exports: it is loaded for its side effects and
// publishes itself as window.ExeLearningEditor on document ready. Each test therefore imports a
// fresh copy against a fresh DOM, under a cache-busting URL so the module actually re-executes.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const EDITOR_SCRIPT = pathToFileURL( path.resolve( __dirname, '../../assets/js/exelearning-editor.js' ) ).href;

const MODAL_MARKUP =
	'<div id="exelearning-editor-modal" style="display:none">' +
		'<iframe id="exelearning-editor-iframe"></iframe>' +
		'<button id="exelearning-editor-save"></button>' +
		'<button id="exelearning-editor-close"></button>' +
	'</div>';

const BUTTON_MARKUP =
	'<a href="/wp-admin/admin.php?page=exelearning-editor&attachment_id=42"' +
		' class="button exelearning-edit-page-button" data-attachment-id="42">Edit in eXeLearning</a>';

// WordPress puts jQuery on the page as a global; the IIFE closes over it by name.
const $ = require( 'jquery' );
global.jQuery = $;

// Each copy of the script binds a native `message` listener on window and never
// unbinds it, so record every registration in order to drop them between tests.
const windowMessageListeners = [];
const realAddEventListener = window.addEventListener.bind( window );
window.addEventListener = ( type, listener, options ) => {
	if ( type === 'message' ) {
		windowMessageListeners.push( { listener, options } );
	}
	realAddEventListener( type, listener, options );
};

let loadCount = 0;

/**
 * Load a fresh copy of the editor script against the given markup.
 *
 * @param {string} markup Body markup for the screen under test.
 * @return {Promise<Object>} The published ExeLearningEditor controller.
 */
async function loadEditorOn( markup ) {
	document.body.innerHTML = markup;
	global.exelearningEditorVars = {
		editorPageUrl: '/wp-admin/admin.php?page=exelearning-editor',
		editorNonce: 'editor-nonce',
		restUrl: '/wp-json/exelearning/v1',
		nonce: 'rest-nonce',
		i18n: {},
	};
	// close() and onSaveComplete() both reach refreshMediaLibrary(), which reads a bare
	// `wp`. Without the global that is a ReferenceError rather than a readable failure.
	global.wp = {};
	// Nothing here belongs on the network. Opening the modal points the iframe at the
	// editor page, and happy-dom fetches that for real; tests that care about a specific
	// response replace this stub.
	window.fetch = () =>
		Promise.resolve( {
			ok: false,
			status: 404,
			json: () => Promise.resolve( {} ),
			text: () => Promise.resolve( '' ),
			headers: { get: () => null },
		} );

	await import( /* @vite-ignore */ `${ EDITOR_SCRIPT }?load=${ ++loadCount }` );

	// Registered after the script's own ready callback, so it resolves after init().
	await new Promise( ( resolve ) => $( document ).ready( resolve ) );
	return window.ExeLearningEditor;
}

/**
 * Click the meta box button the way a browser would, and report whether the
 * default navigation survived.
 *
 * @return {boolean} True when the handler called preventDefault().
 */
function clickEditButton() {
	const event = new window.MouseEvent( 'click', { bubbles: true, cancelable: true } );
	document.querySelector( '.exelearning-edit-page-button' ).dispatchEvent( event );
	return event.defaultPrevented;
}

/**
 * Undo everything a copy of the script left on the shared window/document, so the
 * next test starts from a clean environment.
 */
function cleanupEditor() {
	// jQuery keeps one native listener per event type on document, so a handler bound by
	// the previous copy of the script would still fire during the next test.
	$( document ).off();
	while ( windowMessageListeners.length ) {
		const { listener, options } = windowMessageListeners.pop();
		window.removeEventListener( 'message', listener, options );
	}
	document.body.innerHTML = '';
	// open() marks the body while the modal is up; the class outlives innerHTML.
	document.body.className = '';
	delete window.ExeLearningEditor;
	delete global.exelearningEditorVars;
	delete global.wp;
	delete window.fetch;
}

/**
 * Give the modal iframe a controllable content window.
 *
 * The controller identifies the editor by object identity — `event.source !== iframeWindow`
 * — and talks to it with postMessage, so a stub window is what makes both observable. The
 * real iframe element stays in the document; only its window is ours.
 *
 * @param {Object} editor The controller under test.
 * @return {Object[]} Array collecting {message, origin} for everything posted to the editor.
 */
function stubEditorWindow( editor ) {
	const posted = [];
	const editorWindow = {
		postMessage: ( message, origin ) => posted.push( { message, origin } ),
	};
	Object.defineProperty( editor.iframe[ 0 ], 'contentWindow', {
		get: () => editorWindow,
		configurable: true,
	} );
	editor.editorWindow = editorWindow;
	return posted;
}

/**
 * Deliver a message as if the editor iframe had posted it.
 *
 * @param {Object} editor    The controller under test.
 * @param {Object} data      Message payload.
 * @param {string} [origin]  Origin the message claims to come from.
 * @return {Promise} Resolves once the controller has finished handling it.
 */
function messageFromEditor( editor, data, origin = window.location.origin ) {
	return editor.handleMessage( { data, source: editor.editorWindow, origin } );
}

/** Read the messages of a given type out of a postMessage log. */
function messagesOfType( posted, type ) {
	return posted.filter( ( entry ) => entry.message.type === type ).map( ( entry ) => entry.message );
}

afterEach( cleanupEditor );

describe( 'exelearning-editor: the meta box "Edit in eXeLearning" button', () => {
	it( 'opens the in-page modal instead of navigating away', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP + BUTTON_MARKUP );

		const opened = [];
		editor.open = ( attachmentId ) => opened.push( attachmentId );

		const prevented = clickEditButton();

		expect( opened ).toEqual( [ 42 ] );
		expect( prevented ).toBe( true );
	} );

	it( 'follows its href on a screen that has no modal', async () => {
		const editor = await loadEditorOn( BUTTON_MARKUP );

		const opened = [];
		editor.open = ( attachmentId ) => opened.push( attachmentId );

		const prevented = clickEditButton();

		expect( opened ).toEqual( [] );
		expect( prevented ).toBe( false );
	} );

	it( 'stops listening for window messages once the test cleanup runs', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP + BUTTON_MARKUP );

		const handled = [];
		editor.handleMessage = ( event ) => handled.push( event.data );

		cleanupEditor();
		window.dispatchEvent( new window.MessageEvent( 'message', { data: { type: 'DOCUMENT_CHANGED' } } ) );

		expect( handled ).toEqual( [] );
	} );
} );

describe( 'exelearning-editor: the pieces the rest is built on', () => {
	it( 'issues request ids that never repeat within a session', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		const first = editor.nextRequestId( 'export' );
		const second = editor.nextRequestId( 'export' );

		expect( first ).toMatch( /^export-\d+-1$/ );
		expect( second ).toMatch( /^export-\d+-2$/ );
		expect( second ).not.toBe( first );
	} );

	it( 'saves as elpx, whatever else the editor can export', async () => {
		// The modal writes back to the attachment, and the attachment is the .elpx. The
		// other formats are downloads, and they go through wp-exe-download.js instead.
		const editor = await loadEditorOn( MODAL_MARKUP );

		expect( editor.getFormat() ).toBe( 'elpx' );
	} );

	it( 'names a file after the format when the editor sends none', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		expect( editor.defaultFilenameForFormat( 'elpx' ) ).toBe( 'project.elpx' );
		expect( editor.defaultFilenameForFormat( 'epub3' ) ).toBe( 'project.epub' );
		expect( editor.defaultFilenameForFormat( 'epub' ) ).toBe( 'project.epub' );
		expect( editor.defaultFilenameForFormat( 'scorm12' ) ).toBe( 'project.zip' );
	} );

	it( 'reads the media type off the filename', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		expect( editor.mimeForFilename( 'book.epub' ) ).toBe( 'application/epub+zip' );
		expect( editor.mimeForFilename( 'course.zip' ) ).toBe( 'application/zip' );
		expect( editor.mimeForFilename( 'project.elpx' ) ).toBe( 'application/zip' );
	} );

	it( 'pins postMessage to the origin the iframe actually loaded', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.iframe.attr( 'src', 'https://editor.test/wp-admin/admin.php?page=exelearning-editor' );
		expect( editor.getEditorOrigin() ).toBe( 'https://editor.test' );

		editor.iframe.attr( 'src', '/wp-admin/admin.php?page=exelearning-editor' );
		expect( editor.getEditorOrigin() ).toBe( window.location.origin );
	} );
} );

describe( 'exelearning-editor: the format selector', () => {
	it( 'offers the three export formats with elpx preselected', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.insertFormatSelector();

		const select = document.getElementById( 'exelearning-editor-format' );
		expect( select ).not.toBeNull();
		expect( Array.from( select.options ).map( ( option ) => option.value ) ).toEqual( [
			'elpx',
			'scorm12',
			'epub3',
		] );
		expect( select.value ).toBe( 'elpx' );
		expect( select.nextElementSibling.id ).toBe( 'exelearning-editor-close' );
	} );

	it( 'does not add a second selector when called again', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.insertFormatSelector();
		editor.insertFormatSelector();

		expect( document.querySelectorAll( '#exelearning-editor-format' ) ).toHaveLength( 1 );
	} );
} );

describe( 'exelearning-editor: the saving overlay', () => {
	it( 'appears on save and goes away when saving ends', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.setSavingState( true );

		const modal = document.getElementById( 'exelearning-loading-modal' );
		expect( modal.classList.contains( 'is-visible' ) ).toBe( true );
		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( true );

		editor.setSavingState( false );

		expect( modal.classList.contains( 'is-visible' ) ).toBe( false );
		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( false );
	} );

	it( 'shows the reason when a save fails, and can be dismissed', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.showLoadingError( 'Save failed (503)' );

		const modal = document.getElementById( 'exelearning-loading-modal' );
		expect( modal.classList.contains( 'is-error' ) ).toBe( true );
		expect( modal.querySelector( '.exelearning-loading-modal__error-text' ).textContent ).toBe(
			'Save failed (503)'
		);

		modal.querySelector( '.exelearning-loading-modal__close' ).click();

		expect( modal.classList.contains( 'is-error' ) ).toBe( false );
		expect( modal.classList.contains( 'is-visible' ) ).toBe( false );
	} );

	it( 'clears a previous error when the next save starts', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.showLoadingError( 'Save failed (503)' );
		editor.showLoadingModal();

		const modal = document.getElementById( 'exelearning-loading-modal' );
		expect( modal.classList.contains( 'is-error' ) ).toBe( false );
		expect( modal.classList.contains( 'is-visible' ) ).toBe( true );
	} );

	it( 'reuses the same overlay across saves', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.showLoadingModal();
		editor.hideLoadingModal();
		editor.showLoadingModal();

		expect( document.querySelectorAll( '.exelearning-loading-modal' ) ).toHaveLength( 1 );
	} );
} );

describe( 'exelearning-editor: opening a file', () => {
	it( 'refuses to open without an attachment', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.open( 0 );

		expect( editor.isOpen ).toBe( false );
		expect( document.body.classList.contains( 'exelearning-editor-open' ) ).toBe( false );
	} );

	it( 'points the iframe at the file the editor should import', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		const requests = [];
		window.fetch = ( url, options ) => {
			requests.push( { url, options } );
			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { url: 'https://files.test/my project.elpx' } ),
			} );
		};

		editor.open( 42 );

		expect( editor.isOpen ).toBe( true );
		expect( document.body.classList.contains( 'exelearning-editor-open' ) ).toBe( true );
		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( true );

		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( requests[ 0 ].url ).toBe( '/wp-json/exelearning/v1/elp-data/42' );
		expect( requests[ 0 ].options.headers[ 'X-WP-Nonce' ] ).toBe( 'rest-nonce' );

		const src = editor.iframe.attr( 'src' );
		expect( src ).toContain( 'attachment_id=42' );
		expect( src ).toContain( '_wpnonce=editor-nonce' );
		expect( src ).toContain( 'import=' + encodeURIComponent( 'https://files.test/my project.elpx' ) );
	} );

	it( 'still opens the editor when the file URL cannot be resolved', async () => {
		// A failed lookup must not cost the user the editor: it opens empty rather than
		// not at all.
		const editor = await loadEditorOn( MODAL_MARKUP );
		window.fetch = () => Promise.reject( new Error( 'network down' ) );

		editor.open( 42 );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( editor.iframe.attr( 'src' ) ).toContain( 'attachment_id=42' );
		expect( editor.iframe.attr( 'src' ) ).not.toContain( 'import=' );
	} );

	it( 'omits the import when the lookup answers without a URL', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		window.fetch = () => Promise.resolve( { ok: false, json: () => Promise.resolve( {} ) } );

		editor.open( 42 );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( editor.iframe.attr( 'src' ) ).not.toContain( 'import=' );
	} );

	it( 'falls back to a separate window on a screen with no modal', async () => {
		const editor = await loadEditorOn( '<div></div>' );
		const opened = [];
		window.open = ( url, target, features ) => opened.push( { url, target, features } );

		editor.open( 42 );

		expect( opened ).toHaveLength( 1 );
		expect( opened[ 0 ].url ).toContain( 'attachment_id=42' );
		expect( opened[ 0 ].target ).toBe( '_blank' );
		expect( editor.isOpen ).toBe( false );
	} );
} );

describe( 'exelearning-editor: closing without losing work', () => {
	it( 'does nothing when the editor is not open', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		editor.close();

		expect( document.body.classList.contains( 'exelearning-editor-open' ) ).toBe( false );
	} );

	it( 'asks before discarding unsaved changes, and stays open on refusal', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		editor.isOpen = true;
		editor.hasUnsavedChanges = true;
		window.confirm = () => false;

		editor.close();

		expect( editor.isOpen ).toBe( true );
	} );

	it( 'closes when the reader confirms, and lets the iframe go', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		editor.isOpen = true;
		editor.hasUnsavedChanges = true;
		window.confirm = () => true;

		editor.close();

		expect( editor.isOpen ).toBe( false );
		expect( editor.hasUnsavedChanges ).toBe( false );
		expect( editor.currentAttachmentId ).toBeNull();
		expect( document.body.classList.contains( 'exelearning-editor-open' ) ).toBe( false );
		expect( editor.iframe.attr( 'src' ) ).toBe( 'about:blank' );
		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( false );
	} );

	it( 'skips the question when the caller already knows', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		editor.isOpen = true;
		editor.hasUnsavedChanges = true;
		let asked = false;
		window.confirm = () => {
			asked = true;
			return false;
		};

		editor.close( true );

		expect( asked ).toBe( false );
		expect( editor.isOpen ).toBe( false );
	} );

	it( 'treats a dirty document inside the editor as unsaved work', async () => {
		// The flag only tracks what the editor announced. A document the editor knows is
		// dirty but has not reported yet still counts.
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.editorWindow.eXeLearning = {
			app: { project: { _yjsBridge: { documentManager: { isDirty: true } } } },
		};

		expect( editor.checkUnsavedChanges() ).toBe( true );
	} );

	it( 'reports no unsaved work when neither side has any', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );

		expect( editor.checkUnsavedChanges() ).toBe( false );
	} );

	it( 'closes on Escape while open', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		editor.isOpen = true;

		$( document ).trigger( $.Event( 'keydown', { key: 'Escape' } ) );

		expect( editor.isOpen ).toBe( false );
	} );
} );

describe( 'exelearning-editor: the conversation with the editor', () => {
	it( 'hides the editor chrome WordPress provides itself', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		const posted = stubEditorWindow( editor );

		await messageFromEditor( editor, { type: 'EXELEARNING_READY' } );

		const configure = messagesOfType( posted, 'CONFIGURE' );
		expect( configure ).toHaveLength( 1 );
		expect( configure[ 0 ].data.hideUI ).toEqual( {
			fileMenu: true,
			saveButton: true,
			userMenu: true,
		} );
	} );

	it( 'enables saving once a document is loaded', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.saveBtn.prop( 'disabled', true );
		editor.hasUnsavedChanges = true;

		await messageFromEditor( editor, { type: 'DOCUMENT_LOADED' } );

		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( false );
		expect( editor.hasUnsavedChanges ).toBe( false );
	} );

	it( 'keeps the save button disabled while a save is in flight', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.isSaving = true;
		editor.saveBtn.prop( 'disabled', true );

		await messageFromEditor( editor, { type: 'DOCUMENT_LOADED' } );

		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( true );
	} );

	it( 'remembers that the document changed', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );

		await messageFromEditor( editor, { type: 'DOCUMENT_CHANGED' } );

		expect( editor.hasUnsavedChanges ).toBe( true );
	} );

	it( 'gives the save button back when the editor reports a failure', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.setSavingState( true );
		editor.exportRequestId = 'export-1';

		await messageFromEditor( editor, {
			type: 'WP_REQUEST_SAVE_ERROR',
			requestId: 'export-1',
			error: 'exporter blew up',
		} );

		expect( editor.isSaving ).toBe( false );
		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( false );
	} );

	it( 'ignores a failure that belongs to an earlier save', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.setSavingState( true );
		editor.exportRequestId = 'export-2';

		await messageFromEditor( editor, { type: 'WP_REQUEST_SAVE_ERROR', requestId: 'export-1' } );

		expect( editor.isSaving ).toBe( true );
	} );

	it( 'ignores messages from anywhere but the editor iframe', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );

		await editor.handleMessage( {
			data: { type: 'DOCUMENT_CHANGED' },
			source: { postMessage: () => {} },
			origin: window.location.origin,
		} );

		expect( editor.hasUnsavedChanges ).toBe( false );
	} );

	it( 'ignores messages from an origin other than the one it loaded', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.editorOrigin = 'https://editor.test';

		await messageFromEditor( editor, { type: 'DOCUMENT_CHANGED' }, 'https://evil.test' );

		expect( editor.hasUnsavedChanges ).toBe( false );
	} );

	it( 'accepts a save request relayed by the page itself', async () => {
		// The standalone editor page has no iframe of ours to post from, so its bridge
		// relays through the parent window with a marker instead.
		const editor = await loadEditorOn( MODAL_MARKUP );
		const posted = stubEditorWindow( editor );

		await editor.handleMessage( {
			data: { source: 'wp-exe-editor', type: 'request-save' },
			source: window,
			origin: window.location.origin,
		} );

		expect( messagesOfType( posted, 'WP_REQUEST_SAVE' ) ).toHaveLength( 1 );
	} );

	it( 'asks the editor for the file when the save button is pressed', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		const posted = stubEditorWindow( editor );

		document.getElementById( 'exelearning-editor-save' ).click();

		const requests = messagesOfType( posted, 'WP_REQUEST_SAVE' );
		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ].requestId ).toBe( editor.exportRequestId );
		expect( editor.isSaving ).toBe( true );
	} );

	it( 'does not ask twice while a save is already running', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		const posted = stubEditorWindow( editor );

		editor.requestSave();
		editor.requestSave();

		expect( messagesOfType( posted, 'WP_REQUEST_SAVE' ) ).toHaveLength( 1 );
	} );
} );

describe( 'exelearning-editor: writing the file back to WordPress', () => {
	/**
	 * Answer the save request with a canned REST response.
	 *
	 * @param {Object} response What fetch should resolve to.
	 * @return {Object[]} Array collecting the fetch calls.
	 */
	function stubRestApi( response ) {
		const calls = [];
		window.fetch = ( url, options ) => {
			calls.push( { url, options } );
			return Promise.resolve( response );
		};
		return calls;
	}

	it( 'updates the attachment the modal was opened on', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		const posted = stubEditorWindow( editor );
		editor.isOpen = true;
		editor.currentAttachmentId = 42;
		const calls = stubRestApi( {
			ok: true,
			json: () => Promise.resolve( { success: true, attachment_id: 42 } ),
		} );

		await editor.handleExportFile( {
			bytes: new Uint8Array( [ 1, 2, 3 ] ),
			filename: 'my-course.elpx',
			mimeType: 'application/zip',
		} );

		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ].url ).toBe( '/wp-json/exelearning/v1/save/42' );
		expect( calls[ 0 ].options.method ).toBe( 'POST' );
		expect( calls[ 0 ].options.headers[ 'X-WP-Nonce' ] ).toBe( 'rest-nonce' );

		// The filename is asserted server-side: happy-dom's FormData drops the third
		// argument of append(), so the name is not observable from here.
		const body = calls[ 0 ].options.body;
		expect( body.get( 'format' ) ).toBe( 'elpx' );
		expect( body.get( 'file' ).size ).toBe( 3 );
		expect( body.get( 'file' ).type ).toBe( 'application/zip' );

		expect( messagesOfType( posted, 'WP_SAVE_CONFIRMED' ) ).toHaveLength( 1 );
		expect( editor.isOpen ).toBe( false );
		expect( editor.hasUnsavedChanges ).toBe( false );
	} );

	it( 'creates a new attachment when the modal was opened on nothing', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.currentAttachmentId = null;
		const calls = stubRestApi( {
			ok: true,
			json: () => Promise.resolve( { success: true, attachmentId: 7 } ),
		} );

		await editor.handleExportFile( { bytes: new Uint8Array( [ 1 ] ) } );

		expect( calls[ 0 ].url ).toBe( '/wp-json/exelearning/v1/create' );
		expect( calls[ 0 ].options.body.get( 'file' ).size ).toBe( 1 );
	} );

	it( 'keeps the editor open and says why when WordPress rejects the save', async () => {
		// Closing here would throw away the only copy of the work: the file lives in the
		// editor and nowhere else until this request succeeds.
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.isOpen = true;
		editor.currentAttachmentId = 42;
		editor.setSavingState( true );
		stubRestApi( {
			ok: false,
			status: 413,
			json: () => Promise.resolve( { message: 'File too large' } ),
		} );

		await editor.handleExportFile( { bytes: new Uint8Array( [ 1 ] ) } );

		expect( editor.isOpen ).toBe( true );
		expect( editor.isSaving ).toBe( false );
		expect(
			document.querySelector( '.exelearning-loading-modal__error-text' ).textContent
		).toBe( 'File too large' );
		expect( document.getElementById( 'exelearning-editor-save' ).disabled ).toBe( false );
	} );

	it( 'treats a 200 that did not save as a failure', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		stubEditorWindow( editor );
		editor.isOpen = true;
		editor.currentAttachmentId = 42;
		stubRestApi( { ok: true, json: () => Promise.resolve( { success: false } ) } );

		await editor.handleExportFile( { bytes: new Uint8Array( [ 1 ] ) } );

		expect( editor.isOpen ).toBe( true );
		expect(
			document.querySelector( '.exelearning-loading-modal' ).classList.contains( 'is-error' )
		).toBe( true );
	} );
} );

describe( 'exelearning-editor: what the rest of the screen sees after a save', () => {
	/**
	 * Stand in for the block editor data store with a fixed set of blocks.
	 *
	 * @param {Object[]} blocks Blocks the store should report.
	 * @return {Object} Record of the updates and post saves the controller asked for.
	 */
	function stubBlockEditor( blocks ) {
		const record = { updates: [], postSaved: 0 };
		global.wp.data = {
			select: ( store ) =>
				store === 'core/block-editor' ? { getBlocks: () => blocks } : {},
			dispatch: ( store ) =>
				store === 'core/block-editor'
					? {
						updateBlockAttributes: ( clientId, attributes ) =>
							record.updates.push( { clientId, attributes } ),
					}
					: { savePost: () => ( record.postSaved += 1 ) },
		};
		return record;
	}

	it( 'repoints the block that holds the file just saved', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		const record = stubBlockEditor( [
			{ name: 'exelearning/elp-upload', clientId: 'a', attributes: { attachmentId: 42 } },
			{ name: 'exelearning/elp-upload', clientId: 'b', attributes: { attachmentId: 99 } },
			{ name: 'core/paragraph', clientId: 'c', attributes: {} },
		] );

		editor.updateBlockPreview( 42, 'https://files.test/preview.html' );

		expect( record.updates ).toEqual( [
			{ clientId: 'a', attributes: { previewUrl: 'https://files.test/preview.html' } },
		] );
		expect( record.postSaved ).toBe( 1 );
	} );

	it( 'leaves the post alone when no block was pointing at the file', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		const record = stubBlockEditor( [
			{ name: 'exelearning/elp-upload', clientId: 'b', attributes: { attachmentId: 99 } },
		] );

		editor.updateBlockPreview( 42, 'https://files.test/preview.html' );

		expect( record.updates ).toEqual( [] );
		expect( record.postSaved ).toBe( 0 );
	} );

	it( 'does nothing outside the block editor', async () => {
		// The modal also opens from the media library, where there is no block store.
		const editor = await loadEditorOn( MODAL_MARKUP );

		expect( () => editor.updateBlockPreview( 42, 'https://files.test/preview.html' ) ).not.toThrow();
	} );

	it( 'refreshes the attachment the media library is showing', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );
		document.body.insertAdjacentHTML(
			'beforeend',
			'<div class="attachment-details"><div class="thumbnail exelearning-details-preview-added"></div></div>' +
			'<span class="exelearning-metadata">old</span>'
		);
		const attachment = {
			data: {},
			triggered: [],
			get: ( key ) => attachment.data[ key ],
			set: ( key, value ) => ( attachment.data[ key ] = value ),
			fetch: () => ( { done: ( callback ) => ( callback(), { fail: () => {} } ) } ),
			trigger: ( event ) => attachment.triggered.push( event ),
		};
		global.wp.media = { attachment: () => attachment };

		editor.refreshAttachment( 42, 'https://files.test/preview.html' );

		expect( attachment.data.exelearning.preview_url ).toBe( 'https://files.test/preview.html' );
		expect( attachment.triggered ).toEqual( [ 'change' ] );
		expect(
			document.querySelector( '.thumbnail' ).classList.contains( 'exelearning-details-preview-added' )
		).toBe( false );
		expect( document.querySelector( '.exelearning-metadata' ) ).toBeNull();
	} );

	it( 'survives a media library that is not there', async () => {
		const editor = await loadEditorOn( MODAL_MARKUP );

		expect( () => editor.refreshAttachment( 42, 'https://files.test/preview.html' ) ).not.toThrow();
		expect( () => editor.refreshMediaLibrary() ).not.toThrow();
	} );
} );
