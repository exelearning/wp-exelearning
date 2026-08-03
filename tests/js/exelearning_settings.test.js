// Unit tests for assets/js/exelearning-settings.js.
//
// The script guards the per-style Delete links on the settings screen. Those links are
// nonced admin-post URLs, so letting a click through deletes a style with no way back:
// the guard is the only thing between a stray click and a lost upload. What matters is
// which clicks it blocks and, just as much, which it leaves alone.
//
// The script is an IIFE with no exports that binds one delegated listener on the
// document. It is imported once for the whole file, exactly as the browser loads it:
// importing per test would stack a second listener on the same document and every
// click would be handled twice.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );

const SETTINGS = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/exelearning-settings.js' )
).href;

beforeAll( async () => {
	await import( /* @vite-ignore */ SETTINGS );
} );

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

afterEach( () => {
	document.body.innerHTML = '';
	delete window.confirm;
} );

describe( 'exelearning-settings: the delete confirmation guard', () => {
	it( 'blocks the deletion when the confirmation is declined', () => {
		document.body.innerHTML =
			'<a class="exelearning-delete-style" href="/admin-post.php" data-confirm="Delete this style?">Delete</a>';
		window.confirm = () => false;

		expect( clickAndReportBlocked( document.querySelector( 'a' ) ) ).toBe( true );
	} );

	it( 'lets the deletion through when the confirmation is accepted', () => {
		document.body.innerHTML =
			'<a class="exelearning-delete-style" href="/admin-post.php" data-confirm="Delete this style?">Delete</a>';
		window.confirm = () => true;

		expect( clickAndReportBlocked( document.querySelector( 'a' ) ) ).toBe( false );
	} );

	it( 'passes the link message to the confirmation', () => {
		document.body.innerHTML =
			'<a class="exelearning-delete-style" href="/admin-post.php" data-confirm="Delete “Blue”?">Delete</a>';
		const asked = [];
		window.confirm = ( message ) => {
			asked.push( message );
			return true;
		};

		clickAndReportBlocked( document.querySelector( 'a' ) );

		expect( asked ).toEqual( [ 'Delete “Blue”?' ] );
	} );

	it( 'asks nothing for a delete link that carries no message', () => {
		// The markup builds the message server-side; a link without one is a link the
		// screen did not mean to guard, and stopping it would break the delete instead.
		document.body.innerHTML =
			'<a class="exelearning-delete-style" href="/admin-post.php">Delete</a>';
		let asked = false;
		window.confirm = () => {
			asked = true;
			return false;
		};

		expect( clickAndReportBlocked( document.querySelector( 'a' ) ) ).toBe( false );
		expect( asked ).toBe( false );
	} );

	it( 'guards a click that lands on a child of the link', () => {
		// WordPress puts icons and screen-reader text inside admin links, so the click
		// target is usually not the anchor itself.
		document.body.innerHTML =
			'<a class="exelearning-delete-style" href="/admin-post.php" data-confirm="Delete this style?">' +
			'<span class="label">Delete</span></a>';
		window.confirm = () => false;

		expect( clickAndReportBlocked( document.querySelector( '.label' ) ) ).toBe( true );
	} );

	it( 'ignores clicks on anything else on the screen', () => {
		document.body.innerHTML =
			'<a class="exelearning-delete-style" href="/x" data-confirm="Delete?">Delete</a>' +
			'<button id="save">Save changes</button>';
		let asked = false;
		window.confirm = () => {
			asked = true;
			return false;
		};

		expect( clickAndReportBlocked( document.getElementById( 'save' ) ) ).toBe( false );
		expect( asked ).toBe( false );
	} );
} );
