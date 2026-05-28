/* global Djinn, wp */
( function () {
	const { createElement: el, useState, useRef, useEffect } = wp.element;
	const { Button, Spinner, Notice } = wp.components;

	function api( path, body ) {
		return fetch( Djinn.restUrl + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': Djinn.nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( ( r ) => r.json() );
	}

	// Persist the active conversation so a page reload returns to it (per site, keyed by REST URL).
	const ACTIVE_CHAT_KEY = 'djinn_active_chat:' + Djinn.restUrl;
	function readActiveChat() {
		try {
			return window.localStorage.getItem( ACTIVE_CHAT_KEY ) || '';
		} catch ( e ) {
			return '';
		}
	}
	function writeActiveChat( id ) {
		try {
			window.localStorage.setItem( ACTIVE_CHAT_KEY, String( id ) );
		} catch ( e ) {}
	}
	function clearActiveChat() {
		try {
			window.localStorage.removeItem( ACTIVE_CHAT_KEY );
		} catch ( e ) {}
	}

	// Minimal line-art lamp glyph — the wordmark of the product.
	function Lamp( { size = 28, glow = false } ) {
		return el(
			'svg',
			{
				className: 'djinn-lamp' + ( glow ? ' djinn-lamp-glow' : '' ),
				width: size,
				height: size,
				viewBox: '0 0 64 64',
				fill: 'none',
				'aria-hidden': true,
			},
			// flame/smoke wisp
			el( 'path', {
				d: 'M22 8 C22 14 28 16 28 22 C28 18 24 16 24 12',
				stroke: 'currentColor',
				strokeWidth: 2,
				strokeLinecap: 'round',
				className: 'djinn-lamp-wisp',
			} ),
			// spout
			el( 'path', {
				d: 'M8 38 L20 30 L20 42 Z',
				stroke: 'currentColor',
				strokeWidth: 2.2,
				strokeLinejoin: 'round',
			} ),
			// body
			el( 'path', {
				d: 'M20 30 Q32 22 48 28 Q56 30 56 38 Q56 46 48 48 L24 48 Q18 48 18 42 Z',
				stroke: 'currentColor',
				strokeWidth: 2.2,
				strokeLinejoin: 'round',
			} ),
			// handle
			el( 'path', {
				d: 'M48 28 Q58 30 56 40',
				stroke: 'currentColor',
				strokeWidth: 2.2,
				strokeLinecap: 'round',
				fill: 'none',
			} ),
			// base
			el( 'path', {
				d: 'M22 48 L50 48 L46 54 L26 54 Z',
				stroke: 'currentColor',
				strokeWidth: 2.2,
				strokeLinejoin: 'round',
			} )
		);
	}

	function Sparkle() {
		return el(
			'svg',
			{ width: 14, height: 14, viewBox: '0 0 16 16', 'aria-hidden': true, className: 'djinn-sparkle' },
			el( 'path', {
				d: 'M8 1 L9.4 6.6 L15 8 L9.4 9.4 L8 15 L6.6 9.4 L1 8 L6.6 6.6 Z',
				fill: 'currentColor',
			} )
		);
	}

	function PendingCard( { pending, busy, onConfirm, onCancel } ) {
		const hasVars = pending.variables && Object.keys( pending.variables ).length > 0;
		return el(
			'div',
			{ className: 'djinn-pending' },
			el( 'div', { className: 'djinn-pending-head' }, el( Sparkle ), 'Grant this wish?' ),
			el( 'p', { className: 'djinn-pending-summary' }, pending.summary ),
			el( 'details', { className: 'djinn-pending-details' },
				el( 'summary', null, 'Show the incantation' ),
				el( 'pre', { className: 'djinn-code' }, pending.operation ),
				hasVars ? el( 'pre', { className: 'djinn-code djinn-code-vars' }, JSON.stringify( pending.variables, null, 2 ) ) : null
			),
			el(
				'div',
				{ className: 'djinn-pending-actions' },
				el( Button, { className: 'djinn-grant', isBusy: busy, disabled: busy, onClick: onConfirm }, el( Sparkle ), 'Grant' ),
				el( Button, { variant: 'tertiary', disabled: busy, onClick: onCancel }, 'Refuse' )
			)
		);
	}

	// Status labels for an executed/resolved GraphQL operation.
	const ACTION_BADGE = {
		ok: 'ran',
		granted: 'granted',
		refused: 'refused',
		error: 'failed',
	};

	// A read-only record of a GraphQL operation the Djinn already ran (or that was resolved),
	// so you can always see the exact incantation — even ones that didn't need your approval.
	function IncantationCard( { action } ) {
		const hasVars = action.variables && Object.keys( action.variables ).length > 0;
		const kind = action.kind === 'mutation' ? 'Mutation' : 'Query';
		return el(
			'div',
			{ className: 'djinn-action djinn-action-' + action.status },
			el( 'div', { className: 'djinn-action-head' },
				el( Sparkle ),
				el( 'span', { className: 'djinn-action-kind' }, kind ),
				el( 'span', { className: 'djinn-action-badge' }, ACTION_BADGE[ action.status ] || action.status )
			),
			action.summary ? el( 'p', { className: 'djinn-action-summary' }, action.summary ) : null,
			action.message ? el( 'p', { className: 'djinn-action-msg' }, action.message ) : null,
			el( 'details', { className: 'djinn-pending-details' },
				el( 'summary', null, 'Show the incantation' ),
				el( 'pre', { className: 'djinn-code' }, action.operation ),
				hasVars ? el( 'div', { className: 'djinn-code-label' }, 'Variables' ) : null,
				hasVars ? el( 'pre', { className: 'djinn-code djinn-code-vars' }, JSON.stringify( action.variables, null, 2 ) ) : null,
				action.result ? el( 'div', { className: 'djinn-code-label' }, 'Response' ) : null,
				action.result ? el( 'pre', { className: 'djinn-code djinn-code-result' }, JSON.stringify( action.result, null, 2 ) ) : null
			)
		);
	}

	function Message( { msg, busy, onConfirm, onCancel } ) {
		if ( msg.role === 'pending' ) {
			return el( PendingCard, { pending: msg, busy, onConfirm, onCancel } );
		}
		if ( msg.role === 'action' ) {
			return el( IncantationCard, { action: msg } );
		}
		return el(
			'div',
			{ className: 'djinn-msg djinn-msg-' + msg.role },
			msg.role === 'assistant' ? el( 'div', { className: 'djinn-msg-avatar' }, el( Lamp, { size: 20 } ) ) : null,
			el( 'div', { className: 'djinn-bubble' }, msg.content )
		);
	}

	// created_at is a UTC MySQL datetime ("YYYY-MM-DD HH:MM:SS"); render it in the admin's locale.
	function formatDate( s ) {
		if ( ! s ) {
			return '';
		}
		const d = new Date( s.replace( ' ', 'T' ) + 'Z' );
		return isNaN( d ) ? '' : d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	}

	function Sidebar( { chats, activeId, busy, onNew, onOpen } ) {
		return el(
			'aside',
			{ className: 'djinn-sidebar' },
			el( Button, { className: 'djinn-newchat', variant: 'primary', disabled: busy, onClick: onNew }, el( Sparkle ), 'New wish' ),
			el( 'div', { className: 'djinn-history-label' }, 'Past wishes' ),
			el(
				'div',
				{ className: 'djinn-history' },
				! chats.length
					? el( 'p', { className: 'djinn-history-empty' }, 'Nothing yet.' )
					: chats.map( ( c ) =>
							el(
								'button',
								{
									key: c.id,
									type: 'button',
									className: 'djinn-history-item' + ( c.id === activeId ? ' is-active' : '' ),
									disabled: busy,
									onClick: () => onOpen( c.id ),
									title: c.title,
								},
								el( 'span', { className: 'djinn-history-title' }, c.title || 'Untitled wish' ),
								el( 'span', { className: 'djinn-history-date' }, formatDate( c.created_at ) )
							)
					  )
			)
		);
	}

	// Adaptive currency: keep tiny amounts legible instead of rounding to $0.00.
	function formatCost( usd ) {
		const n = Number( usd ) || 0;
		if ( n === 0 ) {
			return '$0.00';
		}
		if ( n < 0.01 ) {
			return '$' + n.toFixed( 6 ).replace( /0+$/, '' ).replace( /\.$/, '' );
		}
		return '$' + n.toFixed( n < 1 ? 4 : 2 );
	}

	// Running token + cost total for the open conversation. Updates after each wish/grant.
	function Meter( { usage } ) {
		if ( ! usage || ! usage.calls ) {
			return null;
		}
		const tokens = ( usage.tokens || 0 ).toLocaleString();
		return el(
			'div',
			{ className: 'djinn-meter', title: usage.prompt.toLocaleString() + ' in · ' + usage.completion.toLocaleString() + ' out · ' + usage.calls + ' calls' },
			el( Sparkle ),
			el( 'span', { className: 'djinn-meter-tokens' }, tokens, ' tokens' ),
			el( 'span', { className: 'djinn-meter-sep' }, '·' ),
			el( 'span', { className: 'djinn-meter-cost' }, formatCost( usage.cost ) )
		);
	}

	function App() {
		const [ messages, setMessages ] = useState( [] );
		const [ input, setInput ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ chatId, setChatId ] = useState( 0 );
		const [ chats, setChats ] = useState( [] );
		const [ usage, setUsage ] = useState( null );
		const [ indexed, setIndexed ] = useState( Djinn.indexed );
		const [ error, setError ] = useState( '' );
		const scroller = useRef( null );

		useEffect( () => {
			if ( scroller.current ) {
				scroller.current.scrollTop = scroller.current.scrollHeight;
			}
		}, [ messages, busy ] );

		// On mount: load the conversation list, and reopen the chat we were last in so a page
		// refresh keeps you in the same conversation (and follow-ups keep its chat_id + history).
		useEffect( () => {
			refreshChats();
			const saved = parseInt( readActiveChat(), 10 );
			if ( saved ) {
				openChat( saved );
			}
		}, [] );

		// Remember the open conversation across reloads.
		useEffect( () => {
			if ( chatId ) {
				writeActiveChat( chatId );
			}
		}, [ chatId ] );

		async function refreshChats() {
			try {
				const r = await api( '/chats' );
				if ( Array.isArray( r ) ) {
					setChats( r );
				}
			} catch ( e ) {
				// A failed history fetch shouldn't break the lamp.
			}
		}

		// The server holds the canonical conversation — wishes, replies, the GraphQL it ran, and
		// any mutation still awaiting a grant. Reloading it after every turn means one render
		// path, and a page refresh restores the full state (pending cards included).
		async function loadTranscript( id ) {
			const r = await api( '/chats/' + id );
			if ( r && Array.isArray( r.messages ) ) {
				setMessages( r.messages );
				setChatId( r.chat_id || id );
				setUsage( r.usage || null );
			}
		}

		async function send() {
			const text = input.trim();
			if ( ! text || busy ) {
				return;
			}
			const isNew = chatId === 0;
			setInput( '' );
			setMessages( ( m ) => [ ...m, { role: 'user', content: text } ] ); // optimistic echo
			setBusy( true );
			try {
				const r = await api( '/wish', { chat_id: chatId, message: text } );
				setError( r.status === 'error' ? ( r.message || 'The lamp dimmed.' ) : '' );
				const id = r.chat_id || chatId;
				if ( id ) {
					await loadTranscript( id );
				}
				if ( isNew ) {
					refreshChats(); // pick up the freshly-created conversation
				}
			} catch ( e ) {
				setError( String( e ) );
			} finally {
				setBusy( false );
			}
		}

		function newChat() {
			if ( busy ) {
				return;
			}
			setMessages( [] );
			setChatId( 0 );
			setUsage( null );
			setError( '' );
			clearActiveChat();
		}

		async function openChat( id ) {
			if ( busy || id === chatId ) {
				return;
			}
			setBusy( true );
			setError( '' );
			try {
				await loadTranscript( id );
			} catch ( e ) {
				setError( String( e ) );
			} finally {
				setBusy( false );
			}
		}

		async function resolvePending( pending, confirmed ) {
			setBusy( true );
			try {
				const r = await api( '/grant', { chat_id: chatId, pending_id: pending.pending_id, confirmed } );
				setError( r.status === 'error' ? ( r.message || 'The lamp dimmed.' ) : '' );
				await loadTranscript( chatId );
			} catch ( e ) {
				setError( String( e ) );
			} finally {
				setBusy( false );
			}
		}

		async function reindex() {
			setBusy( true );
			setError( '' );
			try {
				const r = await api( '/reindex', {} );
				if ( r.status === 'ok' ) {
					setIndexed( true );
				} else {
					setError( r.message || 'The lamp could not be awakened.' );
				}
			} finally {
				setBusy( false );
			}
		}

		if ( ! Djinn.configured ) {
			return el(
				'div',
				{ className: 'djinn-app djinn-app-empty' },
				el( 'div', { className: 'djinn-hero' },
					el( Lamp, { size: 96, glow: false } ),
					el( 'h1', null, 'The lamp is empty.' ),
					el( 'p', null, 'Place an offering — an API key — to summon the Djinn.' ),
					el( 'a', { className: 'components-button is-primary djinn-cta', href: Djinn.settingsUrl }, 'Open Settings →' )
				)
			);
		}

		const empty = messages.length === 0;

		return el(
			'div',
			{ className: 'djinn-layout' },
			el( Sidebar, { chats, activeId: chatId, busy, onNew: newChat, onOpen: openChat } ),
			el(
			'div',
			{ className: 'djinn-app' },
			el(
				'div',
				{ className: 'djinn-header' },
				el( 'div', { className: 'djinn-brand' },
					el( Lamp, { size: 32, glow: ! empty || busy } ),
					el( 'div', null,
						el( 'h1', null, 'Djinn' ),
						el( 'p', { className: 'djinn-tag' }, 'Whisper a wish. The Djinn of ', el( 'em', null, Djinn.siteName ), ' will grant it.' )
					)
				),
				el( 'div', { className: 'djinn-header-right' },
					el( Meter, { usage } ),
					el(
						Button,
						{ variant: 'secondary', isBusy: busy, disabled: busy, onClick: reindex },
						indexed ? 'Refresh the lamp' : 'Awaken the lamp'
					)
				)
			),
			! indexed
				? el( Notice, { status: 'info', isDismissible: false }, 'The lamp slumbers — Awaken it to sharpen the Djinn\'s memory of your site.' )
				: null,
			error ? el( Notice, { status: 'error', onRemove: () => setError( '' ) }, error ) : null,
			el(
				'div',
				{ className: 'djinn-thread' + ( empty ? ' djinn-thread-empty' : '' ), ref: scroller },
				empty
					? el( 'div', { className: 'djinn-empty' },
						el( Lamp, { size: 84, glow: true } ),
						el( 'p', { className: 'djinn-empty-line' }, 'Rub the lamp.' ),
						el( 'p', { className: 'djinn-empty-sub' }, 'Try: ', el( 'em', null, '"Create a draft page titled About"' ), ' · ', el( 'em', null, '"List my 5 newest posts"' ), ' · ', el( 'em', null, '"Set the tagline to Built with Djinn"' ) )
					)
					: messages.map( ( msg, i ) =>
							el( Message, {
								key: i,
								msg,
								busy,
								onConfirm: () => resolvePending( msg, true ),
								onCancel: () => resolvePending( msg, false ),
							} )
					  ),
				busy && ! empty ? el( 'div', { className: 'djinn-thinking' }, el( Spinner, null ), el( 'span', null, 'The Djinn ponders…' ) ) : null
			),
			el(
				'div',
				{ className: 'djinn-composer' },
				el( 'textarea', {
					className: 'djinn-input',
					value: input,
					placeholder: 'Whisper your wish…  (Enter to send · Shift+Enter for newline)',
					rows: 2,
					disabled: busy,
					onChange: ( e ) => setInput( e.target.value ),
					onKeyDown: ( e ) => {
						if ( e.key === 'Enter' && ! e.shiftKey ) {
							e.preventDefault();
							send();
						}
					},
				} ),
				el( Button, { className: 'djinn-send', disabled: busy || ! input.trim(), onClick: send }, el( Sparkle ), 'Make wish' )
			)
			)
		);
	}

	wp.element.render( el( App ), document.getElementById( 'djinn-root' ) );
} )();
