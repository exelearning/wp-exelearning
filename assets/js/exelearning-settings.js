/**
 * eXeLearning settings screen helpers.
 *
 * The only behavior the screen needs beyond native forms is a confirmation
 * guard on the per-style Delete links (nonced admin-post links). Everything
 * else — saving, uploading, responsive tables — is plain WordPress.
 *
 * @package Exelearning
 */

( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '.exelearning-delete-style' );
		if ( ! link ) {
			return;
		}
		var message = link.getAttribute( 'data-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} )();
