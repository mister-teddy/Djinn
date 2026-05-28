// Djinn proxy — a metered, OpenAI-compatible LLM gateway for the plugin's ORG edition.
//
// The ORG plugin sends standard OpenAI-shaped requests here with its per-site token as the bearer.
// We authenticate the site, check its credit, forward the call to our upstream provider (using OUR
// key), read the token usage from the response, charge the account, and pass the response straight
// back — so the plugin parses it exactly as it would a direct OpenAI call.
//
// This is a deployable scaffold: the account store is a JSON file and there is no payment capture
// yet (see the TODOs). Swap `Accounts` for a real database + Stripe before production.

import express from 'express';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const PORT = process.env.PORT || 8787;

// We choose the model (ORG users don't), so we control cost. Point UPSTREAM_BASE at OpenAI or at
// Google's OpenAI-compatible Gemini endpoint.
const UPSTREAM_BASE = process.env.UPSTREAM_BASE || 'https://api.openai.com/v1';
const UPSTREAM_KEY = process.env.UPSTREAM_KEY || '';
const CHAT_MODEL = process.env.ORG_CHAT_MODEL || 'gpt-4o-mini';
const EMBED_MODEL = process.env.ORG_EMBED_MODEL || 'text-embedding-3-small';
const ADMIN_TOKEN = process.env.ADMIN_TOKEN || '';

// Free trial: a djinn grants three wishes. We cap by BOTH a wish count and a hard dollar ceiling,
// so even a pathological wish can't cost more than FREE_TRIAL_USD. MAX_OUTPUT_TOKENS caps any
// single call. (The plugin marks the first call of each wish with the X-Djinn-New-Wish header.)
const FREE_TRIAL_WISHES = Number( process.env.FREE_TRIAL_WISHES || 3 );
const FREE_TRIAL_USD = Number( process.env.FREE_TRIAL_USD || 0.05 );
const MAX_OUTPUT_TOKENS = Number( process.env.MAX_OUTPUT_TOKENS || 2000 );

// USD per 1,000,000 tokens. Keep in sync with the upstream you point at.
const PRICING = {
	'gpt-4o-mini': { input: 0.15, output: 0.6 },
	'gpt-4o': { input: 2.5, output: 10 },
	'text-embedding-3-small': { input: 0.02, output: 0 },
	'gemini-2.5-flash-lite': { input: 0.1, output: 0.4 },
	'gemini-embedding-001': { input: 0.15, output: 0 },
};

function cost( model, inTok, outTok ) {
	const r = PRICING[ model ];
	return r ? ( inTok * r.input + outTok * r.output ) / 1e6 : 0;
}

// --- Account store (scaffold: a JSON file keyed by site token) ----------------------------------
// TODO: replace with a real database, and gate credit on a Stripe customer + payment method.
const STORE = process.env.ACCOUNTS_FILE || './accounts.json';

const Accounts = {
	all() {
		return existsSync( STORE ) ? JSON.parse( readFileSync( STORE, 'utf8' ) ) : {};
	},
	get( token ) {
		return this.all()[ token ] || null;
	},
	save( token, account ) {
		const all = this.all();
		all[ token ] = account;
		writeFileSync( STORE, JSON.stringify( all, null, 2 ) );
	},
};

const app = express();
app.use( express.json( { limit: '2mb' } ) );

// Resolve + authorize the calling site from its bearer token.
function authorize( req, res ) {
	const token = ( req.headers.authorization || '' ).replace( /^Bearer\s+/i, '' );
	const account = token && Accounts.get( token );
	if ( ! account ) {
		res.status( 401 ).json( { error: { message: 'Unknown or missing Djinn token.' } } );
		return null;
	}
	// payg (pay-as-you-go, card on file) accounts may settle later. Trial accounts are bounded by
	// both a wish count and a dollar ceiling — whichever runs out first ends the trial.
	if ( ! account.payg ) {
		if ( ( account.wishesLeft ?? 0 ) <= 0 ) {
			res.status( 402 ).json( { error: { message: 'Your three free wishes are used up. Add a card to keep wishing.' } } );
			return null;
		}
		if ( ( account.balanceUsd ?? 0 ) <= 0 ) {
			res.status( 402 ).json( { error: { message: 'Free trial credit used up. Add a card to keep wishing.' } } );
			return null;
		}
	}
	return { token, account };
}

// Forward an OpenAI-shaped request upstream, meter it, and charge the account.
async function relay( res, ctx, path, body, model ) {
	body.model = model; // we decide the model
	const upstream = await fetch( UPSTREAM_BASE + path, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + UPSTREAM_KEY },
		body: JSON.stringify( body ),
	} );
	const json = await upstream.json();

	if ( upstream.ok && json.usage ) {
		const charge = cost( model, json.usage.prompt_tokens || 0, json.usage.completion_tokens || 0 );
		ctx.account.balanceUsd = +( ( ctx.account.balanceUsd || 0 ) - charge ).toFixed( 8 );
		ctx.account.spentUsd = +( ( ctx.account.spentUsd || 0 ) + charge ).toFixed( 8 );
		Accounts.save( ctx.token, ctx.account );
	}
	res.status( upstream.status ).json( json );
}

app.post( '/v1/chat/completions', async ( req, res ) => {
	const ctx = authorize( req, res );
	if ( ! ctx ) return;

	// Count a new wish (the plugin sets this header on the first call of each wish).
	if ( ! ctx.account.payg && req.headers['x-djinn-new-wish'] ) {
		ctx.account.wishesLeft = Math.max( 0, ( ctx.account.wishesLeft ?? 0 ) - 1 );
		Accounts.save( ctx.token, ctx.account );
	}
	// Cap output tokens so one call can't blow the budget.
	req.body.max_tokens = Math.min( Number( req.body.max_tokens ) || MAX_OUTPUT_TOKENS, MAX_OUTPUT_TOKENS );

	await relay( res, ctx, '/chat/completions', req.body, CHAT_MODEL );
} );

app.post( '/v1/embeddings', async ( req, res ) => {
	const ctx = authorize( req, res );
	if ( ! ctx ) return;
	await relay( res, ctx, '/embeddings', req.body, EMBED_MODEL );
} );

// The ORG plugin's Spend page reads remaining credit + free wishes here.
app.get( '/v1/account', ( req, res ) => {
	const ctx = authorize( req, res );
	if ( ! ctx ) return;
	res.json( {
		balanceUsd: ctx.account.balanceUsd,
		spentUsd: ctx.account.spentUsd,
		payg: !! ctx.account.payg,
		wishesLeft: ctx.account.wishesLeft ?? 0,
	} );
} );

// Admin: create/top-up an account. New accounts start on the free trial (three wishes + a small
// dollar ceiling). Adding a card later sets payg=true. TODO: drive this from Stripe webhooks.
app.post( '/admin/credit', ( req, res ) => {
	if ( ! ADMIN_TOKEN || req.headers['x-admin-token'] !== ADMIN_TOKEN ) {
		return res.status( 403 ).json( { error: { message: 'Forbidden.' } } );
	}
	const { token, addUsd, payg } = req.body || {};
	if ( ! token ) return res.status( 400 ).json( { error: { message: 'token required' } } );

	const isNew = ! Accounts.get( token );
	const account = Accounts.get( token ) || {
		balanceUsd: FREE_TRIAL_USD,
		spentUsd: 0,
		payg: false,
		wishesLeft: FREE_TRIAL_WISHES,
	};
	if ( addUsd !== undefined ) {
		account.balanceUsd = +( ( account.balanceUsd || 0 ) + Number( addUsd ) ).toFixed( 8 );
	}
	if ( payg !== undefined ) {
		account.payg = !! payg; // card on file → metered pay-as-you-go, trial limits no longer apply
	}
	Accounts.save( token, account );
	res.json( { ...account, created: isNew } );
} );

app.get( '/healthz', ( _req, res ) => res.json( { ok: true } ) );

app.listen( PORT, () => console.log( `Djinn proxy on :${ PORT } → ${ UPSTREAM_BASE } (${ CHAT_MODEL })` ) );
