// Unit tests for assets/js/wp-exe-download.js.
//
// The script drives the split download button rendered by
// ExeLearning_Download_Button_Renderer. Two paths matter and they are not alike:
//
//   - `.elpx` is the uploaded attachment itself, fetched as a blob so that the download
//     goes through whatever serves the uploads (a Playground service worker, for one)
//     instead of a top-level navigation that bypasses it. If fetch is missing or fails,
//     a direct link is the fallback -- failing silently here means no file at all.
//   - Every other format is exported client-side inside a hidden editor iframe.
//     Whether the real editor answers WP_REQUEST_EXPORT is a question for the Playwright
//     suite, and it stays there -- no stub can answer it. What is unit-tested here is
//     this side of the boundary: the bootstrap URL, the readiness handshake, matching an
//     answer to the request that asked for it, and the timeouts. Those are ordinary
//     bookkeeping with ordinary bugs -- an answer accepted from the wrong window, a
//     requestId never cleared, a rejection that leaves the button spinning -- and none
//     of them need a real editor to go wrong.
//
// The script is an IIFE that reads window.wpExeDownloadConfig at load time and binds
// listeners on the document, so it is configured first and imported once for the file.
// It also keeps the iframe in module state, so the export tests use a fresh attachment
// id each time rather than depending on what the previous test left behind.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const SCRIPT = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/wp-exe-download.js' )
).href;

const EDITOR_URL = 'http://example.test/wp-content/plugins/exelearning/dist/static';

/** Downloads triggered through an anchor, in order. */
let downloads = [];
/** Object URLs handed out by the stubbed URL factory. */
let objectUrls = [];
/** Anything the script reported to the console. */
let consoleErrors = [];

let originalCreateObjectURL;
let originalRevokeObjectURL;
let originalConsoleError;
let originalAnchorClick;

beforeAll( async () => {
	window.wpExeDownloadConfig = {
		editorUrl: EDITOR_URL,
		exportBase: 'http://example.test/',
		i18n: { failed: 'No se pudo descargar. Inténtalo de nuevo.' },
	};

	// An anchor click would be a navigation, which is neither available nor the point:
	// what the test needs to know is the href and the filename it was given.
	originalAnchorClick = window.HTMLAnchorElement.prototype.click;
	window.HTMLAnchorElement.prototype.click = function () {
		downloads.push( { href: this.href, download: this.download } );
	};

	originalCreateObjectURL = window.URL.createObjectURL;
	originalRevokeObjectURL = window.URL.revokeObjectURL;
	window.URL.createObjectURL = ( blob ) => {
		const url = `blob:mock/${ objectUrls.length }`;
		objectUrls.push( { url, blob, revoked: false } );
		return url;
	};
	window.URL.revokeObjectURL = ( url ) => {
		const entry = objectUrls.find( ( candidate ) => candidate.url === url );
		if ( entry ) {
			entry.revoked = true;
		}
	};

	originalConsoleError = console.error;
	console.error = ( ...args ) => consoleErrors.push( args.join( ' ' ) );

	await import( /* @vite-ignore */ SCRIPT );
} );

afterAll( () => {
	window.HTMLAnchorElement.prototype.click = originalAnchorClick;
	window.URL.createObjectURL = originalCreateObjectURL;
	window.URL.revokeObjectURL = originalRevokeObjectURL;
	console.error = originalConsoleError;
} );

beforeEach( () => {
	downloads = [];
	objectUrls = [];
	consoleErrors = [];
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.fetch;
} );

/**
 * Render the markup of the split download button, dropdown and all.
 *
 * @return {HTMLElement} The download container.
 */
function renderButton() {
	document.body.innerHTML =
		'<div class="exelearning-download" data-attachment-id="42"' +
			' data-elp-url="http://example.test/uploads/project.elpx" data-slug="project">' +
			'<button type="button" class="exelearning-download__primary"' +
				' data-format="elpx" data-suffix=".elpx">Download</button>' +
			'<button type="button" class="exelearning-download__toggle"' +
				' aria-expanded="false">More</button>' +
			'<div class="exelearning-download__menu" hidden>' +
				'<button type="button" data-format="scorm12" data-suffix="_scorm.zip">SCORM</button>' +
			'</div>' +
		'</div>';

	return document.querySelector( '.exelearning-download' );
}

/** Click an element the way the page would. */
function click( element ) {
	element.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
}

/** Let the promise chain inside the script settle. */
async function settle() {
	for ( let i = 0; i < 6; i++ ) {
		await Promise.resolve();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}
}

describe( 'wp-exe-download: the format menu', () => {
	it( 'opens on the toggle and reports the state to assistive tech', () => {
		const container = renderButton();

		click( container.querySelector( '.exelearning-download__toggle' ) );

		const menu = container.querySelector( '.exelearning-download__menu' );
		expect( menu.hidden ).toBe( false );
		expect( menu.getAttribute( 'data-open' ) ).toBe( '1' );
		expect(
			container.querySelector( '.exelearning-download__toggle' ).getAttribute( 'aria-expanded' )
		).toBe( 'true' );
	} );

	it( 'closes again on a second click of the toggle', () => {
		const container = renderButton();
		const toggle = container.querySelector( '.exelearning-download__toggle' );

		click( toggle );
		click( toggle );

		const menu = container.querySelector( '.exelearning-download__menu' );
		expect( menu.hidden ).toBe( true );
		expect( menu.hasAttribute( 'data-open' ) ).toBe( false );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'closes on a click anywhere else on the page', () => {
		const container = renderButton();
		document.body.insertAdjacentHTML( 'beforeend', '<p id="elsewhere">Text</p>' );

		click( container.querySelector( '.exelearning-download__toggle' ) );
		click( document.getElementById( 'elsewhere' ) );

		expect( container.querySelector( '.exelearning-download__menu' ).hidden ).toBe( true );
	} );

	it( 'closes on Escape', () => {
		const container = renderButton();

		click( container.querySelector( '.exelearning-download__toggle' ) );
		document.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
		);

		expect( container.querySelector( '.exelearning-download__menu' ).hidden ).toBe( true );
	} );

	it( 'leaves other keys alone', () => {
		const container = renderButton();

		click( container.querySelector( '.exelearning-download__toggle' ) );
		document.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'a', bubbles: true } )
		);

		expect( container.querySelector( '.exelearning-download__menu' ).hidden ).toBe( false );
	} );

	it( 'does nothing for a toggle with no menu behind it', () => {
		document.body.innerHTML =
			'<div class="exelearning-download" data-attachment-id="42">' +
			'<button class="exelearning-download__toggle" aria-expanded="false">More</button></div>';

		click( document.querySelector( '.exelearning-download__toggle' ) );

		expect(
			document.querySelector( '.exelearning-download__toggle' ).getAttribute( 'aria-expanded' )
		).toBe( 'false' );
	} );
} );

describe( 'wp-exe-download: downloading the .elpx itself', () => {
	it( 'fetches the attachment and downloads it as a blob', async () => {
		const container = renderButton();
		const blob = { size: 10 };
		window.fetch = () => Promise.resolve( { ok: true, blob: () => Promise.resolve( blob ) } );

		click( container.querySelector( '[data-format="elpx"]' ) );
		await settle();

		expect( downloads ).toEqual( [
			{ href: 'blob:mock/0', download: 'project.elpx' },
		] );
		expect( objectUrls[ 0 ].blob ).toBe( blob );
		expect( container.hasAttribute( 'data-busy' ) ).toBe( false );
	} );

	it( 'marks the button busy while the fetch is in flight', async () => {
		const container = renderButton();
		let release;
		window.fetch = () => new Promise( ( resolve ) => {
			release = () => resolve( { ok: true, blob: () => Promise.resolve( {} ) } );
		} );

		click( container.querySelector( '[data-format="elpx"]' ) );
		await Promise.resolve();

		expect( container.getAttribute( 'data-busy' ) ).toBe( '1' );

		release();
		await settle();

		expect( container.hasAttribute( 'data-busy' ) ).toBe( false );
	} );

	it( 'falls back to the direct URL when the fetch fails', async () => {
		const container = renderButton();
		window.fetch = () => Promise.reject( new Error( 'offline' ) );

		click( container.querySelector( '[data-format="elpx"]' ) );
		await settle();

		expect( downloads ).toEqual( [
			{ href: 'http://example.test/uploads/project.elpx', download: 'project.elpx' },
		] );
	} );

	it( 'falls back to the direct URL on an HTTP error', async () => {
		const container = renderButton();
		window.fetch = () => Promise.resolve( { ok: false, status: 404 } );

		click( container.querySelector( '[data-format="elpx"]' ) );
		await settle();

		expect( downloads ).toEqual( [
			{ href: 'http://example.test/uploads/project.elpx', download: 'project.elpx' },
		] );
	} );

	it( 'falls back to the direct URL where fetch does not exist', async () => {
		renderButton();

		await window.wpExeDownload.downloadFormat( {
			format: 'elpx',
			suffix: '.elpx',
			attachmentId: 42,
			elpUrl: 'http://example.test/uploads/project.elpx',
			slug: 'project',
		} );

		expect( downloads ).toEqual( [
			{ href: 'http://example.test/uploads/project.elpx', download: 'project.elpx' },
		] );
	} );

	it( 'rejects when there is no attachment URL to download', async () => {
		await expect(
			window.wpExeDownload.downloadFormat( { format: 'elpx', attachmentId: 42 } )
		).rejects.toThrow( 'Missing .elpx URL' );
	} );
} );

// The editor iframe never really loads under Vitest: happy-dom is configured not to
// fetch iframe pages, and it signals that by firing `error` on every one of them --
// which is exactly what the script reads as a broken editor. So the harness gives the
// script an iframe it can drive: the src is recorded instead of being applied, the
// contentWindow is a stub that collects postMessage calls, and the error handler is
// held back so a load failure is something a test asks for rather than something the
// environment does on its own. Everything else about the element is a real element.
let frames = [];
let restoreCreateElement = null;

/** Make every iframe the script creates from now on inert and observable. */
function installIframeHarness() {
	const realCreateElement = document.createElement.bind( document );
	frames = [];

	document.createElement = ( tagName, options ) => {
		const element = realCreateElement( tagName, options );
		if ( 'iframe' !== String( tagName ).toLowerCase() ) {
			return element;
		}

		const frame = { element, src: null, posted: [], errorHandlers: [] };
		frame.contentWindow = {
			postMessage: ( message ) => frame.posted.push( message ),
		};

		Object.defineProperty( element, 'src', {
			configurable: true,
			get: () => '',
			set: ( value ) => {
				frame.src = value;
			},
		} );
		Object.defineProperty( element, 'contentWindow', {
			configurable: true,
			value: frame.contentWindow,
		} );

		const realAddEventListener = element.addEventListener.bind( element );
		element.addEventListener = ( type, fn, opts ) => {
			if ( 'error' === type ) {
				frame.errorHandlers.push( fn );
				return;
			}
			realAddEventListener( type, fn, opts );
		};

		frames.push( frame );
		return element;
	};

	restoreCreateElement = () => {
		document.createElement = realCreateElement;
	};
}

/** The iframe the script is currently working with. */
function currentFrame() {
	return frames[ frames.length - 1 ];
}

/** Deliver a message to the page as if the editor iframe had sent it. */
function fromEditor( frame, data ) {
	window.dispatchEvent(
		new window.MessageEvent( 'message', { data, source: frame.contentWindow } )
	);
}

/** Report the editor inside the frame as loaded and ready to export. */
function reportReady( frame ) {
	fromEditor( frame, { type: 'EXELEARNING_READY' } );
}

/** The requestId of the export the script last asked this iframe for. */
function lastRequestId( frame ) {
	const request = frame.posted[ frame.posted.length - 1 ];
	return request && request.requestId;
}

/** Answer the frame's outstanding export request with some bytes. */
function answerExport( frame, extra ) {
	fromEditor( frame, {
		type: 'WP_EXPORT_FILE',
		requestId: lastRequestId( frame ),
		bytes: new Uint8Array( [ 1 ] ),
		...extra,
	} );
}

/**
 * Append a second download button without disturbing what is already on the page.
 *
 * renderButton() replaces the whole body, which would silently detach the script's
 * hidden iframe and make "the old iframe was removed" true for the wrong reason.
 *
 * @param {number} attachmentId Attachment the button downloads.
 * @return {HTMLElement} The appended container.
 */
function appendButton( attachmentId ) {
	document.body.insertAdjacentHTML(
		'beforeend',
		'<div class="exelearning-download" data-attachment-id="' + attachmentId + '"' +
			' data-elp-url="http://example.test/uploads/project.elpx" data-slug="mi-curso"></div>'
	);
	return document.body.lastElementChild;
}

/** Attachment ids are never reused: the script caches its iframe per attachment. */
let nextAttachmentId = 1000;

describe( 'wp-exe-download: exporting through the editor iframe', () => {
	beforeEach( () => {
		installIframeHarness();
	} );

	afterEach( () => {
		restoreCreateElement();
	} );

	it( 'refuses to export without an attachment id', async () => {
		await expect(
			window.wpExeDownload.downloadFormat( { format: 'scorm12' } )
		).rejects.toThrow( 'Missing attachment id or editor URL' );
		expect( consoleErrors.join( ' ' ) ).toContain( 'Missing attachment id or editor URL' );
	} );

	/**
	 * Start an export and get as far as the iframe asking the editor for it.
	 *
	 * @param {Object} [params] Overrides for the downloadFormat call.
	 * @return {Promise<Object>} The pending download, its iframe and its container.
	 */
	async function startExport( params ) {
		const container = ( params && params.container ) || renderButton();
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'scorm12',
			suffix: '_scorm.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'mi-curso',
			...params,
			container,
		} );

		await settle();
		const frame = currentFrame();
		reportReady( frame );
		await settle();

		return { pending, frame, container };
	}

	it( 'points the hidden iframe at the export bootstrap for that attachment', async () => {
		const attachmentId = ++nextAttachmentId;
		const { pending, frame } = await startExport( { attachmentId } );

		const url = new URL( frame.src );
		expect( url.origin + url.pathname ).toBe( 'http://example.test/' );
		expect( url.searchParams.get( 'exe_export' ) ).toBe( '1' );
		expect( url.searchParams.get( 'attachment_id' ) ).toBe( String( attachmentId ) );
		expect( frame.element.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( frame.element.getAttribute( 'tabindex' ) ).toBe( '-1' );

		answerExport( frame );
		await pending;
	} );

	it( 'asks the editor for the format and downloads what comes back', async () => {
		const { pending, frame, container } = await startExport();

		expect( frame.posted ).toHaveLength( 1 );
		expect( frame.posted[ 0 ].type ).toBe( 'WP_REQUEST_EXPORT' );
		expect( frame.posted[ 0 ].data ).toEqual( { format: 'scorm12' } );

		answerExport( frame, {
			bytes: new Uint8Array( [ 1, 2, 3 ] ),
			filename: 'ignorado.zip',
			mimeType: 'application/zip',
		} );
		await pending;

		expect( downloads ).toEqual( [
			{ href: 'blob:mock/0', download: 'mi-curso_scorm.zip' },
		] );
		expect( objectUrls[ 0 ].blob.type ).toBe( 'application/zip' );
		expect( container.hasAttribute( 'data-busy' ) ).toBe( false );
	} );

	it( 'labels an EPUB export as an EPUB when the editor did not say', async () => {
		const { pending, frame } = await startExport( { format: 'epub3', suffix: '.epub' } );

		answerExport( frame );
		await pending;

		expect( objectUrls[ 0 ].blob.type ).toBe( 'application/epub+zip' );
		expect( downloads[ 0 ].download ).toBe( 'mi-curso.epub' );
	} );

	it( 'marks the button busy for as long as the export runs', async () => {
		const { pending, frame, container } = await startExport();

		expect( container.getAttribute( 'data-busy' ) ).toBe( '1' );

		answerExport( frame );
		await pending;

		expect( container.hasAttribute( 'data-busy' ) ).toBe( false );
	} );

	it( 'reuses the iframe for a second export of the same attachment', async () => {
		const attachmentId = ++nextAttachmentId;
		const first = await startExport( { attachmentId } );
		answerExport( first.frame );
		await first.pending;

		const pending = window.wpExeDownload.downloadFormat( {
			format: 'ims',
			suffix: '_ims.zip',
			attachmentId,
			slug: 'mi-curso',
			container: appendButton( attachmentId ),
		} );
		await settle();

		// No second iframe and no second handshake: the editor is already loaded.
		expect( frames ).toHaveLength( 1 );
		answerExport( first.frame, { bytes: new Uint8Array( [ 2 ] ) } );
		await pending;

		expect( downloads[ 1 ].download ).toBe( 'mi-curso_ims.zip' );
	} );

	it( 'removes the old iframe when a different attachment is exported', async () => {
		const first = await startExport();
		answerExport( first.frame );
		await first.pending;

		expect( first.frame.element.parentNode ).not.toBeNull();

		// A second attachment, with the first iframe still on the page, so the
		// assertion below is about disposal and not about renderButton() having
		// wiped the body.
		const second = await startExport( { container: appendButton( ++nextAttachmentId ) } );

		expect( frames ).toHaveLength( 2 );
		expect( first.frame.element.parentNode ).toBeNull();
		expect( second.frame.element.parentNode ).not.toBeNull();

		answerExport( second.frame, { bytes: new Uint8Array( [ 2 ] ) } );
		await second.pending;
	} );
} );

describe( 'wp-exe-download: answers the export must not accept', () => {
	// The page listens on window for the editor's replies. Anything that gets past
	// these checks resolves somebody else's export with the wrong bytes.

	beforeEach( () => {
		installIframeHarness();
	} );

	afterEach( () => {
		restoreCreateElement();
	} );

	/** Start an export that is sitting waiting for its answer. */
	async function pendingExport() {
		const container = renderButton();
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'html5',
			suffix: '_web.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'curso',
			container,
		} );
		await settle();
		const frame = currentFrame();
		reportReady( frame );
		await settle();
		return { pending, frame, container };
	}

	it( 'ignores an answer that came from some other window', async () => {
		const { pending, frame } = await pendingExport();
		const impostor = { postMessage: () => {} };

		window.dispatchEvent(
			new window.MessageEvent( 'message', {
				data: {
					type: 'WP_EXPORT_FILE',
					requestId: lastRequestId( frame ),
					bytes: new Uint8Array( [ 6, 6, 6 ] ),
				},
				source: impostor,
			} )
		);
		await settle();

		expect( downloads ).toEqual( [] );

		// Still waiting, and the real answer still works.
		answerExport( frame );
		await pending;
		expect( downloads ).toHaveLength( 1 );
	} );

	it( 'ignores an answer to a request nobody made', async () => {
		const { pending, frame } = await pendingExport();

		fromEditor( frame, {
			type: 'WP_EXPORT_FILE',
			requestId: 'exe-9999',
			bytes: new Uint8Array( [ 9 ] ),
		} );
		await settle();

		expect( downloads ).toEqual( [] );

		answerExport( frame );
		await pending;
	} );

	it( 'ignores an answer with no requestId at all', async () => {
		const { pending, frame } = await pendingExport();

		fromEditor( frame, { type: 'WP_EXPORT_FILE', bytes: new Uint8Array( [ 9 ] ) } );
		await settle();

		expect( downloads ).toEqual( [] );

		answerExport( frame );
		await pending;
	} );

	it( 'surfaces an export the editor could not produce', async () => {
		const { pending, frame, container } = await pendingExport();

		fromEditor( frame, {
			type: 'WP_REQUEST_EXPORT_ERROR',
			requestId: lastRequestId( frame ),
			error: 'Exporter crashed',
		} );

		await expect( pending ).rejects.toThrow( 'Exporter crashed' );
		expect( container.querySelector( '.exelearning-download__status' ).textContent ).toBe(
			'No se pudo descargar. Inténtalo de nuevo.'
		);
		expect( container.hasAttribute( 'data-busy' ) ).toBe( false );
	} );

	it( 'treats an answer carrying an error as a failure whatever its type', async () => {
		const { pending, frame } = await pendingExport();

		fromEditor( frame, {
			type: 'WP_EXPORT_FILE',
			requestId: lastRequestId( frame ),
			error: 'Out of memory',
		} );

		await expect( pending ).rejects.toThrow( 'Out of memory' );
	} );

	it( 'reports an editor iframe that fails to load', async () => {
		const container = renderButton();
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'html5',
			suffix: '_web.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'curso',
			container,
		} );
		await settle();

		currentFrame().errorHandlers.forEach( ( fn ) => fn() );

		await expect( pending ).rejects.toThrow( 'iframe failed to load' );
		expect( container.querySelector( '.exelearning-download__status' ) ).not.toBeNull();
	} );

	it( 'exports without a container without tripping over the missing UI', async () => {
		// The block editor toolbar calls downloadFormat directly, with no container
		// to put a spinner or an error message on.
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'html5',
			suffix: '_web.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'curso',
		} );
		await settle();

		const frame = currentFrame();
		reportReady( frame );
		await settle();
		answerExport( frame );

		await expect( pending ).resolves.toBeUndefined();
		expect( downloads[ 0 ].download ).toBe( 'curso_web.zip' );
	} );
} );

describe( 'wp-exe-download: exports that never finish', () => {
	// A hung export must end in a rejection and a button that is usable again.
	// Waiting on the real 45s and 120s deadlines is not an option, so this block
	// drives the clock.

	beforeEach( () => {
		installIframeHarness();
		vi.useFakeTimers();
	} );

	afterEach( () => {
		vi.useRealTimers();
		restoreCreateElement();
	} );

	/** settle(), but for a test whose timers are under its own control. */
	async function settleFake() {
		for ( let i = 0; i < 6; i++ ) {
			await Promise.resolve();
			await vi.advanceTimersByTimeAsync( 0 );
		}
	}

	it( 'gives up on an editor that never reports itself ready', async () => {
		const container = renderButton();
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'html5',
			suffix: '_web.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'curso',
			container,
		} );
		await settleFake();

		// The handler goes on before the clock moves: the rejection lands during
		// advanceTimersByTimeAsync, and an unhandled one fails the run.
		const rejected = expect( pending ).rejects.toThrow( 'export iframe timeout' );
		await vi.advanceTimersByTimeAsync( 45000 );
		await rejected;

		expect( container.hasAttribute( 'data-busy' ) ).toBe( false );
	} );

	it( 'gives up on an export the editor accepted and never answered', async () => {
		const container = renderButton();
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'html5',
			suffix: '_web.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'curso',
			container,
		} );
		await settleFake();
		reportReady( currentFrame() );
		await settleFake();

		const rejected = expect( pending ).rejects.toThrow( 'Export timed out' );
		await vi.advanceTimersByTimeAsync( 120000 );
		await rejected;

		expect( container.hasAttribute( 'data-busy' ) ).toBe( false );
	} );

	it( 'clears the failure message once the reader has had time to see it', async () => {
		const container = renderButton();
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'html5',
			suffix: '_web.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'curso',
			container,
		} );
		await settleFake();
		currentFrame().errorHandlers.forEach( ( fn ) => fn() );
		await expect( pending ).rejects.toThrow( 'iframe failed to load' );

		expect( container.querySelector( '.exelearning-download__status' ) ).not.toBeNull();

		await vi.advanceTimersByTimeAsync( 4000 );

		expect( container.querySelector( '.exelearning-download__status' ) ).toBeNull();
	} );

	it( 'releases the object URL after handing the blob to the browser', async () => {
		// Holding every exported package in memory for the life of the tab would be
		// a leak measured in tens of megabytes.
		const container = renderButton();
		const pending = window.wpExeDownload.downloadFormat( {
			format: 'html5',
			suffix: '_web.zip',
			attachmentId: ++nextAttachmentId,
			slug: 'curso',
			container,
		} );
		await settleFake();
		reportReady( currentFrame() );
		await settleFake();
		answerExport( currentFrame() );
		await pending;

		expect( objectUrls[ 0 ].revoked ).toBe( false );

		await vi.advanceTimersByTimeAsync( 1000 );

		expect( objectUrls[ 0 ].revoked ).toBe( true );
	} );
} );
