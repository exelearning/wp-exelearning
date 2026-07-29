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

	/**
	 * Whether the frame finished loading before this script got a chance to listen.
	 *
	 * The loader is enqueued in the footer and binds no earlier than DOMContentLoaded,
	 * so an embed near the top of a long page -- a cached one especially -- can fire
	 * its load event before bind() ever runs. `load` is not re-emitted, so without
	 * asking the frame directly it would look unloaded forever and the spinner would
	 * cover finished content until the backstop expired.
	 *
	 * The embed is same-origin (the REST content proxy on this host) and keeps
	 * allow-same-origin in its sandbox, so its document is readable. The about:blank
	 * test is what makes reading it safe: a lazy frame whose fetch has NOT started
	 * yet also reports readyState 'complete', on the placeholder document it was
	 * created with. Treating that as loaded would suppress the spinner in exactly the
	 * case it exists for.
	 *
	 * @param {HTMLIFrameElement} iframe The embed frame.
	 * @return {boolean} True only when a real document has finished loading.
	 */
	function hasAlreadyLoaded( iframe ) {
		try {
			var doc = iframe.contentDocument;
			return !! doc &&
				'complete' === doc.readyState &&
				!! doc.location &&
				'about:blank' !== doc.location.href;
		} catch ( e ) {
			// Cross-origin, so unreadable. Fall through to the normal event flow.
			return false;
		}
	}

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

		// A frame that finished before we got here will never re-emit `load`, so ask
		// it directly instead of waiting for an event that already happened.
		if ( hasAlreadyLoaded( iframe ) ) {
			settled = true;
		}

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
