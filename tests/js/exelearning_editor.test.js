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

afterEach( () => {
	// jQuery keeps one native listener per event type on document, so a handler bound by
	// the previous copy of the script would still fire during the next test.
	$( document ).off();
	document.body.innerHTML = '';
	delete window.ExeLearningEditor;
	delete global.exelearningEditorVars;
} );

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
} );
