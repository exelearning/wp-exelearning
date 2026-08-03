// Unit tests for assets/js/wp-exe-download.js.
//
// The script drives the split download button rendered by
// ExeLearning_Download_Button_Renderer. Two paths matter and they are not alike:
//
//   - `.elpx` is the uploaded attachment itself, fetched as a blob so that the download
//     goes through whatever serves the uploads (a Playground service worker, for one)
//     instead of a top-level navigation that bypasses it. If fetch is missing or fails,
//     a direct link is the fallback -- failing silently here means no file at all.
//   - Every other format is exported client-side inside a hidden editor iframe. Only
//     the refusals are unit-tested: the handshake itself needs an iframe that really
//     loads the editor and answers by postMessage, which is what the Playwright suite
//     gives it. Faking it here would test the fake.
//
// The script is an IIFE that reads window.wpExeDownloadConfig at load time and binds
// listeners on the document, so it is configured first and imported once for the file.

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

describe( 'wp-exe-download: exporting through the editor iframe', () => {
	it( 'refuses to export without an attachment id', async () => {
		await expect(
			window.wpExeDownload.downloadFormat( { format: 'scorm12' } )
		).rejects.toThrow( 'Missing attachment id or editor URL' );
		expect( consoleErrors.join( ' ' ) ).toContain( 'Missing attachment id or editor URL' );
	} );
} );
