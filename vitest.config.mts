import { defineConfig } from 'vitest/config';

// Vitest config for the plugin's JavaScript unit tests. The scripts under
// assets/js/ are plain IIFEs loaded by wp_enqueue_script, so the suite gives them a
// window/document (happy-dom) and exercises them through the DOM, exactly as
// WordPress does. Scripts that also need jQuery load the real jquery package as a
// global per-test.
//
// One suite here is not just a unit test: assets/js/exe-embed-relay.js is a MIRROR of
// the canonical mod_exelearning source, and its tests mirror that project's RELAY
// describe-blocks so drift in the validate()/makePlayer()/sync() logic is caught on
// this side too. The auto-running shim (assets/js/exe-embed-shim.js) is not covered.
export default defineConfig( {
	test: {
		globals: true,
		environment: 'happy-dom',
		include: [ 'tests/js/**/*.test.js' ],
	},
} );
