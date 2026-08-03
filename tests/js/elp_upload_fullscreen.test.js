// Unit tests for assets/js/elp-upload-fullscreen.js.
//
// The script drives the fullscreen button of the eXeLearning block inside the Gutenberg
// editor. Gutenberg rebuilds block markup constantly, so the button routinely outlives
// the preview iframe it points at: the script keeps the two in step through a
// MutationObserver. A button left enabled with no iframe behind it does nothing when
// clicked, which reads as a broken editor, so most of what follows is about that pairing.
//
// The script is an IIFE with no exports that binds a delegated click listener and one
// observer on the document. It is imported once for the whole file, exactly as the
// browser loads it: importing per test would stack a second listener and a second
// observer on the same document, and every click would be handled twice.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const SCRIPT = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/elp-upload-fullscreen.js' )
).href;

/** Let the MutationObserver callbacks for the current batch run. */
async function flushMutations() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * Build the block markup Gutenberg renders for the eXeLearning block.
 *
 * @param {Object}  options            Markup options.
 * @param {boolean} options.withIframe Whether the preview iframe is present.
 * @param {string}  [options.label]    Button contents.
 * @return {string} Block HTML.
 */
function blockMarkup( { withIframe, label = 'Fullscreen' } ) {
	return (
		'<div data-type="exelearning/elp-upload">' +
			'<div class="exelearning-block-preview">' +
				( withIframe ? '<iframe src="about:blank"></iframe>' : '' ) +
			'</div>' +
			'<button type="button" class="exelearning-fullscreen-btn">' + label + '</button>' +
		'</div>'
	);
}

/**
 * Record the fullscreen requests made on the preview iframe.
 *
 * @param {string|null} api Fullscreen method the browser is said to support, if any.
 * @return {string[]} Array that collects the names of the methods called.
 */
function stubFullscreenApi( api ) {
	const calls = [];
	const iframe = document.querySelector( 'iframe' );

	delete iframe.requestFullscreen;
	delete iframe.webkitRequestFullscreen;
	delete iframe.msRequestFullscreen;

	if ( api ) {
		iframe[ api ] = () => calls.push( api );
	}

	return calls;
}

/**
 * Click an element and report whether the default action was prevented.
 *
 * @param {Element} element Element to click.
 * @return {boolean} Whether the click was blocked.
 */
function clickAndReportBlocked( element ) {
	const event = new window.MouseEvent( 'click', { bubbles: true, cancelable: true } );
	element.dispatchEvent( event );
	return event.defaultPrevented;
}

// State of the two buttons present when the script bound, which is the one thing the
// initial sweep can be observed through -- every later change goes through the observer.
const atLoad = {};

beforeAll( async () => {
	document.body.innerHTML =
		'<div id="with">' + blockMarkup( { withIframe: true } ) + '</div>' +
		'<div id="without">' + blockMarkup( { withIframe: false } ) + '</div>';

	await import( /* @vite-ignore */ SCRIPT );

	const button = ( id ) => document.querySelector( `#${ id } .exelearning-fullscreen-btn` );
	atLoad.withPreview = {
		disabled: button( 'with' ).disabled,
		aria: button( 'with' ).getAttribute( 'aria-disabled' ),
	};
	atLoad.withoutPreview = {
		disabled: button( 'without' ).disabled,
		aria: button( 'without' ).getAttribute( 'aria-disabled' ),
	};

	document.body.innerHTML = '';
} );

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'elp-upload-fullscreen: the sweep at load time', () => {
	it( 'enables a button whose block already has a preview', () => {
		expect( atLoad.withPreview ).toEqual( { disabled: false, aria: 'false' } );
	} );

	it( 'disables a button whose block has none', () => {
		expect( atLoad.withoutPreview ).toEqual( { disabled: true, aria: 'true' } );
	} );
} );

describe( 'elp-upload-fullscreen: the button follows its preview', () => {
	it( 'enables the button once the preview turns up later', async () => {
		document.body.innerHTML = blockMarkup( { withIframe: false } );
		await flushMutations();

		document
			.querySelector( '.exelearning-block-preview' )
			.appendChild( document.createElement( 'iframe' ) );
		await flushMutations();

		expect( document.querySelector( '.exelearning-fullscreen-btn' ).disabled ).toBe( false );
	} );

	it( 'disables the button again when the preview goes away', async () => {
		document.body.innerHTML = blockMarkup( { withIframe: true } );
		await flushMutations();

		document.querySelector( 'iframe' ).remove();
		await flushMutations();

		expect( document.querySelector( '.exelearning-fullscreen-btn' ).disabled ).toBe( true );
	} );

	it( 'syncs a whole block inserted after binding', async () => {
		const holder = document.createElement( 'div' );
		holder.innerHTML = blockMarkup( { withIframe: true } );
		document.body.appendChild( holder );
		await flushMutations();

		expect( document.querySelector( '.exelearning-fullscreen-btn' ).disabled ).toBe( false );
	} );

	it( 'leaves buttons outside an eXeLearning block alone', async () => {
		document.body.innerHTML =
			'<div data-type="core/paragraph">' +
			'<button class="exelearning-fullscreen-btn">Fullscreen</button></div>';
		await flushMutations();

		const button = document.querySelector( '.exelearning-fullscreen-btn' );
		expect( button.disabled ).toBe( false );
		expect( button.hasAttribute( 'aria-disabled' ) ).toBe( false );
	} );
} );

describe( 'elp-upload-fullscreen: clicking the button', () => {
	it( 'opens the preview fullscreen with the standard API', async () => {
		document.body.innerHTML = blockMarkup( { withIframe: true } );
		await flushMutations();
		const calls = stubFullscreenApi( 'requestFullscreen' );

		const blocked = clickAndReportBlocked(
			document.querySelector( '.exelearning-fullscreen-btn' )
		);

		expect( calls ).toEqual( [ 'requestFullscreen' ] );
		expect( blocked ).toBe( true );
	} );

	it( 'falls back to the WebKit API', async () => {
		document.body.innerHTML = blockMarkup( { withIframe: true } );
		await flushMutations();
		const calls = stubFullscreenApi( 'webkitRequestFullscreen' );

		clickAndReportBlocked( document.querySelector( '.exelearning-fullscreen-btn' ) );

		expect( calls ).toEqual( [ 'webkitRequestFullscreen' ] );
	} );

	it( 'falls back to the Microsoft API', async () => {
		document.body.innerHTML = blockMarkup( { withIframe: true } );
		await flushMutations();
		const calls = stubFullscreenApi( 'msRequestFullscreen' );

		clickAndReportBlocked( document.querySelector( '.exelearning-fullscreen-btn' ) );

		expect( calls ).toEqual( [ 'msRequestFullscreen' ] );
	} );

	it( 'still swallows the click when no fullscreen API exists', async () => {
		document.body.innerHTML = blockMarkup( { withIframe: true } );
		await flushMutations();
		stubFullscreenApi( null );

		expect(
			clickAndReportBlocked( document.querySelector( '.exelearning-fullscreen-btn' ) )
		).toBe( true );
	} );

	it( 'disables the button when the preview vanished before the click', async () => {
		// The observer may not have caught up with Gutenberg yet, so the click handler
		// re-checks instead of trusting the button state it finds.
		document.body.innerHTML = blockMarkup( { withIframe: true } );
		await flushMutations();
		document.querySelector( 'iframe' ).remove();

		const button = document.querySelector( '.exelearning-fullscreen-btn' );
		expect( clickAndReportBlocked( button ) ).toBe( true );
		expect( button.disabled ).toBe( true );
	} );

	it( 'reacts to a click on a child of the button', async () => {
		document.body.innerHTML = blockMarkup( {
			withIframe: true,
			label: '<span class="label">Fullscreen</span>',
		} );
		await flushMutations();
		const calls = stubFullscreenApi( 'requestFullscreen' );

		clickAndReportBlocked( document.querySelector( '.label' ) );

		expect( calls ).toEqual( [ 'requestFullscreen' ] );
	} );

	it( 'ignores clicks elsewhere in the editor', async () => {
		document.body.innerHTML =
			blockMarkup( { withIframe: true } ) + '<button id="publish">Publish</button>';
		await flushMutations();
		const calls = stubFullscreenApi( 'requestFullscreen' );

		expect( clickAndReportBlocked( document.getElementById( 'publish' ) ) ).toBe( false );
		expect( calls ).toEqual( [] );
	} );
} );
