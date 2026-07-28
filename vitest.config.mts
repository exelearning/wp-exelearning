import { defineConfig } from 'vitest/config';

// Vitest config for the plugin's JavaScript unit tests. The scripts under
// assets/js/ are plain IIFEs loaded by wp_enqueue_script, so the suite gives them a
// window/document (happy-dom) and exercises them through the DOM, exactly as
// WordPress does. Scripts that also need jQuery load the real jquery package as a
// global per-test.
export default defineConfig( {
	test: {
		globals: true,
		environment: 'happy-dom',
		include: [ 'tests/js/**/*.test.js' ],
	},
} );
