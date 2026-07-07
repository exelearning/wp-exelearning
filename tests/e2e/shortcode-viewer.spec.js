/**
 * E2E tests for the [exelearning] shortcode viewer on the public frontend.
 *
 * These run against the local wp-env tests environment (never the public
 * Gobierno de Canarias URL, which is only a visual reference). Fixtures are
 * seeded through WP-CLI (tests/e2e/seed-shortcode-fixtures.php) so the embed
 * renders a real, proxied preview with a clean console.
 *
 * @package Exelearning
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

/**
 * IDs of the seeded scenario pages, filled in by the global beforeAll seed.
 *
 * @type {{attachmentId:number, hash:string, pages:Object<string,number>}}
 */
let fixtures;

/**
 * A plugin-owned URL is either a bundled asset (…/plugins/exelearning/…) or the
 * plugin's content-proxy / REST namespace (…/exelearning/v1/…). A failed load
 * of one of these is a genuine plugin regression and must fail the test.
 *
 * @param {string} url Request URL.
 * @return {boolean} Whether the URL is generated/owned by the plugin.
 */
function isPluginUrl(url) {
	return url.includes('/plugins/exelearning/') || url.includes('/exelearning/v1/');
}

/**
 * Console/network noise that is NOT caused by this plugin and must only be
 * recorded, never fail the test:
 *  - ERR_BLOCKED_BY_CLIENT: ad-blocker / browser-extension blocking.
 *  - CSP "report-only": non-blocking policy reports.
 *  - the known iframe sandbox note (allow-scripts + allow-same-origin): this PR
 *    does not change the sandbox, so the warning is expected and tolerated.
 *
 * @param {string} text Message or error text.
 * @return {boolean} Whether the message is a tolerated non-plugin warning.
 */
function isTolerated(text) {
	const lower = text.toLowerCase();
	return (
		lower.includes('err_blocked_by_client') ||
		lower.includes('report-only') ||
		lower.includes('report only') ||
		(lower.includes('allow-scripts') && lower.includes('allow-same-origin'))
	);
}

/**
 * Attach console/error/network listeners and classify what should fail the test.
 *
 * Hard failures (plugin-generated JavaScript errors / asset failures):
 *  - uncaught page exceptions (the plugin's inline viewer script is the only
 *    plugin JS on these pages);
 *  - failed requests to plugin-owned URLs (bundle 404s, broken proxy iframe).
 * Everything tolerated is kept in `ignored` for visibility.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @return {{failures:Array, ignored:Array}} Collected classification buckets.
 */
function watchConsole(page) {
	const failures = [];
	const ignored = [];

	page.on('pageerror', (err) => {
		failures.push(`pageerror: ${String(err)}`);
	});

	page.on('console', (msg) => {
		if (msg.type() !== 'error') {
			return;
		}
		const text = msg.text();
		// A failed subresource surfaces its URL in msg.location().url, not the
		// message text, so classify against both.
		const location = msg.location();
		const locationUrl = location ? location.url : '';
		if (isTolerated(text) || isTolerated(locationUrl)) {
			ignored.push(`console: ${text}`);
			return;
		}
		// Only plugin-referencing console errors are actionable here; generic
		// theme/core noise is out of scope for this PR.
		if (isPluginUrl(text) || isPluginUrl(locationUrl)) {
			failures.push(`console: ${text} @ ${locationUrl}`);
		} else {
			ignored.push(`console: ${text}`);
		}
	});

	page.on('requestfailed', (req) => {
		const url = req.url();
		const failure = req.failure();
		const text = failure ? failure.errorText : '';
		if (isTolerated(text) || isTolerated(url)) {
			ignored.push(`requestfailed: ${url} (${text})`);
			return;
		}
		if (isPluginUrl(url)) {
			failures.push(`requestfailed: ${url} (${text})`);
		} else {
			ignored.push(`requestfailed: ${url} (${text})`);
		}
	});

	// requestfailed only fires for transport-level failures (DNS, connection
	// refused, aborted, ERR_BLOCKED_BY_CLIENT) — NOT for HTTP 4xx/5xx, which
	// complete at the network layer. Catch error-status responses on plugin-
	// owned URLs directly so a 404 on a bundled asset or a broken proxied iframe
	// document actually fails the test.
	page.on('response', (response) => {
		const url = response.url();
		if (isPluginUrl(url) && response.status() >= 400) {
			failures.push(`response ${response.status()}: ${url}`);
		}
	});

	return { failures, ignored };
}

/**
 * Navigate to a seeded scenario page by ID (permalink-structure independent).
 *
 * @param {import('@playwright/test').Page} page Page under test.
 * @param {string} key Scenario key from the seed manifest.
 */
async function gotoScenario(page, key) {
	const id = fixtures.pages[key];
	expect(id, `seeded page for scenario "${key}"`).toBeTruthy();
	await page.goto(`/?page_id=${id}`);
}

test.beforeAll(() => {
	// The wp-env tests site ships with no active theme, which renders every
	// frontend page empty. Activate a classic theme so page content (and our
	// shortcode) actually renders. Idempotent: re-activating is a no-op.
	execSync('npx wp-env run tests-cli wp theme activate twentytwentyone', {
		encoding: 'utf8',
	});

	// Seed fixtures through WP-CLI in the tests container. `wp-env run` execs
	// inside the container, so this is independent of the host port mapping.
	const raw = execSync(
		'npx wp-env run tests-cli --env-cwd=wp-content/plugins/exelearning '
			+ 'wp eval-file tests/e2e/seed-shortcode-fixtures.php',
		{ encoding: 'utf8' }
	);

	const marker = 'EXE_E2E_JSON:';
	const idx = raw.indexOf(marker);
	if (idx === -1) {
		throw new Error(`Seed script did not emit a manifest. Output:\n${raw}`);
	}
	fixtures = JSON.parse(raw.slice(idx + marker.length).split('\n')[0].trim());
});

test.describe('Shortcode viewer (public frontend)', () => {
	test('default embed renders the eXeLearning iframe', async ({ page }) => {
		await gotoScenario(page, 'default');

		// Rendered on a public page, not wp-admin.
		expect(page.url()).not.toContain('/wp-admin/');
		await expect(page.locator('.exelearning-shortcode.exelearning-preview')).toBeVisible();
		await expect(page.locator('iframe.exelearning-iframe')).toBeVisible();
	});

	test('default embed renders the fullscreen button', async ({ page }) => {
		await gotoScenario(page, 'default');
		await expect(page.locator('.exelearning-fullscreen-btn')).toBeVisible();
	});

	test('fullscreen="0" hides the fullscreen button', async ({ page }) => {
		await gotoScenario(page, 'nofs');
		await expect(page.locator('iframe.exelearning-iframe')).toBeVisible();
		await expect(page.locator('.exelearning-fullscreen-btn')).toHaveCount(0);
	});

	test('fullscreen="1" shows the fullscreen button', async ({ page }) => {
		await gotoScenario(page, 'fs');
		await expect(page.locator('.exelearning-fullscreen-btn')).toBeVisible();
	});

	test('height="75%" is applied to the iframe', async ({ page }) => {
		await gotoScenario(page, 'height');
		// A percentage height can compute to 0 without a sized parent, so assert
		// on the inline style rather than the element's rendered box.
		const style = await page.locator('iframe.exelearning-iframe').getAttribute('style');
		expect(style).toContain('height: 75%');
	});

	test('show_download + fullscreen render both controls', async ({ page }) => {
		await gotoScenario(page, 'downloadfs');
		await expect(page.locator('.exelearning-download')).toBeVisible();
		await expect(page.locator('.exelearning-fullscreen-btn')).toBeVisible();
	});

	test('the frontend stylesheet and Dashicons font are enqueued', async ({ page }) => {
		await gotoScenario(page, 'default');
		// The toolbar CSS and icon font must be present on the public frontend —
		// the crux of the original bad-looking rendering.
		await expect(page.locator('link#exelearning-frontend-css')).toHaveCount(1);
		await expect(page.locator('link#dashicons-css')).toHaveCount(1);
	});

	test('Download and Fullscreen controls are aligned with equivalent boxes', async ({ page }) => {
		await gotoScenario(page, 'default');

		const downloadButton = page
			.locator('.exelearning-toolbar-actions .exelearning-toolbar-btn')
			.first();
		const fullscreenButton = page.locator('.exelearning-fullscreen-btn');

		await expect(downloadButton).toBeVisible();
		await expect(fullscreenButton).toBeVisible();

		const downloadBox = await downloadButton.boundingBox();
		const fullscreenBox = await fullscreenButton.boundingBox();

		// Equivalent dimensions and same baseline (horizontally aligned).
		expect(Math.abs(downloadBox.height - fullscreenBox.height)).toBeLessThanOrEqual(2);
		expect(Math.abs(downloadBox.width - fullscreenBox.width)).toBeLessThanOrEqual(2);
		expect(Math.abs(downloadBox.y - fullscreenBox.y)).toBeLessThanOrEqual(2);
	});

	test('toolbar icons are visible and centered within their buttons', async ({ page }) => {
		await gotoScenario(page, 'default');

		for (const selector of ['.exelearning-toolbar-btn', '.exelearning-fullscreen-btn']) {
			const button = page.locator(selector).first();
			const icon = button.locator('.dashicons');
			await expect(icon).toBeVisible();

			const buttonBox = await button.boundingBox();
			const iconBox = await icon.boundingBox();

			// The icon has a real box and sits inside its button (centered).
			expect(iconBox.width).toBeGreaterThan(0);
			expect(iconBox.height).toBeGreaterThan(0);
			const iconCenterX = iconBox.x + iconBox.width / 2;
			const iconCenterY = iconBox.y + iconBox.height / 2;
			const buttonCenterX = buttonBox.x + buttonBox.width / 2;
			const buttonCenterY = buttonBox.y + buttonBox.height / 2;
			expect(Math.abs(iconCenterX - buttonCenterX)).toBeLessThanOrEqual(3);
			expect(Math.abs(iconCenterY - buttonCenterY)).toBeLessThanOrEqual(3);
		}
	});

	test('narrow viewport (375x667) keeps the toolbar usable', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 667 });
		await gotoScenario(page, 'default');

		const titleBox = await page.locator('.exelearning-title').boundingBox();
		const fullscreenBox = await page.locator('.exelearning-fullscreen-btn').boundingBox();

		// The actions must not overlap the title: either they sit to the right of
		// it (same row) or below it (wrapped). Flexbox never overlaps, but assert
		// it explicitly against regressions.
		const clearRight = fullscreenBox.x >= titleBox.x + titleBox.width - 2;
		const clearBelow = fullscreenBox.y >= titleBox.y + titleBox.height - 2;
		expect(clearRight || clearBelow).toBeTruthy();

		// The action group must not overflow the toolbar horizontally.
		const overflow = await page.locator('.exelearning-toolbar').evaluate(
			(el) => el.scrollWidth - el.clientWidth
		);
		expect(overflow).toBeLessThanOrEqual(1);
	});

	test('desktop viewport (1280x720) keeps the toolbar aligned', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 720 });
		await gotoScenario(page, 'default');

		const downloadBox = await page
			.locator('.exelearning-toolbar-actions .exelearning-toolbar-btn')
			.first()
			.boundingBox();
		const fullscreenBox = await page.locator('.exelearning-fullscreen-btn').boundingBox();

		expect(Math.abs(downloadBox.y - fullscreenBox.y)).toBeLessThanOrEqual(2);
	});

	test('default embed produces no plugin-generated console errors', async ({ page }) => {
		const monitor = watchConsole(page);

		await gotoScenario(page, 'default');
		await expect(page.locator('iframe.exelearning-iframe')).toBeVisible();
		// Give the proxied iframe a moment to load and settle.
		await page.waitForTimeout(1000);

		if (monitor.ignored.length) {
			// Recorded but non-fatal (extension blocks, CSP report-only, sandbox note).
			console.log('Tolerated console/network noise:\n' + monitor.ignored.join('\n'));
		}
		expect(
			monitor.failures,
			`Plugin-generated errors:\n${monitor.failures.join('\n')}`
		).toEqual([]);
	});
});
