// Capture Lamp screenshots for the product showcase.
//
//   node bin/showcase-shots.mjs            (after seeding + `npx playwright install chromium`)
//
// Logs into the wp-env site, opens each seeded conversation in the sidebar, and saves a clean
// element screenshot of the Lamp to build/screenshots/. Renders the real UI — nothing mocked.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.DJINN_URL || 'http://localhost:8888';
const USER = process.env.DJINN_USER || 'admin';
const PASS = process.env.DJINN_PASS || 'password';
const OUT = 'build/screenshots';

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({
	viewport: { width: 1440, height: 960 },
	deviceScaleFactor: 2, // crisp, retina-quality output
});

// Log in.
await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle' });
await page.fill('#user_login', USER);
await page.fill('#user_pass', PASS);
await page.click('#wp-submit');
await page.waitForLoadState('networkidle');

// Open the Lamp and collapse the WP admin menu so the app fills the frame.
await page.goto(`${BASE}/wp-admin/admin.php?page=djinn`, { waitUntil: 'networkidle' });
await page.addStyleTag({ content: '#adminmenumain,#wpadminbar{display:none!important} #wpcontent{margin-left:0!important}' });
await page.waitForSelector('.djinn-layout', { timeout: 15000 });

// Give web fonts a beat to load so text doesn't shift between shots.
await page.waitForTimeout(1200);

const items = await page.$$('.djinn-history-item');
if (!items.length) {
	console.error('No conversations in the sidebar — run the seed script first.');
	await browser.close();
	process.exit(1);
}

let n = 0;
for (let i = 0; i < items.length; i++) {
	// Re-query each loop; React re-renders the list on open.
	const list = await page.$$('.djinn-history-item');
	const title = (await list[i].innerText()).split('\n')[0].trim().slice(0, 40).replace(/[^a-z0-9]+/gi, '-').toLowerCase();
	await list[i].click();

	// Wait for the transcript to render and the thinking spinner to clear.
	await page.waitForSelector('.djinn-thread .djinn-msg, .djinn-thread .djinn-action, .djinn-thread .djinn-pending', { timeout: 10000 });
	await page.waitForSelector('.djinn-thinking', { state: 'detached' }).catch(() => {});
	await page.waitForTimeout(500);

	const file = `${OUT}/${String(++n).padStart(2, '0')}-${title || 'chat'}.png`;
	const layout = await page.$('.djinn-layout');
	await layout.screenshot({ path: file });
	console.log('saved', file);
}

await browser.close();
console.log(`\nDone — ${n} screenshots in ${OUT}/`);
