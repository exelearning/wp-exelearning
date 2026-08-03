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
		coverage: {
			provider: 'v8',
			reporter: [ 'text', 'lcov' ],
			reportsDirectory: 'artifacts/coverage-js',
			// Everything under assets/js is measured, not only the files that
			// happen to be under test: most of these scripts drive the browser
			// and are covered by the E2E suite instead, and hiding them would
			// report a prettier number rather than a true one.
			include: [ 'assets/js/**/*.js' ],
		},
	},
} );
