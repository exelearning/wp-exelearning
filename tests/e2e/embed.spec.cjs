// Cross-browser (Firefox) e2e for the secure-mode external embeds: verifies the
// real in-iframe shim + parent relay promote whitelisted video and local PDF
// embeds to inline players on the parent page, while rejecting other origins.
const { test, expect } = require('@playwright/test');
test('promotes whitelisted video + relative local PDF to inline parent players (Firefox)', async ({ page }) => {
    await page.goto('/tests/e2e/embed/parent.html');
    const players = page.locator('.exe-embed-overlay iframe');
    await expect.poll(() => players.count(), { timeout: 15000 }).toBe(2);
    const srcs = await players.evaluateAll((els) => els.map((e) => e.src));
    expect(srcs.some((s) => s.includes('youtube-nocookie.com/embed/aqz-KE-bpKQ'))).toBe(true);
    expect(srcs.some((s) => /\/local\.pdf$/.test(s))).toBe(true);
    expect(srcs.some((s) => s.includes('example.com'))).toBe(false);
});
