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

	function bind( wrap ) {
		var iframe = wrap.querySelector( '.exelearning-iframe' );
		if ( ! iframe || wrap.dataset.exeLoaderBound ) {
			return;
		}
		wrap.dataset.exeLoaderBound = '1';

		var clear = function () {
			wrap.classList.remove( 'is-loading' );
		};
		// `load` fires when the package finished downloading, but the eXeLearning
		// theme still builds its navigation right after, so clearing immediately
		// can uncover a frame that is still blank.
		var clearSoon = function () {
			setTimeout( clear, 150 );
		};

		var start = function () {
			wrap.classList.add( 'is-loading' );
			setTimeout( clear, BACKSTOP_MS );
		};

		iframe.addEventListener( 'load', clearSoon );
		iframe.addEventListener( 'error', clear );

		// The iframe is `loading="lazy"`: a block below the fold has not started
		// loading yet, so showing the spinner at parse time would leave it
		// spinning over an idle frame until the backstop. Wait until it is close
		// enough for the browser to actually fetch it.
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
			{ rootMargin: '200px' }
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
