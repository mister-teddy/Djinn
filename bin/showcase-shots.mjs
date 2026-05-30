// Capture Lamp screenshots for the product showcase.
//
//   node bin/showcase-shots.mjs            (after seeding + installing playwright + chromium)
//
// RULE: showcase screenshots use a FIXED, uniform aspect ratio at a SMALL viewport, so on-screen
// text stays large and readable. Do NOT size the canvas to the tallest conversation — that zooms
// the whole UI out and makes text tiny. Default 1280×720 (16:9) at 2× DPR (→ crisp 2560×1440 PNGs).
// A conversation taller than the viewport simply shows its latest exchange (the app scrolls to the
// bottom). Override with DJINN_SHOT_WIDTH / DJINN_SHOT_HEIGHT, but keep every shot the same size.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.DJINN_URL || 'http://localhost:8888';
const USER = process.env.DJINN_USER || 'admin';
const PASS = process.env.DJINN_PASS || 'password';
const WIDTH = Number( process.env.DJINN_SHOT_WIDTH || 1280 );
const HEIGHT = Number( process.env.DJINN_SHOT_HEIGHT || 720 );
const OUT = 'build/screenshots';

mkdirSync( OUT, { recursive: true } );

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: WIDTH, height: HEIGHT }, deviceScaleFactor: 2 } );

// Log in.
await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'networkidle' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await page.click( '#wp-submit' );
await page.waitForLoadState( 'networkidle' );

// Open the Lamp; hide WP chrome and make the app fill the viewport so a viewport screenshot is the
// clean app at the exact aspect ratio (dark fallback bg avoids white slivers at the edges).
await page.goto( `${ BASE }/wp-admin/admin.php?page=djinn`, { waitUntil: 'networkidle' } );
await page.addStyleTag( { content: `
	html,body{margin:0!important;padding:0!important;background:#160e2e!important;overflow:hidden!important}
	#adminmenumain,#wpadminbar,#wpfooter,#screen-meta,#screen-meta-links,.update-nag,#update-nag{display:none!important}
	#wpwrap,#wpcontent,#wpbody,#wpbody-content{margin:0!important;padding:0!important}
	.wrap.djinn-wrap{margin:0!important;padding:0!important;max-width:none!important;height:100vh!important;overflow:visible!important}
	#djinn-root{width:100vw!important;height:100vh!important}
	.djinn-layout{width:100vw!important;height:100vh!important}
` } );
await page.waitForSelector( '.djinn-layout', { timeout: 15000 } );
await page.waitForTimeout( 1200 ); // let the web font settle

const count = ( await page.$$( '.djinn-history-item' ) ).length;
if ( ! count ) {
	console.error( 'No conversations in the sidebar — run the seed script first.' );
	await browser.close();
	process.exit( 1 );
}

async function openChat( i ) {
	const list = await page.$$( '.djinn-history-item' );
	const title = ( await list[ i ].innerText() ).split( '\n' )[ 0 ].trim().slice( 0, 40 ).replace( /[^a-z0-9]+/gi, '-' ).toLowerCase();
	await list[ i ].click();
	await page.waitForSelector( '.djinn-thread .djinn-msg, .djinn-thread .djinn-action, .djinn-thread .djinn-pending', { timeout: 10000 } );
	await page.waitForSelector( '.djinn-thinking', { state: 'detached' } ).catch( () => {} );
	await page.waitForTimeout( 350 );
	return title;
}

let n = 0;
for ( let i = 0; i < count; i++ ) {
	const title = await openChat( i );
	const file = `${ OUT }/${ String( ++n ).padStart( 2, '0' ) }-${ title || 'chat' }.png`;
	await page.screenshot( { path: file } );
	console.log( 'saved', file );
}

await browser.close();
console.log( `\nDone — ${ n } screenshots at ${ WIDTH }×${ HEIGHT } (×2 DPR) in ${ OUT }/` );
