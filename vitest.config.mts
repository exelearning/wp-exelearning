import { defineConfig } from 'vitest/config';

// Vitest config for the plugin's JavaScript unit tests. The admin scripts under
// assets/js/ are plain jQuery IIFEs loaded by wp_enqueue_script, so the suite gives
// them a window/document and a global jQuery and then exercises them through the DOM,
// exactly as WordPress does.
export default defineConfig( {
	test: {
		globals: true,
		environment: 'happy-dom',
		include: [ 'tests/js/**/*.test.js' ],
	},
} );
