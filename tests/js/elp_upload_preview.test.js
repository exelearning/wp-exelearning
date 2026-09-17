// Unit tests for the block editor's preview wiring.
//
// The editor's preview used to be the one surface that ran an uploaded package with
// `allow-same-origin` — giving untrusted content the plugin's own origin in the very place
// where the person viewing it is the person holding credentials to the site. Making it
// opaque removes that, and takes with it the DOM injection the teacher-mode toggle relied
// on, so the toggle now travels the way the front end has always sent it: on the URL.
//
// These are the two pure decisions behind that change, tested without a block editor.
const editor = require( '../../assets/js/elp-upload.js' );

const PREVIEW = 'http://example.test/wp-json/exelearning/v1/content/' + 'a'.repeat( 40 ) + '/index.html';

describe( 'sandboxTokens()', () => {
	afterEach( () => {
		delete window.exeLearningBlockEditor;
	} );

	it( 'uses the tokens PHP localized, so the editor cannot drift from the front end', () => {
		window.exeLearningBlockEditor = { sandboxTokens: 'allow-scripts allow-popups allow-forms' };

		expect( editor.sandboxTokens() ).toBe( 'allow-scripts allow-popups allow-forms' );
	} );

	/**
	 * Fails SAFE. If the localized data never arrives, an over-restrictive preview is a
	 * visible bug someone reports; an over-permissive one is a silent hole nobody sees.
	 */
	it( 'falls back to the secure set, never to same-origin', () => {
		expect( editor.sandboxTokens() ).not.toContain( 'allow-same-origin' );
	} );
} );

describe( 'withTeacherMode()', () => {
	it( 'asks the package to reveal the teacher layer through the URL', () => {
		expect( editor.withTeacherMode( PREVIEW, true ) ).toBe( PREVIEW + '?exe-teacher=1' );
	} );

	it( 'leaves the URL untouched when the layer stays hidden', () => {
		expect( editor.withTeacherMode( PREVIEW, false ) ).toBe( PREVIEW );
	} );

	it( 'appends to a URL that already carries a query', () => {
		expect( editor.withTeacherMode( PREVIEW + '?v=2', true ) ).toBe( PREVIEW + '?v=2&exe-teacher=1' );
	} );

	/**
	 * Toggling twice must not accumulate. The editor rebuilds this src on every render, so
	 * a duplicate would grow without bound as the author flips the switch.
	 */
	it( 'does not add the flag twice', () => {
		expect( editor.withTeacherMode( PREVIEW + '?exe-teacher=1', true ) ).toBe( PREVIEW + '?exe-teacher=1' );
	} );
} );
