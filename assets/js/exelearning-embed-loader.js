/**
 * Loading state for embedded eXeLearning packages.
 *
 * The iframe has to download, parse and lay out a whole package before anything
 * paints, so without this the visitor stares at an empty bordered frame with no
 * clue whether it is loading or broken.
 *
 * Binds every `.exelearning-embed-loader` on the page in one pass, so a page
 * with several embeds costs one cached script instead of one inline copy per
 * block. The spinner is revealed from here, never server-side: a visitor
 * without JavaScript then never gets an overlay that nothing could clear.
 */
( function () {
	'use strict';

	/** Give up after this long so a failed embed cannot spin forever. */
	var BACKSTOP_MS = 20000;

	/**
	 * How far ahead of the viewport to arm the spinner.
	 *
	 * It has to be at least the browser's own lazy-loading fetch distance, which is
	 * far larger than "nearly visible" -- Chrome uses a viewport-distance threshold
	 * in the low thousands of pixels. A margin smaller than that arms the spinner
	 * only after the frame has already been fetched.
	 */
	var FETCH_MARGIN = '1500px';

	function bind( wrap ) {
		var iframe = wrap.querySelector( '.exelearning-iframe' );
		if ( ! iframe || wrap.dataset.exeLoaderBound ) {
			return;
		}
		wrap.dataset.exeLoaderBound = '1';

		// Once the frame has painted -- or failed, or outlived the backstop -- the
		// spinner must never appear again. The margin below is a heuristic about
		// someone else's fetch scheduling; this is the invariant that does not
		// depend on getting that heuristic right.
		var settled = false;

		var clear = function () {
			settled = true;
			wrap.classList.remove( 'is-loading' );
		};
		// `load` fires when the package finished downloading, but the eXeLearning
		// theme still builds its navigation right after, so clearing immediately
		// can uncover a frame that is still blank.
		var clearSoon = function () {
			setTimeout( clear, 150 );
		};

		var start = function () {
			if ( settled ) {
				return;
			}
			wrap.classList.add( 'is-loading' );
			setTimeout( clear, BACKSTOP_MS );
		};

		iframe.addEventListener( 'load', function () {
			// Settled now, not in 150 ms: the frame is done, and a scroll landing in
			// between must not be able to drop a spinner over the loaded content.
			settled = true;
			clearSoon();
		} );
		iframe.addEventListener( 'error', clear );

		// The iframe is `loading="lazy"`, so the browser owns the fetch schedule and
		// starts it long before the frame is on screen. Arming the spinner at parse
		// time would leave it over a frame nothing is fetching yet; arming it only
		// once the frame is nearly visible would leave it over a frame that already
		// finished. The margin covers the browser's fetch distance, and `settled`
		// covers whatever the margin gets wrong.
		if ( ! ( 'IntersectionObserver' in window ) ) {
			start();
			return;
		}
		var observer = new IntersectionObserver(
			function ( entries ) {
				if ( ! entries.some( function ( entry ) { return entry.isIntersecting; } ) ) {
					return;
				}
				observer.disconnect();
				start();
			},
			{ rootMargin: FETCH_MARGIN }
		);
		observer.observe( iframe );
	}

	function bindAll() {
		var wraps = document.querySelectorAll( '.exelearning-embed-loader' );
		Array.prototype.forEach.call( wraps, bind );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', bindAll );
	} else {
		bindAll();
	}
}() );
