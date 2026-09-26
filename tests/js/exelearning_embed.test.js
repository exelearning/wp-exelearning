// Unit tests for assets/js/exelearning-embed.js.
//
// The script replaces the per-instance inline <script> blocks the shortcode and the
// block used to print. One enqueued file now serves every embed on the page, so the
// interesting cases are the ones an inline copy got for free and delegation does not:
// several embeds side by side, a click landing on the icon inside a button rather than
// on the button, and the fullscreen button pressed while the poster is still covering
// a frame that has no src yet.
//
// The script is an IIFE with no exports and binds one delegated listener on the
// document, so it is imported once for the whole file -- exactly as a page enqueues it
// once -- and every test drives it through the DOM.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const EMBED = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/exelearning-embed.js' )
).href;

/** Load the script, which binds its delegated listener on import. */
beforeAll( async () => {
	await import( /* @vite-ignore */ EMBED );
} );

/**
 * Render one embed as the shortcode prints it.
 *
 * @param {Object}  options              Embed shape.
 * @param {string}  options.id           Container id.
 * @param {boolean} [options.poster]     Whether the iframe is deferred behind a poster.
 * @param {boolean} [options.fullscreen] Whether the toolbar carries the fullscreen button.
 * @return {string} Container markup.
 */
function embedMarkup( { id, poster = false, fullscreen = false } ) {
	return (
		`<div class="exelearning-shortcode exelearning-preview" id="${ id }">` +
			'<div class="exelearning-toolbar"><div class="exelearning-toolbar-actions">' +
				( fullscreen
					? '<button type="button" class="exelearning-toolbar-btn exelearning-fullscreen-btn">' +
						'<span class="dashicons dashicons-fullscreen-alt"></span>' +
					'</button>'
					: '' ) +
			'</div></div>' +
			( poster
				? '<button type="button" class="exelearning-poster">' +
					'<img class="exelearning-poster-img" alt="" />' +
				'</button>'
				: '' ) +
			'<iframe class="exelearning-iframe" ' +
				( poster
					? 'data-src="about:blank?embed" style="display: none;"'
					: 'src="about:blank?embed"' ) +
			'></iframe>' +
		'</div>'
	);
}

/**
 * Give every iframe a spy for the fullscreen request, which happy-dom does not implement.
 *
 * @return {Function[]} One call recorder per iframe, in document order.
 */
function stubFullscreen() {
	return Array.prototype.map.call(
		document.querySelectorAll( '.exelearning-iframe' ),
		( iframe ) => {
			const calls = [];
			iframe.requestFullscreen = () => calls.push( 1 );
			return calls;
		}
	);
}

/** Click an element the way a visitor does, so the listener on document sees it. */
function click( element ) {
	element.dispatchEvent( new window.Event( 'click', { bubbles: true } ) );
}

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'exelearning-embed: the fullscreen button', () => {
	it( 'does not reach a neighboring embed when its own frame is missing', () => {
		document.body.innerHTML =
			embedMarkup( { id: 'exelearning-1', fullscreen: true } ) +
			embedMarkup( { id: 'exelearning-2', fullscreen: true } );
		document.querySelector( '#exelearning-1 iframe' ).remove();
		const [ neighbor ] = stubFullscreen();

		click( document.querySelector( '#exelearning-1 .exelearning-fullscreen-btn' ) );

		expect( neighbor.length ).toBe( 0 );
	} );

	it( 'fullscreens the frame of the embed it belongs to', () => {
		document.body.innerHTML =
			embedMarkup( { id: 'exelearning-1', fullscreen: true } ) +
			embedMarkup( { id: 'exelearning-2', fullscreen: true } );
		const [ first, second ] = stubFullscreen();

		click( document.querySelectorAll( '.exelearning-fullscreen-btn' )[ 1 ] );

		expect( first.length ).toBe( 0 );
		expect( second.length ).toBe( 1 );
	} );

	it( 'works when the click lands on the icon inside the button', () => {
		document.body.innerHTML = embedMarkup( { id: 'exelearning-1', fullscreen: true } );
		const [ frame ] = stubFullscreen();

		click( document.querySelector( '.dashicons' ) );

		expect( frame.length ).toBe( 1 );
	} );

	it( 'does nothing on a click outside any embed', () => {
		document.body.innerHTML =
			'<button type="button" class="some-other-button">x</button>' +
			embedMarkup( { id: 'exelearning-1', fullscreen: true } );
		const [ frame ] = stubFullscreen();

		click( document.querySelector( '.some-other-button' ) );

		expect( frame.length ).toBe( 0 );
	} );

	it( 'falls back to the prefixed request when the standard one is missing', () => {
		document.body.innerHTML = embedMarkup( { id: 'exelearning-1', fullscreen: true } );
		const iframe = document.querySelector( '.exelearning-iframe' );
		const calls = [];
		iframe.requestFullscreen = undefined;
		iframe.webkitRequestFullscreen = () => calls.push( 1 );

		click( document.querySelector( '.exelearning-fullscreen-btn' ) );

		expect( calls.length ).toBe( 1 );
	} );
} );

describe( 'exelearning-embed: the poster', () => {
	it( 'loads and reveals the deferred frame on click', () => {
		document.body.innerHTML = embedMarkup( { id: 'exelearning-1', poster: true } );
		const iframe = document.querySelector( '.exelearning-iframe' );

		click( document.querySelector( '.exelearning-poster' ) );

		expect( iframe.getAttribute( 'src' ) ).toBe( 'about:blank?embed' );
		expect( iframe.style.display ).toBe( '' );
		expect( document.querySelector( '.exelearning-poster' ).style.display ).toBe( 'none' );
	} );

	it( 'does not reload a frame that is already showing', () => {
		document.body.innerHTML = embedMarkup( { id: 'exelearning-1', poster: true } );
		const iframe = document.querySelector( '.exelearning-iframe' );
		iframe.setAttribute( 'src', 'about:blank?embed#page-3' );

		click( document.querySelector( '.exelearning-poster' ) );

		expect( iframe.getAttribute( 'src' ) ).toBe( 'about:blank?embed#page-3' );
	} );

	it( 'only touches the embed that was clicked', () => {
		document.body.innerHTML =
			embedMarkup( { id: 'exelearning-1', poster: true } ) +
			embedMarkup( { id: 'exelearning-2', poster: true } );

		click( document.querySelectorAll( '.exelearning-poster' )[ 0 ] );

		const frames = document.querySelectorAll( '.exelearning-iframe' );
		expect( frames[ 0 ].getAttribute( 'src' ) ).toBe( 'about:blank?embed' );
		expect( frames[ 1 ].getAttribute( 'src' ) ).toBe( null );
	} );
} );

describe( 'exelearning-embed: fullscreen pressed before the poster', () => {
	it( 'loads and reveals the frame before requesting fullscreen', () => {
		// Requesting fullscreen on a hidden frame with no src would expand nothing.
		document.body.innerHTML = embedMarkup( {
			id: 'exelearning-1',
			poster: true,
			fullscreen: true,
		} );
		const [ frame ] = stubFullscreen();
		const iframe = document.querySelector( '.exelearning-iframe' );

		click( document.querySelector( '.exelearning-fullscreen-btn' ) );

		expect( iframe.getAttribute( 'src' ) ).toBe( 'about:blank?embed' );
		expect( iframe.style.display ).toBe( '' );
		expect( frame.length ).toBe( 1 );
	} );
} );

describe( 'exelearning-embed: embeds added after load', () => {
	it( 'serves an embed injected into the page later', () => {
		// Delegation is what buys this: a lazy-loaded or AJAX-inserted embed needs no
		// second copy of the script and no re-binding pass.
		document.body.innerHTML = embedMarkup( { id: 'exelearning-late', fullscreen: true } );
		const [ frame ] = stubFullscreen();
		click( document.querySelector( '.exelearning-fullscreen-btn' ) );

		expect( frame.length ).toBe( 1 );
	} );
} );
