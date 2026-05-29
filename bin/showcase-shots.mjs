// Capture Lamp screenshots for the product showcase.
//
//   node bin/showcase-shots.mjs            (after seeding + installing playwright + chromium)
//
// Logs into the wp-env site, opens each seeded conversation, and saves a 16:9 screenshot of the
// Lamp to build/screenshots/. The canvas height is the TALLEST conversation (so the longest fits),
// and the width is derived to keep a clean 16:9 across the whole set. Renders the real UI.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.DJINN_URL || 'http://localhost:8888';
const USER = process.env.DJINN_USER || 'admin';
const PASS = process.env.DJINN_PASS || 'password';
const BASE_W = Number( process.env.DJINN_SHOT_WIDTH || 1280 );
const OUT = 'build/screenshots';

mkdirSync( OUT, { recursive: true } );

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: BASE_W, height: 800 }, deviceScaleFactor: 2 } );

// Log in.
await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'networkidle' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await page.click( '#wp-submit' );
await page.waitForLoadState( 'networkidle' );

// Open the Lamp; hide WP chrome and make the app fill the viewport so a viewport screenshot is the
// clean app at an exact aspect ratio (dark fallback bg avoids white slivers at the edges).
await page.goto( `${ BASE }/wp-admin/admin.php?page=djinn`, { waitUntil: 'networkidle' } );
await page.addStyleTag( { content: `
	html,body{margin:0!important;padding:0!important;background:#160e2e!important;overflow:hidden!important}
	#adminmenumain,#wpadminbar,#wpfooter,#screen-meta,#screen-meta-links{display:none!important}
	#wpwrap,#wpcontent,#wpbody,#wpbody-content{margin:0!important;padding:0!important}
	.wrap.djinn-wrap{margin:0!important;padding:0!important;max-width:none!important}
	#djinn-root{width:100vw!important}
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

// Toggle a style that collapses the thread's flex-grow, so the layout reports its natural (content)
// height for measurement, then restore it for the fixed-canvas capture.
async function setCollapse( on ) {
	await page.evaluate( ( on ) => {
		let s = document.getElementById( 'dj-collapse' );
		if ( on ) {
			if ( ! s ) {
				s = document.createElement( 'style' );
				s.id = 'dj-collapse';
				document.head.appendChild( s );
			}
			s.textContent = '.djinn-layout,.djinn-app,.djinn-sidebar{height:auto!important;min-height:0!important}.djinn-thread{flex:0 0 auto!important;height:auto!important;overflow:visible!important}';
		} else if ( s ) {
			s.remove();
		}
	}, on );
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

// Pass 1 — measure the tallest conversation at the base width.
await setCollapse( true );
let maxH = 0;
for ( let i = 0; i < count; i++ ) {
	await openChat( i );
	maxH = Math.max( maxH, await page.evaluate( () => document.querySelector( '.djinn-layout' ).offsetHeight ) );
}
await setCollapse( false );

// 16:9 canvas: tall enough for the longest conversation, width derived to hold the ratio.
const HEIGHT = Math.max( 720, Math.ceil( maxH ) + 24 );
const WIDTH = Math.round( HEIGHT * 16 / 9 );
await page.setViewportSize( { width: WIDTH, height: HEIGHT } );
await page.waitForTimeout( 300 );

// Pass 2 — capture each chat as a viewport screenshot (exactly WIDTH×HEIGHT = 16:9).
let n = 0;
for ( let i = 0; i < count; i++ ) {
	const title = await openChat( i );
	const file = `${ OUT }/${ String( ++n ).padStart( 2, '0' ) }-${ title || 'chat' }.png`;
	await page.screenshot( { path: file } );
	console.log( 'saved', file );
}

await browser.close();
console.log( `\nDone — ${ n } screenshots at ${ WIDTH }×${ HEIGHT } (16:9) in ${ OUT }/` );
