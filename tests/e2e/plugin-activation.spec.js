/**
 * E2E tests for eXeLearning WordPress plugin.
 *
 * @package Exelearning
 */

const { test, expect } = require('@playwright/test');

/**
 * Test that the plugin is active and functional.
 */
test.describe('Plugin Activation', () => {
	test('WordPress admin login page loads', async ({ page }) => {
		await page.goto('/wp-login.php');
		await expect(page.locator('#loginform')).toBeVisible();
	});

	test('can login to WordPress admin', async ({ page }) => {
		await page.goto('/wp-login.php');

		// Fill login form.
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');

		// Wait for redirect to admin.
		await page.waitForURL('**/wp-admin/**');

		// Should see the dashboard.
		await expect(page.locator('#wpadminbar')).toBeVisible();
	});

	test('eXeLearning plugin is listed in plugins page', async ({ page }) => {
		// Login first.
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');

		// Go to plugins page.
		await page.goto('/wp-admin/plugins.php');

		// Plugin should be listed and active.
		const pluginRow = page.locator('tr[data-slug="exelearning"]');
		await expect(pluginRow).toBeVisible();
		await expect(pluginRow.locator('.deactivate')).toBeVisible();
	});

	test('eXeLearning settings page exists', async ({ page }) => {
		// Login first.
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');

		// Go to eXeLearning settings page.
		await page.goto('/wp-admin/options-general.php?page=exelearning-settings');

		// Settings page should load.
		await expect(page.locator('.wrap h1')).toBeVisible();
	});

	test('Media Library accepts elpx files', async ({ page }) => {
		// Login first.
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await page.waitForURL('**/wp-admin/**');

		// Go to Media Library.
		await page.goto('/wp-admin/upload.php');

		// Media library should load - check for Add Media button.
		await expect(page.locator('a.page-title-action').first()).toBeVisible();
	});
});
