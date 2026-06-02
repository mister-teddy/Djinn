/* global DjinnCave, wp, DjinnUI */
/**
 * The Cave of Wonders — a React dashboard built on the shared DjinnUI components. Three tiles:
 * Account (proxy account + payment, or the bring-your-own-key form), Capabilities (every operation
 * the Djinn can run + the index status), and Spend (usage). The columns are resizable.
 */
( function () {
	const {
		el, useState, useEffect, Button, Spinner, Notice, Tile, Card, StatCard,
		Popover, Table, Field, Select, PasswordField, usePanelResize, ResizeHandle,
		ToastHost, toast, makeApi, formatCost,
	} = DjinnUI;
	const api = makeApi( DjinnCave );

	const REGISTRY = DjinnCave.providers || [];
	const PROVIDERS = REGISTRY.map( ( p ) => ( { value: p.value, label: p.label } ) );
	const KEY_PROVIDERS = REGISTRY.filter( ( p ) => p.needsKey ).map( ( p ) => p.value );
	const PROVIDER_EMBEDS = {};
	const PROVIDER_DESC = {};
	const PROVIDER_KEYHINT = {};
	REGISTRY.forEach( ( p ) => {
		PROVIDER_EMBEDS[ p.value ] = p.embeddings !== false;
		PROVIDER_DESC[ p.value ] = p.description || '';
		PROVIDER_KEYHINT[ p.value ] = p.keyHint || '';
	} );
	const TIER_LABELS = { recommended: 'Recommended', standard: 'Other models', limited: 'Not recommended — too small for multi-step wishes' };

	const cap = ( s ) => ( s ? String( s ).charAt( 0 ).toUpperCase() + String( s ).slice( 1 ) : s );

	// ---- Account tile -------------------------------------------------------------------------
	function AccountTile() {
		const [ s, setS ] = useState( null );             // GET /settings
		const [ account, setAccount ] = useState( null ); // GET /account
		const [ models, setModels ] = useState( null );   // GET /models
		const [ provider, setProvider ] = useState( '' );
		const [ apiKey, setApiKey ] = useState( '' );
		const [ chatModel, setChatModel ] = useState( '' );
		const [ embedModel, setEmbedModel ] = useState( '' );
		const [ saving, setSaving ] = useState( false );

		function loadAccount() {
			api( '/account' ).then( setAccount ).catch( () => {} );
		}
		useEffect( () => {
			api( '/settings' ).then( ( r ) => {
				setS( r );
				setProvider( r.provider );
				setChatModel( r.chat_model || '' );
				setEmbedModel( r.embedding_model || '' );
			} ).catch( () => {} );
			loadAccount();
		}, [] );

		// Discover models when a key-backed provider is selected.
		useEffect( () => {
			if ( KEY_PROVIDERS.indexOf( provider ) !== -1 ) {
				api( '/models?provider=' + encodeURIComponent( provider ) ).then( setModels ).catch( () => {} );
			}
		}, [ provider ] );

		// Bind the Stripe modal once the proxy payment view is on screen and no card is on file.
		useEffect( () => {
			if ( DjinnCave.stripeEnabled && provider === 'proxy' && account && account.usesProxy &&
				! account.payg && window.DjinnBilling && window.DjinnBilling.mount ) {
				window.DjinnBilling.mount();
			}
		}, [ account, provider ] );

		if ( ! s ) {
			return el( Tile, { tone: 'account', title: 'Account' }, el( Spinner ) );
		}
		const isOrg = s.isOrg;

		async function save() {
			setSaving( true );
			const body = { provider, chat_model: chatModel, embedding_model: embedModel };
			if ( apiKey ) {
				body.api_key = apiKey;
			}
			try {
				const r = await api( '/settings', body );
				if ( r && r.message ) {
					toast( r.message, 'error' );
				} else {
					setS( r );
					setProvider( r.provider );
					setApiKey( '' );
					toast( 'Settings saved.' );
					loadAccount();
				}
			} catch ( e ) {
				toast( String( e ), 'error' );
			} finally {
				setSaving( false );
			}
		}

		const selector = isOrg
			? null
			: el( Field, {
				label: 'Provider',
				htmlFor: 'djinn-provider',
				description: PROVIDER_DESC[ provider ] || '',
			}, el( Select, { id: 'djinn-provider', value: provider, onChange: setProvider, options: PROVIDERS } ) );

		const body = provider === 'proxy'
			? el( ProxyView, { account, isOrg } )
			: el( KeyView, {
				provider, apiKey, setApiKey, chatModel, setChatModel, embedModel, setEmbedModel,
				models, hasApiKey: s.hasApiKey,
			} );

		return el( Tile, { tone: 'account', title: 'Account' },
			selector,
			body,
			! isOrg ? el( Button, { className: 'djinn-save djinn-gold', variant: 'primary', busy: saving, disabled: saving, onClick: save }, 'Save settings' ) : null
		);
	}

	function ProxyView( { account, isOrg } ) {
		if ( account === null ) {
			return el( 'div', null, el( Spinner ) );
		}
		if ( ! account.connected ) {
			return el( 'div', null, el( Notice, { status: 'info', isDismissible: false }, 'Linking this site to Djinn — reload in a moment.' ) );
		}
		const cards = [];
		if ( isOrg ) {
			cards.push( el( StatCard, { key: 'wishes', value: account.wishesLeft != null ? String( account.wishesLeft ) : '—', label: 'Free wishes left' } ) );
		}
		cards.push( el( StatCard, {
			key: 'credit',
			value: formatCost( account.balanceUsd || 0 ),
			label: 'Account credit',
			sub: account.payg ? 'auto top-up on' : 'top up to continue',
		} ) );
		const kids = [ el( 'div', { key: 'cards', className: 'djinn-cards' }, cards ) ];
		if ( DjinnCave.stripeEnabled ) {
			kids.push( el( PaymentBlock, { key: 'pay', account } ) );
		}
		return el( 'div', null, kids );
	}

	function PaymentBlock( { account } ) {
		if ( account.payg ) {
			return el( 'p', { className: 'description' }, '✓ A card is on file — automatic top-up is on.' );
		}
		return el( 'div', { className: 'djinn-payment' },
			el( 'p', { className: 'description' }, 'Add a card to keep wishing — prepaid with automatic top-up, no charge now.' ),
			el( 'button', { type: 'button', className: 'button button-primary', id: 'djinn-add-card' }, 'Add a card' ),
			el( 'div', { id: 'djinn-billing-modal', className: 'djinn-modal', hidden: true },
				el( 'div', { className: 'djinn-modal-backdrop', 'data-djinn-close': 'true' } ),
				el( 'div', { className: 'djinn-modal-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'djinn-billing-title' },
					el( 'button', { type: 'button', className: 'djinn-modal-x', id: 'djinn-billing-cancel', 'data-djinn-close': 'true', 'aria-label': 'Close' }, '×' ),
					el( 'h2', { id: 'djinn-billing-title' }, 'Add a card' ),
					el( 'p', { className: 'description' }, 'Prepaid with automatic top-up — no charge now. Your card is stored by Stripe; it never touches this site.' ),
					el( 'div', { id: 'djinn-payment-element' } ),
					el( 'div', { className: 'djinn-modal-actions' },
						el( 'button', { type: 'button', className: 'button button-primary', id: 'djinn-billing-save', disabled: true }, 'Save card' ),
						el( 'span', { id: 'djinn-billing-msg' } )
					)
				)
			)
		);
	}

	function KeyView( { provider, apiKey, setApiKey, chatModel, setChatModel, embedModel, setEmbedModel, models, hasApiKey } ) {
		const chatList = ( models && models.chat ) || [];
		const withPrice = ( m ) => ( { value: m.id, label: m.price ? m.id + ' — ' + m.price : m.id } );
		const chatGroups = [ 'recommended', 'standard', 'limited' ].map( ( t ) => ( {
			label: TIER_LABELS[ t ],
			options: chatList.filter( ( m ) => m.tier === t ).map( withPrice ),
		} ) );
		const embedOptions = ( ( models && models.embed ) || [] ).map( withPrice );
		const hasEmbeddings = PROVIDER_EMBEDS[ provider ] !== false;
		return el( 'div', null,
			el( Field, {
				label: 'API key',
				htmlFor: 'djinn-key',
				description: PROVIDER_KEYHINT[ provider ] || '',
			}, el( PasswordField, {
				id: 'djinn-key',
				value: apiKey,
				onChange: setApiKey,
				placeholder: hasApiKey ? '•••••••• (saved — leave blank to keep)' : 'Paste your key',
			} ) ),
			el( Field, { label: 'Chat model', htmlFor: 'djinn-chat' },
				el( Select, { id: 'djinn-chat', value: chatModel, onChange: setChatModel, groups: chatGroups, placeholder: 'Provider default' } ) ),
			hasEmbeddings
				? el( Field, { label: 'Embedding model', htmlFor: 'djinn-embed' },
					el( Select, { id: 'djinn-embed', value: embedModel, onChange: setEmbedModel, options: embedOptions, placeholder: 'Provider default' } ) )
				: el( 'p', { className: 'description' }, 'This provider has no embeddings API — schema search runs on the full schema (no index needed).' ),
			models && models.error ? el( 'p', { className: 'description' }, '⚠ ' + models.error + ' Showing known models as a fallback.' ) : null
		);
	}

	// ---- Capabilities tile --------------------------------------------------------------------
	function CapabilitiesTile() {
		const [ ops, setOps ] = useState( null );
		useEffect( () => {
			api( '/operations' ).then( setOps ).catch( () => {} );
		}, [] );

		return el( Tile, { tone: 'capabilities', title: 'Capabilities' },
			el( 'p', { className: 'description' }, 'Everything the Djinn can do here — the operations it can run, grouped by area. Build the index from the Lamp so it can find them by meaning.' ),
			ops ? el( OperationsList, { ops } ) : el( Spinner ),
			ops ? el( UnindexedSection, { ops } ) : null
		);
	}

	// The hover-popover body for one operation: full description, its parameters (name → type, with a
	// gold * for required), and the return type. Reuses the shared .djinn-pop dl grid.
	function opDetails( o ) {
		const args = o.args || [];
		return el( 'div', null,
			o.description ? el( 'p', { className: 'djinn-pop-desc' }, o.description ) : null,
			args.length
				? el( 'dl', null, ...args.flatMap( ( a, i ) => [
					el( 'dt', { key: 'k' + i }, a.name, a.required ? el( 'span', { className: 'djinn-pop-req', title: 'required' }, ' *' ) : null ),
					el( 'dd', { key: 'v' + i }, a.type || '—' ),
				] ) )
				: el( 'p', { className: 'djinn-pop-none' }, 'No parameters.' ),
			el( 'div', { className: 'djinn-pop-returns' }, 'Returns ', el( 'code', null, o.returns || '—' ) )
		);
	}

	function OperationsList( { ops } ) {
		const byDomain = {};
		( ops.operations || [] ).forEach( ( o ) => {
			( byDomain[ o.domain ] = byDomain[ o.domain ] || [] ).push( o );
		} );
		const domains = Object.keys( byDomain ).sort();
		const [ open, setOpen ] = useState( {} ); // domain → true when expanded (default: all collapsed)
		const toggle = ( d ) => setOpen( ( o ) => Object.assign( {}, o, { [ d ]: ! o[ d ] } ) );

		return el( 'div', { className: 'djinn-ops' },
			...domains.map( ( d ) => {
				const isOpen = !! open[ d ];
				const list = byDomain[ d ].slice().sort( ( a, b ) => a.name.localeCompare( b.name ) );
				return el( 'div', { key: d, className: 'djinn-ops-domain' + ( isOpen ? ' is-open' : '' ) },
					el( 'button', { type: 'button', className: 'djinn-ops-head', 'aria-expanded': isOpen, onClick: () => toggle( d ) },
						el( 'span', { className: 'djinn-ops-caret', 'aria-hidden': true }, '▶' ),
						el( 'h3', null, d ),
						el( 'span', { className: 'djinn-ops-count' }, list.length )
					),
					isOpen ? el( 'div', { className: 'djinn-ops-list' },
						...list.map( ( o ) =>
							el( Popover, { key: o.kind + ':' + o.name, className: 'djinn-op', placement: 'top', content: opDetails( o ) },
								el( 'span', { className: 'djinn-badge djinn-badge--' + o.kind }, o.kind ),
								el( 'code', { className: 'djinn-op-name' }, o.name ),
								o.description ? el( 'span', { className: 'djinn-op-desc' }, o.description ) : null
							)
						)
					) : null
				);
			} )
		);
	}

	function UnindexedSection( { ops } ) {
		const u = ops.unindexed || [];
		const o = ops.outdated || [];
		if ( ! u.length && ! o.length ) {
			return null;
		}
		return el( 'div', { className: 'djinn-unindexed' },
			el( 'h3', null, 'Not yet indexed' ),
			el( 'p', { className: 'description' }, 'Build or update the index so the Djinn can find these by meaning.' ),
			el( 'ul', { className: 'djinn-tag-list' },
				...u.map( ( t ) => el( 'li', { key: 'u' + t, className: 'djinn-tag djinn-tag--new' }, t ) ),
				...o.map( ( t ) => el( 'li', { key: 'o' + t, className: 'djinn-tag djinn-tag--stale' }, t + ' (changed)' ) )
			)
		);
	}

	// ---- Spend tile ---------------------------------------------------------------------------
	function SpendTile() {
		const [ data, setData ] = useState( null );
		const [ resetting, setResetting ] = useState( false );
		const isOrg = DjinnCave.isOrg;
		function load() {
			api( '/usage' ).then( setData ).catch( () => {} );
		}
		useEffect( load, [] );
		if ( ! data ) {
			return el( Tile, { tone: 'spend', title: 'Spend' }, el( Spinner ) );
		}
		const t = data.totals || {};
		const empty = ! t.calls;

		async function reset() {
			if ( ! window.confirm( 'Reset the entire usage tally? This cannot be undone.' ) ) {
				return;
			}
			setResetting( true );
			try {
				await api( '/reset-usage', {} );
				toast( 'Usage tally reset.' );
				load();
			} finally {
				setResetting( false );
			}
		}

		// Proxy mode: the stored cost is the proxy's real post-markup charge per call, so it shows what
		// you'll be billed. Provider/model are fixed (the managed proxy), so those columns are just
		// noise; group by kind instead. BYO mode keeps the full, list-price estimate view.
		const providerCol = { label: 'Provider', render: ( r ) => cap( r.provider ) };
		const modelCol = { label: 'Model', render: ( r ) => el( 'code', null, r.model ) };
		const costCol = { label: isOrg ? 'Cost' : 'Est. cost', render: ( r ) => formatCost( r.cost ) };
		const byModelCols = [
			...( isOrg ? [] : [ providerCol, modelCol ] ),
			{ label: isOrg ? 'Type' : 'Kind', key: 'kind' },
			{ label: 'Calls', render: ( r ) => Number( r.calls ).toLocaleString() },
			{ label: 'Input', render: ( r ) => Number( r.prompt ).toLocaleString() },
			{ label: 'Output', render: ( r ) => Number( r.completion ).toLocaleString() },
			costCol,
		];
		const recentCols = [
			{ label: 'When (UTC)', key: 'created_at' },
			...( isOrg ? [] : [ providerCol, modelCol ] ),
			{ label: isOrg ? 'Type' : 'Kind', key: 'kind' },
			{ label: 'In', render: ( r ) => Number( r.prompt_tokens ).toLocaleString() },
			{ label: 'Out', render: ( r ) => Number( r.completion_tokens ).toLocaleString() },
			costCol,
		];

		const kids = [
			el( 'p', { key: 'intro', className: 'description' },
				isOrg
					? 'What using the Djinn has cost — billed to your account as you go.'
					: 'Estimated spend — based on public list prices, so treat it as a guide.' ),
			el( 'div', { key: 'cards', className: 'djinn-cards' },
				el( StatCard, {
					value: formatCost( t.cost || 0 ),
					label: isOrg ? 'Charged so far' : 'Estimated spend',
					sub: isOrg ? 'billed to your account' : ( t.has_estimates ? 'includes estimated tokens' : 'all metered' ),
				} ),
				el( StatCard, { value: ( t.calls || 0 ).toLocaleString(), label: 'Provider calls', sub: 'chat + embed' } ),
				el( StatCard, { value: ( t.prompt || 0 ).toLocaleString(), label: 'Input tokens' } ),
				el( StatCard, { value: ( t.completion || 0 ).toLocaleString(), label: 'Output tokens' } )
			),
			el( 'h3', { key: 'h14' }, 'Last 14 days' ),
			el( DailyBars, { key: 'bars', byDay: data.by_day || [] } ),
		];
		if ( ! empty ) {
			kids.push( el( 'h3', { key: 'hm' }, isOrg ? 'By type' : 'By model' ) );
			kids.push( el( Table, { key: 'tm', columns: byModelCols, rows: data.by_model || [] } ) );
			kids.push( el( 'h3', { key: 'hr' }, 'Recent calls' ) );
			kids.push( el( Table, { key: 'tr', columns: recentCols, rows: data.recent || [] } ) );
			// No reset in proxy mode: the tally mirrors what the proxy actually charged, so clearing it
			// locally would just desync this view from your real billing.
			if ( ! isOrg ) {
				kids.push( el( 'div', { key: 'reset', className: 'djinn-reset' },
					el( Button, { variant: 'secondary', isDestructive: true, busy: resetting, disabled: resetting, onClick: reset }, 'Reset the tally' )
				) );
			}
		}
		return el( Tile, { tone: 'spend', title: 'Spend' }, kids );
	}

	function DailyBars( { byDay } ) {
		const byKey = {};
		( byDay || [] ).forEach( ( r ) => { byKey[ r.day ] = Number( r.cost ) || 0; } );
		const now = new Date();
		const days = [];
		for ( let i = 13; i >= 0; i-- ) {
			const d = new Date( Date.UTC( now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - i ) );
			const key = d.toISOString().slice( 0, 10 );
			days.push( { key, cost: byKey[ key ] || 0 } );
		}
		const max = days.reduce( ( m, d ) => Math.max( m, d.cost ), 0 );
		return el( 'div', { className: 'djinn-bars' },
			...days.map( ( d ) => {
				const pct = max > 0 ? Math.max( 3, Math.round( ( d.cost / max ) * 100 ) ) : 3;
				return el( 'div', { key: d.key, className: 'djinn-bar-col', title: d.key + ' — ' + formatCost( d.cost ) },
					el( 'div', { className: 'djinn-bar', style: { height: pct + '%' } } ),
					el( 'div', { className: 'djinn-bar-x' }, d.key.slice( 5 ) )
				);
			} )
		);
	}

	// ---- Cave root: resizable left column (Account + Capabilities) | Spend ---------------------
	function Cave() {
		const col = usePanelResize( { storageKey: 'djinn_cave_split', min: 320, max: 760, initial: 440, axis: 'x' } );
		const acc = usePanelResize( { storageKey: 'djinn_cave_account_h', min: 140, max: 640, initial: 320, axis: 'y' } );
		const resizing = col.resizing || acc.resizing;
		return el( 'div', { className: 'djinn-cave' + ( resizing ? ' is-resizing' : '' ) },
			el( ToastHost ),
			el( 'div', { className: 'djinn-cave-col djinn-cave-col--left', style: { width: col.size + 'px' } },
				el( 'div', { className: 'djinn-cave-pane', style: { height: acc.size + 'px' } }, el( AccountTile ) ),
				el( ResizeHandle, { axis: 'y', onMouseDown: acc.startResize, title: 'Drag to resize' } ),
				el( 'div', { className: 'djinn-cave-pane djinn-cave-pane--grow' }, el( CapabilitiesTile ) )
			),
			el( ResizeHandle, { axis: 'x', onMouseDown: col.startResize, title: 'Drag to resize' } ),
			el( 'div', { className: 'djinn-cave-col djinn-cave-col--right' },
				el( SpendTile )
			)
		);
	}

	wp.element.render( el( Cave ), document.getElementById( 'djinn-cave-root' ) );
} )();
