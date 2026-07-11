/**
 * E2E: the editor bootstrap wires the opaque HTTP preview transport
 * (serving contract v2) and no longer patches a Service Worker /viewer/ path.
 *
 * WHY THIS MOSTLY SKIPS (documented dependency, honestly). Reaching the editor
 * bootstrap in a browser requires all three of:
 *
 *   1. A static editor build under `dist/static` carrying an HttpPreviewProvider.
 *      The bundled editor (`.editor-version`, v4.0.2) predates it, and
 *      `dist/static` is a gitignored build artifact absent in CI — so the page
 *      redirects to the installer.
 *   2. A SESSION-valid `exelearning_editor` nonce. WordPress binds nonces to the
 *      browser session token, so a wp-cli nonce never validates; the nonce is
 *      exposed only through the editor's admin assets.
 *   3. The plugin's editor admin assets actually enqueuing on the screen.
 *
 * When any precondition is unmet the test skips with a clear reason rather than
 * hacking a build. The previewHttp injection and the Service-Worker removal are
 * covered headlessly and deterministically by `tests/unit/EditorTest.php`
 * (build_preview_http_config, the admin notice) and the bootstrap edits; this
 * spec is the browser-level guard that engages once a capable build is present.
 *
 * @package Exelearning
 */

const { test, expect } = require('@playwright/test');
const { execSync } = require('node:child_process');

/**
 * Seed a throwaway .elpx attachment on the wp-env *tests* instance (:8889, the
 * Playwright baseURL) and return its id, or null when the CLI is unreachable.
 *
 * @returns {number|null}
 */
function seedAttachmentId() {
	try {
		const out = execSync(
			'npx wp-env run tests-cli --env-cwd=wp-content/plugins/exelearning ' +
				'wp eval-file tests/e2e/helpers/seed-elpx-attachment.php --user=admin',
			{ encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }
		);
		const match = out.match(/attachment_id=(\d+)/);
		return match ? Number(match[1]) : null;
	} catch (e) {
		return null;
	}
}

test.describe('Editor opaque HTTP preview wiring', () => {
	test('bootstrap injects previewHttp and drops the Service Worker /viewer/ wiring', async ({ page }) => {
		const attachmentId = seedAttachmentId();
		test.skip(!attachmentId, 'wp-env CLI unreachable — cannot seed an .elpx attachment.');

		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');

		await page.goto('/wp-admin/upload.php');
		const editorNonce = await page.evaluate(
			() => (window.exelearningEditorVars && window.exelearningEditorVars.editorNonce) || null
		);
		test.skip(
			!editorNonce,
			'Editor admin assets not enqueued here — cannot mint a session-valid editor nonce. ' +
				'previewHttp injection is covered by tests/unit/EditorTest.php.'
		);

		await page.goto(
			`/wp-admin/admin.php?page=exelearning-editor&attachment_id=${attachmentId}&_wpnonce=${editorNonce}`
		);
		test.skip(
			/editor-missing=1|page=exelearning-settings/.test(page.url()),
			'Static editor not built (dist/static missing) — full editor->HTTP-preview flow needs a build with HttpPreviewProvider.'
		);

		const html = await page.content();

		// The opaque HTTP preview transport is wired into the embedding config.
		expect(html).toContain('previewHttp');
		expect(html).toContain('exelearning/v1/preview-session');
		expect(html).toContain('exelearning/v1/preview');
		expect(html).toContain('X-WP-Nonce');

		// The legacy Service Worker /viewer/ machinery is gone.
		expect(html).not.toContain('preview-sw.js');
		expect(html).not.toContain('navigator.serviceWorker');
		expect(html).not.toContain('normalizePreviewIframeSrc');
		expect(html).not.toContain('/viewer/');
	});
});
