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

	// Line-art genie lamp — the wordmark of the product. Rising spout + flame on the left, a
	// bulbous body with a lid knob, a handle curl on the right, and a small foot.
	function Lamp( { size = 28, glow = false } ) {
		return el(
			'svg',
			{
				className: 'djinn-lamp' + ( glow ? ' djinn-lamp-glow' : '' ),
				width: size,
				height: size,
				viewBox: '0 0 64 64',
				fill: 'none',
				stroke: 'currentColor',
				strokeWidth: 2.2,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
				'aria-hidden': true,
			},
			// flame above the spout
			el( 'path', { d: 'M7 21 C5 17 9 15 7.5 11 C10.5 14 10.5 18 9 20', className: 'djinn-lamp-wisp' } ),
			// spout (left, rising) — a slim tapering funnel
			el( 'path', { d: 'M20 35 C13 32 8 28 6 22 L10 20.5 C12 26 16 30 22 33 Z' } ),
			// body (bulbous vessel)
			el( 'path', { d: 'M19 45 C12 45 10 38 15 34 C21 29 31 27 41 29 C50 31 54 35 53 40 C52 45 46 47 39 47 L23 47 C21.5 47 20 46.2 19 45 Z' } ),
			// lid dome + knob
			el( 'path', { d: 'M27 28.5 C30 24 38 24 41 28.5' } ),
			el( 'circle', { cx: 34, cy: 23, r: 2.2, fill: 'currentColor', stroke: 'none' } ),
			// handle (right curl)
			el( 'path', { d: 'M53 35 C60 36 62 43 56 47' } ),
			// foot
			el( 'path', { d: 'M24 47 L40 47 L37 52 L27 52 Z' } )
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

	// Status icon + word for an executed/resolved GraphQL operation.
	const ACTION_STATUS = {
		ok:      { icon: '✓', text: 'done' },
		granted: { icon: '✓', text: 'granted' },
		refused: { icon: '⊘', text: 'refused' },
		error:   { icon: '⚠', text: 'failed' },
	};

	function humanize( s ) {
		return String( s || '' )
			.replace( /([a-z0-9])([A-Z])/g, '$1 $2' )
			.replace( /[_-]+/g, ' ' )
			.replace( /^./, ( c ) => c.toUpperCase() );
	}

	// A concise, readable purpose for the operation: the model's summary for writes, else a
	// humanized version of the operation's root field for reads.
	function actionPurpose( action ) {
		if ( action.summary ) {
			return action.summary;
		}
		const m = /\{\s*([A-Za-z_][A-Za-z0-9_]*)/.exec( action.operation || '' );
		const field = m ? humanize( m[ 1 ] ) : 'the site';
		return ( action.kind === 'mutation' ? 'Changed ' : 'Looked up ' ) + field.toLowerCase();
	}

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

	// A single conjured line: ✦ purpose … ✓ status. Click to expand the incantation (operation,
	// variables, response) and any links/downloads. Compact by design.
	function IncantationCard( { action } ) {
		const [ open, setOpen ] = useState( false );
		const hasVars = action.variables && Object.keys( action.variables ).length > 0;
		const links = action.result ? collectLinks( action.result, [] ) : [];
		const downloads = action.result ? collectDownloads( action.result, [] ) : [];

		return el(
			'div',
			{ className: 'djinn-action djinn-action-' + action.status },
			el( 'button', { type: 'button', className: 'djinn-act-row', onClick: () => setOpen( ( o ) => ! o ), 'aria-expanded': open },
				el( 'span', { className: 'djinn-act-glyph' }, el( Sparkle ) ),
				el( 'span', { className: 'djinn-act-purpose' }, actionPurpose( action ) ),
				el( 'span', { className: 'djinn-act-caret' }, open ? '▾' : '▸' )
			),
			open ? el( 'div', { className: 'djinn-act-detail' },
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
				el( 'div', { className: 'djinn-code-label' }, 'Operation' ),
				el( 'pre', { className: 'djinn-code' }, action.operation ),
				hasVars ? el( 'div', { className: 'djinn-code-label' }, 'Variables' ) : null,
				hasVars ? el( 'pre', { className: 'djinn-code djinn-code-vars' }, JSON.stringify( action.variables, null, 2 ) ) : null,
				action.result ? el( 'div', { className: 'djinn-code-label' }, 'Response' ) : null,
				action.result ? el( 'pre', { className: 'djinn-code djinn-code-result' }, JSON.stringify( action.result, null, 2 ) ) : null
			) : null
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
			// Autolink bare http(s) URLs; markdown links still win (they match at an earlier index).
			{ re: /https?:\/\/[^\s<>()[\]'"]*[^\s<>()[\]'".,;:!?]/, make: ( m ) => {
				const u = safeUrl( m[ 0 ] );
				return u ? el( 'a', { href: u, target: '_blank', rel: 'noopener noreferrer' }, m[ 0 ] ) : m[ 0 ];
			} },
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
		const hasText = ( msg.content || '' ) !== '';
		const bubble = msg.role === 'assistant'
			? el( 'div', { className: 'djinn-bubble djinn-md' }, ...renderMarkdown( msg.content || '' ) )
			: hasText ? el( 'div', { className: 'djinn-bubble' }, msg.content ) : null;
		const chips = ( msg.attachments && msg.attachments.length )
			? msg.attachments.map( ( a, i ) =>
					el( 'span', { key: 'att' + i, className: 'djinn-attach-chip' },
						'📎 ' + a.filename + ( a.size ? ' (' + formatBytes( a.size ) + ')' : '' )
					)
			  )
			: [];
		return el(
			'div',
			{ className: 'djinn-msg djinn-msg-' + msg.role },
			msg.role === 'assistant' ? el( 'div', { className: 'djinn-msg-avatar' }, el( Lamp, { size: 20 } ) ) : null,
			el( 'div', { className: 'djinn-msg-stack' }, bubble, ...chips )
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

	function Sidebar( { chats, activeId, busy, onNew, onOpen, onDelete, width } ) {
		return el(
			'aside',
			{ className: 'djinn-sidebar', style: { width: width + 'px' } },
			el( Button, { className: 'djinn-newchat', variant: 'primary', disabled: busy, onClick: onNew }, el( Sparkle ), 'New wish' ),
			el( 'div', { className: 'djinn-history-label' }, 'Past wishes' ),
			el(
				'div',
				{ className: 'djinn-history' },
				! chats.length
					? el( 'p', { className: 'djinn-history-empty' }, 'Nothing yet.' )
					: chats.map( ( c ) =>
							el(
								'div',
								{ key: c.id, className: 'djinn-history-item' + ( c.id === activeId ? ' is-active' : '' ) },
								el( 'button', {
									type: 'button',
									className: 'djinn-history-open',
									disabled: busy,
									onClick: () => onOpen( c.id ),
									title: c.title,
								},
									el( 'span', { className: 'djinn-history-title' }, c.title || 'Untitled wish' ),
									el( 'span', { className: 'djinn-history-date' }, formatDate( c.created_at ) )
								),
								el( 'button', {
									type: 'button',
									className: 'djinn-history-del',
									disabled: busy,
									title: 'Delete this wish',
									'aria-label': 'Delete this wish',
									onClick: () => onDelete( c.id ),
								}, '×' )
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
		const showCost = ! Djinn.isOrg;
		return el(
			'div',
			{ className: 'djinn-meter', title: usage.prompt.toLocaleString() + ' in · ' + usage.completion.toLocaleString() + ' out · ' + usage.calls + ' calls' },
			el( Sparkle ),
			el( 'span', { className: 'djinn-meter-tokens' }, tokens, ' tokens' ),
			showCost ? el( 'span', { className: 'djinn-meter-sep' }, '·' ) : null,
			showCost ? el( 'span', { className: 'djinn-meter-cost' }, formatCost( usage.cost ) ) : null
		);
	}

	// Grow a textarea to fit its content, up to a cap (then it scrolls).
	function autosize( node ) {
		if ( ! node ) {
			return;
		}
		node.style.height = 'auto';
		node.style.height = Math.min( node.scrollHeight, 220 ) + 'px';
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
		const [ dragOver, setDragOver ] = useState( false ); // a file is being dragged over the page
		const [ collapsed, setCollapsed ] = useState( () => {
			try {
				const s = localStorage.getItem( 'djinn_sidebar_collapsed' );
				return s === null ? window.matchMedia( '(max-width: 782px)' ).matches : s === '1';
			} catch ( e ) { return false; }
		} );
		const [ sidebarWidth, setSidebarWidth ] = useState( () => {
			try {
				const w = parseInt( localStorage.getItem( 'djinn_sidebar_width' ), 10 );
				return ( w >= 150 && w <= 400 ) ? w : 200;
			} catch ( e ) { return 200; }
		} );
		const [ resizing, setResizing ] = useState( false );
		const scroller = useRef( null );
		const fileInput = useRef( null );
		const inputRef = useRef( null );
		const loadSeq = useRef( 0 ); // guards against a stale transcript load clobbering newer state
		const dragDepth = useRef( 0 ); // dragenter/leave fire per child; count to know when we truly left

		useEffect( () => {
			const node = scroller.current;
			if ( ! node ) {
				return;
			}
			// Defer to after layout/paint so scrollHeight reflects the new content (streamed text,
			// tables, late-rendered blocks) and we land at the true bottom.
			const id = requestAnimationFrame( () => {
				node.scrollTop = node.scrollHeight;
			} );
			return () => cancelAnimationFrame( id );
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

		// Keep the composer elastic: grow/shrink to fit as the text (or a cleared send) changes.
		useEffect( () => {
			autosize( inputRef.current );
		}, [ input ] );

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

		// Drop a file anywhere on the page → upload it. Depth-count enter/leave (they fire per child).
		function dragHasFile( e ) {
			return !! ( e.dataTransfer && Array.prototype.indexOf.call( e.dataTransfer.types || [], 'Files' ) !== -1 );
		}
		function onDragEnter( e ) {
			if ( ! dragHasFile( e ) ) return;
			e.preventDefault();
			dragDepth.current++;
			setDragOver( true );
		}
		function onDragOver( e ) {
			if ( dragHasFile( e ) ) e.preventDefault(); // permit the drop
		}
		function onDragLeave( e ) {
			if ( ! dragHasFile( e ) ) return;
			dragDepth.current = Math.max( 0, dragDepth.current - 1 );
			if ( dragDepth.current === 0 ) setDragOver( false );
		}
		function onDrop( e ) {
			if ( ! dragHasFile( e ) ) return;
			e.preventDefault();
			dragDepth.current = 0;
			setDragOver( false );
			const f = e.dataTransfer.files && e.dataTransfer.files[ 0 ];
			if ( f && ! busy ) uploadFile( f );
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
		async function sendBlocking( startChatId, text, attachments ) {
			const r = await api( '/wish', { chat_id: startChatId, message: text, attachments: attachments || [] } );
			setError( r.status === 'error' ? ( r.message || 'The lamp dimmed.' ) : '' );
			const id = r.chat_id || startChatId;
			if ( id ) {
				await loadTranscript( id );
			}
		}

		async function send() {
			const text = input.trim();
			if ( ( ! text && ! attachment ) || busy ) {
				return;
			}
			// Attachments ride alongside the message, not spliced into the text: the user's words stay
			// clean in the bubble and transcript, and the engine hands the import token to the model.
			const attachments = attachment
				? [ { filename: attachment.filename, token: attachment.token, size: attachment.size } ]
				: [];
			const startChatId = chatId;
			loadSeq.current++; // supersede any in-flight restore so it can't wipe this turn
			setInput( '' );
			setAttachment( null );
			setMessages( ( m ) => [ ...m, { role: 'user', content: text, attachments: attachment ? attachments : undefined } ] ); // optimistic echo
			setBusy( true );
			setStep( '' );

			try {
				const res = await fetch( Djinn.restUrl + '/wish/stream', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': Djinn.nonce },
					body: JSON.stringify( { chat_id: startChatId, message: text, attachments } ),
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
					await sendBlocking( startChatId, text, attachments );
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
			if ( window.matchMedia( '(max-width: 782px)' ).matches ) {
				setCollapsed( true ); // on narrow screens the sidebar overlays — close it on open
			}
			try {
				await loadTranscript( id );
			} catch ( e ) {
				setError( String( e ) );
			} finally {
				setBusy( false );
			}
		}

		function toggleSidebar() {
			setCollapsed( ( v ) => {
				const next = ! v;
				try { localStorage.setItem( 'djinn_sidebar_collapsed', next ? '1' : '0' ); } catch ( e ) {}
				return next;
			} );
		}

		// Drag the handle to resize the sidebar; width persists. Disabled while collapsed or on narrow
		// screens (where the sidebar overlays at a fixed width).
		function startResize( e ) {
			if ( collapsed || window.matchMedia( '(max-width: 782px)' ).matches ) {
				return;
			}
			e.preventDefault();
			const startX = e.clientX;
			const startW = sidebarWidth;
			let lastW = startW;
			setResizing( true );
			function onMove( ev ) {
				lastW = Math.min( 400, Math.max( 150, startW + ( ev.clientX - startX ) ) );
				setSidebarWidth( lastW );
			}
			function onUp() {
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup', onUp );
				setResizing( false );
				try { localStorage.setItem( 'djinn_sidebar_width', String( lastW ) ); } catch ( e2 ) {}
			}
			document.addEventListener( 'mousemove', onMove );
			document.addEventListener( 'mouseup', onUp );
		}

		async function deleteChat( id ) {
			if ( busy || ! window.confirm( 'Delete this wish and its history? This cannot be undone.' ) ) {
				return;
			}
			try {
				await fetch( Djinn.restUrl + '/chats/' + id, {
					method: 'DELETE',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': Djinn.nonce },
				} );
			} catch ( e ) {
				setError( 'Could not delete that wish.' );
				return;
			}
			if ( id === chatId ) {
				newChat();
			}
			refreshChats();
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
					el( 'a', { className: 'components-button is-primary djinn-cta', href: Djinn.settingsUrl }, 'Open the Cave of Wonders →' )
				)
			);
		}

		const empty = messages.length === 0;

		return el(
			'div',
			{
				className: 'djinn-layout' + ( collapsed ? ' is-collapsed' : '' ) + ( resizing ? ' is-resizing' : '' ) + ( dragOver ? ' djinn-dragging' : '' ),
				onDragEnter,
				onDragOver,
				onDragLeave,
				onDrop,
			},
			dragOver ? el( 'div', { className: 'djinn-drop-overlay' },
				el( 'div', { className: 'djinn-drop-card' }, '📎 Drop a file to attach it to your wish' )
			) : null,
			el( Sidebar, { chats, activeId: chatId, busy, onNew: newChat, onOpen: openChat, onDelete: deleteChat, width: collapsed ? 0 : sidebarWidth } ),
			el( 'div', { className: 'djinn-resizer', onMouseDown: startResize },
				el( 'button', {
					type: 'button',
					className: 'djinn-resizer-btn',
					onMouseDown: ( e ) => e.stopPropagation(),
					onClick: toggleSidebar,
					title: collapsed ? 'Show past wishes' : 'Hide past wishes',
					'aria-label': 'Toggle past wishes',
				}, collapsed ? '›' : '‹' )
			),
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
						el( 'p', { className: 'djinn-disclosure' },
							Djinn.isOrg
								? 'Wishes and the relevant site content travel through Djinn’s gateway to Google Gemini. '
								: 'Wishes and the relevant site content are sent to your AI provider. ',
							Djinn.isOrg ? el( 'a', { href: Djinn.privacyUrl, target: '_blank', rel: 'noopener' }, 'Privacy' ) : null
						)
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
						ref: inputRef,
						placeholder: 'Whisper your wish…  (Enter to send · Shift+Enter for newline)',
						rows: 1,
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
