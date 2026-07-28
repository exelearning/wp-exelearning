// Unit tests for assets/js/exelearning-embed-loader.js.
//
// The loader is enqueued in the footer and binds no earlier than DOMContentLoaded, so
// it routinely arrives after an embed has already finished: `load` has fired and will
// not fire again. Everything here is about that ordering, because getting it wrong is
// worse than having no spinner at all -- the overlay lands on content the visitor is
// already reading and stays for the whole backstop.
//
// The loader is an IIFE with no exports, so each test imports a fresh copy under a
// cache-busting URL and drives it through the DOM.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const LOADER = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/exelearning-embed-loader.js' )
).href;

let loadCount = 0;
let observers = [];

/**
 * Install a controllable IntersectionObserver so intersection is a test decision
 * rather than a layout accident.
 */
function installIntersectionObserver() {
	observers = [];
	window.IntersectionObserver = class {
		constructor( callback, options ) {
			this.callback = callback;
			this.options = options;
			this.targets = [];
			observers.push( this );
		}
		observe( element ) {
			this.targets.push( element );
		}
		disconnect() {
			this.disconnected = true;
		}
		/** Test hook: report every observed target as intersecting. */
		intersect() {
			this.callback( this.targets.map( ( target ) => ( { target, isIntersecting: true } ) ) );
		}
	};
}

/**
 * Build the wrapper markup the block renders, with a controllable contentDocument.
 *
 * @param {Object}  frame            What the embed frame should report.
 * @param {string}  [frame.readyState] Document readyState, or null for no document.
 * @param {string}  [frame.href]     Document location.href.
 * @param {boolean} [frame.throws]   Whether reading contentDocument throws (cross-origin).
 * @return {HTMLElement} The loader wrapper.
 */
function buildEmbed( frame ) {
	document.body.innerHTML =
		'<div class="exelearning-embed-loader">' +
			'<iframe class="exelearning-iframe" loading="lazy"></iframe>' +
			'<div class="exelearning-embed-loader__spinner" role="status" aria-live="polite">Loading…</div>' +
		'</div>';

	const iframe = document.querySelector( '.exelearning-iframe' );
	Object.defineProperty( iframe, 'contentDocument', {
		configurable: true,
		get() {
			if ( frame.throws ) {
				throw new Error( 'cross-origin' );
			}
			if ( ! frame.readyState ) {
				return null;
			}
			return { readyState: frame.readyState, location: { href: frame.href } };
		},
	} );

	return document.querySelector( '.exelearning-embed-loader' );
}

/** Import a fresh copy of the loader, which binds on import. */
async function runLoader() {
	await import( /* @vite-ignore */ `${ LOADER }?load=${ ++loadCount }` );
}

beforeEach( () => {
	installIntersectionObserver();
} );

afterEach( () => {
	document.body.innerHTML = '';
	observers = [];
} );

describe( 'exelearning-embed-loader: a frame that finished before the loader bound', () => {
	it( 'never covers an embed whose document already loaded', async () => {
		const wrap = buildEmbed( { readyState: 'complete', href: 'http://example.test/embed.html' } );

		await runLoader();
		observers[ 0 ].intersect();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( false );
	} );

	it( 'still covers a lazy embed whose fetch has not started', async () => {
		// A lazy frame the browser has not fetched yet reports readyState 'complete' on
		// its about:blank placeholder. Reading that as "loaded" would suppress the
		// spinner in the one case it exists for.
		const wrap = buildEmbed( { readyState: 'complete', href: 'about:blank' } );

		await runLoader();
		observers[ 0 ].intersect();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( true );
	} );

	it( 'still covers an embed that is mid-flight', async () => {
		const wrap = buildEmbed( { readyState: 'loading', href: 'http://example.test/embed.html' } );

		await runLoader();
		observers[ 0 ].intersect();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( true );
	} );

	it( 'falls back to the event flow when the document is unreadable', async () => {
		// Cross-origin: we cannot know, so behave as before rather than guess.
		const wrap = buildEmbed( { throws: true } );

		await runLoader();
		observers[ 0 ].intersect();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( true );
	} );

	it( 'still covers a frame that reports no document at all', async () => {
		const wrap = buildEmbed( { readyState: null } );

		await runLoader();
		observers[ 0 ].intersect();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( true );
	} );
} );

describe( 'exelearning-embed-loader: without IntersectionObserver', () => {
	it( 'arms the spinner immediately rather than never', async () => {
		delete window.IntersectionObserver;
		const wrap = buildEmbed( { readyState: 'loading', href: 'about:blank' } );

		await runLoader();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( true );
	} );

	it( 'still leaves a frame that already loaded uncovered', async () => {
		delete window.IntersectionObserver;
		const wrap = buildEmbed( { readyState: 'complete', href: 'http://example.test/embed.html' } );

		await runLoader();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( false );
	} );
} );

describe( 'exelearning-embed-loader: the normal ordering still works', () => {
	it( 'covers the frame on intersection and clears it on load', async () => {
		const wrap = buildEmbed( { readyState: 'loading', href: 'about:blank' } );
		const iframe = document.querySelector( '.exelearning-iframe' );

		await runLoader();
		observers[ 0 ].intersect();
		expect( wrap.classList.contains( 'is-loading' ) ).toBe( true );

		iframe.dispatchEvent( new window.Event( 'load' ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( false );
	} );

	it( 'does not arm the spinner after a load that arrived first', async () => {
		const wrap = buildEmbed( { readyState: 'loading', href: 'about:blank' } );
		const iframe = document.querySelector( '.exelearning-iframe' );

		await runLoader();
		iframe.dispatchEvent( new window.Event( 'load' ) );
		observers[ 0 ].intersect();

		expect( wrap.classList.contains( 'is-loading' ) ).toBe( false );
	} );
} );
