import { defineConfig } from 'vitest/config';

// Vitest config for the plugin's JavaScript unit tests. Scope: the secure-mode
// external-embed relay (assets/js/exe-embed-relay.js). The relay is a MIRROR of the
// canonical mod_exelearning source; these tests mirror its RELAY describe-blocks so
// drift in the validate()/makePlayer()/sync() logic is caught here too. The auto-running
// shim (assets/js/exe-embed-shim.js) is not unit-tested here.
export default defineConfig( {
	test: {
		globals: true,
		// happy-dom gives the relay a window/document for createRelay() and the overlay
		// DOM work, matching the canonical embedder's own setup.
		environment: 'happy-dom',
		include: [ 'tests/js/**/*.test.js' ],
	},
} );
