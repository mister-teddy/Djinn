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

	// Pull View/Edit links out of a GraphQL result — any object carrying link/editUrl.
	function collectLinks( node, acc ) {
		acc = acc || [];
		if ( ! node || typeof node !== 'object' ) {
			return acc;
		}
		if ( Array.isArray( node ) ) {
			node.forEach( ( n ) => collectLinks( n, acc ) );
			return acc;
		}
		const view = node.link ? safeUrl( node.link ) : null;
		const edit = node.editUrl ? safeUrl( node.editUrl ) : null;
		if ( view || edit ) {
			acc.push( { view, edit, label: String( node.title || node.name || node.id || '' ) } );
		}
		Object.keys( node ).forEach( ( k ) => collectLinks( node[ k ], acc ) );
		return acc;
	}

	// Pull generated download files out of a result (DownloadFile shape: token + filename).
	function collectDownloads( node, acc ) {
		acc = acc || [];
		if ( ! node || typeof node !== 'object' ) {
			return acc;
		}
		if ( Array.isArray( node ) ) {
			node.forEach( ( n ) => collectDownloads( n, acc ) );
			return acc;
		}
		if ( node.token && node.filename ) {
			acc.push( { token: String( node.token ), filename: String( node.filename ), bytes: node.bytes } );
		}
		Object.keys( node ).forEach( ( k ) => collectDownloads( node[ k ], acc ) );
		return acc;
	}

	function formatBytes( n ) {
		n = Number( n ) || 0;
		if ( n < 1024 ) {
			return n + ' B';
		}
		const u = [ 'KB', 'MB', 'GB' ];
		let i = -1;
		do {
			n /= 1024;
			i++;
		} while ( n >= 1024 && i < u.length - 1 );
		return n.toFixed( 1 ) + ' ' + u[ i ];
	}

	// A read-only record of a GraphQL operation the Djinn already ran (or that was resolved),
	// so you can always see the exact incantation — even ones that didn't need your approval.
	function IncantationCard( { action } ) {
		const hasVars = action.variables && Object.keys( action.variables ).length > 0;
		const kind = action.kind === 'mutation' ? 'Mutation' : 'Query';
		const links = action.result ? collectLinks( action.result, [] ) : [];
		const downloads = action.result ? collectDownloads( action.result, [] ) : [];
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
			links.length ? el( 'div', { className: 'djinn-action-links' },
				...links.slice( 0, 8 ).map( ( l, idx ) => el( 'span', { key: idx, className: 'djinn-action-link-chip' },
					l.label ? el( 'span', { className: 'djinn-action-link-label' }, l.label ) : null,
					l.view ? el( 'a', { className: 'djinn-action-link', href: l.view, target: '_blank', rel: 'noopener noreferrer' }, 'View ↗' ) : null,
					l.edit ? el( 'a', { className: 'djinn-action-link', href: l.edit, target: '_blank', rel: 'noopener noreferrer' }, 'Edit ✎' ) : null
				) )
			) : null,
			downloads.length ? el( 'div', { className: 'djinn-action-links' },
				...downloads.map( ( d, idx ) => el( 'a', {
					key: idx,
					className: 'djinn-action-link djinn-download',
					href: Djinn.restUrl + '/download?token=' + encodeURIComponent( d.token ) + '&_wpnonce=' + encodeURIComponent( Djinn.nonce ),
				}, '⤓ ' + d.filename + ( d.bytes ? ' (' + formatBytes( d.bytes ) + ')' : '' ) ) )
			) : null,
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

	// --- Markdown → React (no library, no innerHTML; XSS-safe by construction) --------------------
	// Only http(s)/mailto/root-relative/anchor URLs survive; javascript:/data: are dropped.
	function safeUrl( url ) {
		const u = String( url || '' ).trim();
		return /^(https?:|mailto:|\/|#)/i.test( u ) ? u : null;
	}

	// Inline span parsing: code, images, links, bold, italic. Returns an array of strings/elements.
	function mdInline( text ) {
		const patterns = [
			{ re: /`([^`]+)`/, make: ( m ) => el( 'code', { className: 'djinn-md-code' }, m[ 1 ] ) },
			{ re: /!\[([^\]]*)\]\(([^)\s]+)\)/, make: ( m ) => {
				const u = safeUrl( m[ 2 ] );
				return u ? el( 'img', { className: 'djinn-md-img', src: u, alt: m[ 1 ], loading: 'lazy' } ) : m[ 1 ];
			} },
			{ re: /\[([^\]]+)\]\(([^)\s]+)\)/, make: ( m ) => {
				const u = safeUrl( m[ 2 ] );
				return u ? el( 'a', { href: u, target: '_blank', rel: 'noopener noreferrer' }, ...mdInline( m[ 1 ] ) ) : m[ 0 ];
			} },
			{ re: /\*\*([^*]+)\*\*/, make: ( m ) => el( 'strong', null, ...mdInline( m[ 1 ] ) ) },
			{ re: /__([^_]+)__/, make: ( m ) => el( 'strong', null, ...mdInline( m[ 1 ] ) ) },
			{ re: /\*([^*]+)\*/, make: ( m ) => el( 'em', null, ...mdInline( m[ 1 ] ) ) },
			{ re: /_([^_]+)_/, make: ( m ) => el( 'em', null, ...mdInline( m[ 1 ] ) ) },
		];
		const out = [];
		let rest = String( text );
		while ( rest ) {
			let best = null;
			for ( const p of patterns ) {
				const m = p.re.exec( rest );
				if ( m && ( ! best || m.index < best.m.index ) ) {
					best = { p, m };
				}
			}
			if ( ! best ) {
				out.push( rest );
				break;
			}
			if ( best.m.index > 0 ) {
				out.push( rest.slice( 0, best.m.index ) );
			}
			out.push( best.p.make( best.m ) );
			rest = rest.slice( best.m.index + best.m[ 0 ].length );
		}
		return out;
	}

	function mdTable( lines, start ) {
		const cells = ( l ) => l.replace( /^\s*\|/, '' ).replace( /\|\s*$/, '' ).split( '|' ).map( ( c ) => c.trim() );
		const head = cells( lines[ start ] );
		let i = start + 2; // skip header + separator
		const rows = [];
		while ( i < lines.length && lines[ i ].indexOf( '|' ) !== -1 && lines[ i ].trim() ) {
			rows.push( cells( lines[ i ] ) );
			i++;
		}
		return {
			next: i,
			node: el( 'table', { className: 'djinn-md-table' },
				el( 'thead', null, el( 'tr', null, ...head.map( ( c, j ) => el( 'th', { key: j }, ...mdInline( c ) ) ) ) ),
				el( 'tbody', null, ...rows.map( ( r, ri ) => el( 'tr', { key: ri }, ...r.map( ( c, ci ) => el( 'td', { key: ci }, ...mdInline( c ) ) ) ) ) )
			),
		};
	}

	// Block parsing: headings, fenced code, blockquote, tables, lists, hr, paragraphs.
	function renderMarkdown( text ) {
		const lines = String( text || '' ).replace( /\r\n?/g, '\n' ).split( '\n' );
		const blocks = [];
		let i = 0;
		let key = 0;
		const isSpecial = ( l ) => /^```/.test( l ) || /^(#{1,6})\s/.test( l ) || /^\s*>/.test( l ) || /^\s*[-*+]\s+/.test( l ) || /^\s*\d+\.\s+/.test( l );
		while ( i < lines.length ) {
			const line = lines[ i ];
			if ( /^```/.test( line ) ) {
				const buf = [];
				i++;
				while ( i < lines.length && ! /^```/.test( lines[ i ] ) ) {
					buf.push( lines[ i ] );
					i++;
				}
				i++;
				blocks.push( el( 'pre', { key: key++, className: 'djinn-md-pre' }, el( 'code', null, buf.join( '\n' ) ) ) );
				continue;
			}
			if ( /^\s*$/.test( line ) ) {
				i++;
				continue;
			}
			const h = /^(#{1,6})\s+(.*)$/.exec( line );
			if ( h ) {
				blocks.push( el( 'h' + h[ 1 ].length, { key: key++, className: 'djinn-md-h' }, ...mdInline( h[ 2 ] ) ) );
				i++;
				continue;
			}
			if ( /^\s*([-*_])(\s*\1){2,}\s*$/.test( line ) ) {
				blocks.push( el( 'hr', { key: key++ } ) );
				i++;
				continue;
			}
			if ( /^\s*>/.test( line ) ) {
				const buf = [];
				while ( i < lines.length && /^\s*>/.test( lines[ i ] ) ) {
					buf.push( lines[ i ].replace( /^\s*>\s?/, '' ) );
					i++;
				}
				blocks.push( el( 'blockquote', { key: key++, className: 'djinn-md-quote' }, ...mdInline( buf.join( '\n' ) ) ) );
				continue;
			}
			if ( line.indexOf( '|' ) !== -1 && i + 1 < lines.length && /^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/.test( lines[ i + 1 ] ) ) {
				const t = mdTable( lines, i );
				blocks.push( el( 'div', { key: key++ }, t.node ) );
				i = t.next;
				continue;
			}
			if ( /^\s*[-*+]\s+/.test( line ) ) {
				const items = [];
				while ( i < lines.length && /^\s*[-*+]\s+/.test( lines[ i ] ) ) {
					items.push( lines[ i ].replace( /^\s*[-*+]\s+/, '' ) );
					i++;
				}
				blocks.push( el( 'ul', { key: key++, className: 'djinn-md-ul' }, ...items.map( ( it, j ) => el( 'li', { key: j }, ...mdInline( it ) ) ) ) );
				continue;
			}
			if ( /^\s*\d+\.\s+/.test( line ) ) {
				const items = [];
				while ( i < lines.length && /^\s*\d+\.\s+/.test( lines[ i ] ) ) {
					items.push( lines[ i ].replace( /^\s*\d+\.\s+/, '' ) );
					i++;
				}
				blocks.push( el( 'ol', { key: key++, className: 'djinn-md-ol' }, ...items.map( ( it, j ) => el( 'li', { key: j }, ...mdInline( it ) ) ) ) );
				continue;
			}
			const para = [ line ];
			i++;
			while ( i < lines.length && lines[ i ].trim() && ! isSpecial( lines[ i ] ) ) {
				para.push( lines[ i ] );
				i++;
			}
			const inline = [];
			para.forEach( ( p, j ) => {
				if ( j ) {
					inline.push( el( 'br', { key: 'br' + j } ) );
				}
				mdInline( p ).forEach( ( n ) => inline.push( n ) );
			} );
			blocks.push( el( 'p', { key: key++, className: 'djinn-md-p' }, ...inline ) );
		}
		return blocks;
	}

	function Message( { msg, busy, onConfirm, onCancel } ) {
		if ( msg.role === 'pending' ) {
			return el( PendingCard, { pending: msg, busy, onConfirm, onCancel } );
		}
		if ( msg.role === 'action' ) {
			return el( IncantationCard, { action: msg } );
		}
		const bubble = msg.role === 'assistant'
			? el( 'div', { className: 'djinn-bubble djinn-md' }, ...renderMarkdown( msg.content || '' ) )
			: el( 'div', { className: 'djinn-bubble' }, msg.content );
		return el(
			'div',
			{ className: 'djinn-msg djinn-msg-' + msg.role },
			msg.role === 'assistant' ? el( 'div', { className: 'djinn-msg-avatar' }, el( Lamp, { size: 20 } ) ) : null,
			bubble
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

	// Parse one SSE block ("event: x\ndata: {...}") into { event, data } or null.
	function parseSSE( block ) {
		let event = 'message';
		let data = '';
		block.split( '\n' ).forEach( ( line ) => {
			if ( line.indexOf( 'event:' ) === 0 ) {
				event = line.slice( 6 ).trim();
			} else if ( line.indexOf( 'data:' ) === 0 ) {
				data += line.slice( 5 ).trim();
			}
		} );
		if ( ! data ) {
			return null;
		}
		try {
			return { event, data: JSON.parse( data ) };
		} catch ( e ) {
			return null;
		}
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
		const [ attachment, setAttachment ] = useState( null ); // { token, filename, size } once uploaded
		const [ step, setStep ] = useState( '' ); // current streaming step label
		const scroller = useRef( null );
		const fileInput = useRef( null );
		const loadSeq = useRef( 0 ); // guards against a stale transcript load clobbering newer state

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
				// Set the id immediately so a wish sent before the transcript finishes loading still
				// continues this chat; load history without blocking the composer (no busy).
				setChatId( saved );
				loadTranscript( saved );
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
			const seq = ++loadSeq.current;
			const r = await api( '/chats/' + id );
			if ( seq !== loadSeq.current ) {
				return; // a newer load or a sent wish superseded this fetch
			}
			if ( r && Array.isArray( r.messages ) ) {
				setMessages( r.messages );
				setChatId( r.chat_id || id );
				setUsage( r.usage || null );
			}
		}

		async function uploadFile( file ) {
			const body = new FormData();
			body.append( 'file', file );
			const r = await fetch( Djinn.restUrl + '/upload', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': Djinn.nonce },
				body,
			} ).then( ( x ) => x.json() );
			if ( r && r.token ) {
				setAttachment( { token: r.token, filename: r.filename, size: r.size } );
			} else {
				setError( ( r && r.message ) || 'Upload failed.' );
			}
		}

		// Live-update the trailing streaming assistant bubble as deltas arrive.
		function setStreamingContent( content ) {
			setMessages( ( m ) => {
				const c = m.slice();
				for ( let i = c.length - 1; i >= 0; i-- ) {
					if ( c[ i ].streaming ) {
						c[ i ] = { ...c[ i ], content };
						break;
					}
				}
				return c;
			} );
		}

		// Non-streaming fallback (older browser / curl-less host / stream error).
		async function sendBlocking( startChatId, text ) {
			const r = await api( '/wish', { chat_id: startChatId, message: text } );
			setError( r.status === 'error' ? ( r.message || 'The lamp dimmed.' ) : '' );
			const id = r.chat_id || startChatId;
			if ( id ) {
				await loadTranscript( id );
			}
		}

		async function send() {
			let text = input.trim();
			if ( ( ! text && ! attachment ) || busy ) {
				return;
			}
			// Fold the attachment into the message so the model sees its import token.
			if ( attachment ) {
				text = ( text ? text + '\n\n' : '' ) + '📎 Attached file: ' + attachment.filename + ' (import token: ' + attachment.token + ')';
			}
			const startChatId = chatId;
			loadSeq.current++; // supersede any in-flight restore so it can't wipe this turn
			setInput( '' );
			setAttachment( null );
			setMessages( ( m ) => [ ...m, { role: 'user', content: text } ] ); // optimistic echo
			setBusy( true );
			setStep( '' );

			try {
				const res = await fetch( Djinn.restUrl + '/wish/stream', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': Djinn.nonce },
					body: JSON.stringify( { chat_id: startChatId, message: text } ),
				} );
				if ( ! res.ok || ! res.body ) {
					throw new Error( 'stream unavailable' );
				}
				const reader = res.body.getReader();
				const decoder = new TextDecoder();
				let buf = '';
				let acc = '';
				let started = false;
				let resolvedId = startChatId;
				let terminal = null;
				for ( ;; ) {
					const { value, done } = await reader.read();
					if ( done ) {
						break;
					}
					buf += decoder.decode( value, { stream: true } );
					let sep;
					while ( ( sep = buf.indexOf( '\n\n' ) ) !== -1 ) {
						const evt = parseSSE( buf.slice( 0, sep ) );
						buf = buf.slice( sep + 2 );
						if ( ! evt ) {
							continue;
						}
						if ( evt.event === 'open' ) {
							resolvedId = evt.data.chat_id || resolvedId;
							if ( evt.data.chat_id ) {
								setChatId( evt.data.chat_id );
							}
						} else if ( evt.event === 'step' ) {
							setStep( evt.data.label || '' );
						} else if ( evt.event === 'delta' ) {
							acc += evt.data.token || '';
							if ( ! started ) {
								started = true;
								setMessages( ( m ) => [ ...m, { role: 'assistant', content: acc, streaming: true } ] );
							} else {
								setStreamingContent( acc );
							}
						} else {
							terminal = evt;
							resolvedId = ( evt.data && evt.data.chat_id ) || resolvedId;
						}
					}
				}
				setError( terminal && terminal.event === 'error' ? ( terminal.data.message || 'The lamp dimmed.' ) : '' );
				if ( resolvedId ) {
					await loadTranscript( resolvedId ); // canonical transcript (replaces the streamed bubble)
				}
			} catch ( e ) {
				try {
					await sendBlocking( startChatId, text );
				} catch ( e2 ) {
					setError( String( e2 ) );
				}
			} finally {
				if ( startChatId === 0 ) {
					refreshChats();
				}
				setBusy( false );
				setStep( '' );
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
					el( Meter, { usage } )
				)
			),
			! indexed
				? el( Notice, { status: 'info', isDismissible: false },
						'The lamp slumbers — ',
						el( 'a', { href: Djinn.indexUrl }, 'awaken its memory' ),
						' to sharpen the Djinn\'s knowledge of your site.'
					)
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
				busy && ! empty ? el( 'div', { className: 'djinn-thinking' }, el( Spinner, null ), el( 'span', null, step || 'The Djinn ponders…' ) ) : null
			),
			el(
				'div',
				{ className: 'djinn-composer' },
				attachment ? el( 'div', { className: 'djinn-attach-chip' },
					'📎 ' + attachment.filename + ( attachment.size ? ' (' + formatBytes( attachment.size ) + ')' : '' ),
					el( 'button', { type: 'button', className: 'djinn-attach-x', onClick: () => setAttachment( null ), title: 'Remove' }, '×' )
				) : null,
				el( 'div', { className: 'djinn-composer-row' },
					el( 'input', {
						type: 'file',
						ref: fileInput,
						style: { display: 'none' },
						onChange: ( e ) => {
							const f = e.target.files && e.target.files[ 0 ];
							if ( f ) {
								uploadFile( f );
							}
							e.target.value = '';
						},
					} ),
					el( Button, { className: 'djinn-attach', disabled: busy, title: 'Attach a file', onClick: () => fileInput.current && fileInput.current.click() }, '📎' ),
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
					el( Button, { className: 'djinn-send', disabled: busy || ( ! input.trim() && ! attachment ), onClick: send }, el( Sparkle ), 'Make wish' )
				)
			)
			)
		);
	}

	wp.element.render( el( App ), document.getElementById( 'djinn-root' ) );
} )();
