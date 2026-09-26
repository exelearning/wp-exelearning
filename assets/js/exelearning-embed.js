/**
 * Toolbar and poster behavior for embedded eXeLearning packages.
 *
 * One enqueued script serves shortcode and block controls, including embeds
 * inserted after load. Each click stays within its own embed container.
 */
( function () {
	'use strict';

	/**
	 * Promote a deferred frame: load it, reveal it and drop the poster.
	 *
	 * In poster mode the iframe ships with its URL in `data-src` and hidden, so the
	 * package is downloaded only when the visitor asks for it. Called both by the
	 * poster itself and by the fullscreen button, which must not expand a hidden
	 * frame that has no document yet.
	 *
	 * @param {Element} container The embed.
	 * @return {void}
	 */
	function activate( container ) {
		var iframe = container.querySelector( '.exelearning-iframe' );
		if ( ! iframe ) {
			return;
		}

		var deferred = iframe.getAttribute( 'data-src' );
		if ( deferred && ! iframe.getAttribute( 'src' ) ) {
			iframe.setAttribute( 'src', deferred );
		}

		iframe.style.display = '';

		var poster = container.querySelector( '.exelearning-poster' );
		if ( poster ) {
			poster.style.display = 'none';
		}
	}

	/**
	 * Take the embed frame fullscreen, whatever the browser calls it.
	 *
	 * @param {Element} container The embed.
	 * @return {void}
	 */
	function fullscreen( container ) {
		var iframe = container.querySelector( '.exelearning-iframe' );
		if ( ! iframe ) {
			return;
		}

		if ( iframe.requestFullscreen ) {
			iframe.requestFullscreen();
		} else if ( iframe.webkitRequestFullscreen ) {
			iframe.webkitRequestFullscreen();
		} else if ( iframe.msRequestFullscreen ) {
			iframe.msRequestFullscreen();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var control = target.closest( '.exelearning-poster, .exelearning-fullscreen-btn' );
		if ( ! control ) {
			return;
		}

		var container = control.closest( '.exelearning-preview, .exelearning-block-frontend' );
		if ( ! container ) {
			return;
		}

		// The fullscreen button in poster mode loads and reveals the frame first:
		// expanding a hidden, srcless frame would fill the screen with nothing.
		activate( container );

		if ( control.classList.contains( 'exelearning-fullscreen-btn' ) ) {
			fullscreen( container );
		}
	} );
}() );
