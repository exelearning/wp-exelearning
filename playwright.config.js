/**
 * Playwright configuration for WordPress E2E tests.
 *
 * @see https://playwright.dev/docs/test-configuration
 */

/** @type {import('@playwright/test').PlaywrightTestConfig} */
const config = {
	testDir: './tests/e2e',
	timeout: 30000,
	expect: {
		timeout: 5000,
	},
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: [
		['list'],
		['html', { outputFolder: 'artifacts/playwright-report', open: 'never' }],
	],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	outputDir: 'artifacts/test-results',
	projects: [
		{
			name: 'chromium',
			use: {
				browserName: 'chromium',
			},
		},
	],
};

module.exports = config;
