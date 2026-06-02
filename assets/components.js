/* global wp */
/**
 * DjinnUI — the shared, no-build component library used by both the Lamp app (admin.js) and the
 * Cave app (cave.js). Plain wp.element factories published on window.DjinnUI; load order is
 * guaranteed by registering this as the `djinn-ui` script dependency of each app.
 */
( function () {
	const el = wp.element.createElement;
	const { useState, useRef, useEffect, useCallback, Fragment, createPortal } = wp.element;
	const { Button: WpButton, Spinner, Notice } = wp.components;

	// ---- data helper -------------------------------------------------------------------------
	// Each app calls makeApi({ restUrl, nonce }) once to get a fetch helper bound to its config.
	function makeApi( cfg ) {
		return function api( path, body, method ) {
			const m = method || ( body !== undefined ? 'POST' : 'GET' );
			return fetch( cfg.restUrl + path, {
				method: m,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: body !== undefined ? JSON.stringify( body ) : undefined,
			} ).then( ( r ) => r.json() );
		};
	}

	// ---- formatting --------------------------------------------------------------------------
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

	// ---- Markdown → React (no library, no innerHTML; XSS-safe by construction) ----------------
	function safeUrl( url ) {
		const u = String( url || '' ).trim();
		return /^(https?:|mailto:|\/|#)/i.test( u ) ? u : null;
	}

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
		let i = start + 2;
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

	// ---- icons -------------------------------------------------------------------------------
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
			el( 'path', { d: 'M7 21 C5 17 9 15 7.5 11 C10.5 14 10.5 18 9 20', className: 'djinn-lamp-wisp' } ),
			el( 'path', { d: 'M20 35 C13 32 8 28 6 22 L10 20.5 C12 26 16 30 22 33 Z' } ),
			el( 'path', { d: 'M19 45 C12 45 10 38 15 34 C21 29 31 27 41 29 C50 31 54 35 53 40 C52 45 46 47 39 47 L23 47 C21.5 47 20 46.2 19 45 Z' } ),
			el( 'path', { d: 'M27 28.5 C30 24 38 24 41 28.5' } ),
			el( 'circle', { cx: 34, cy: 23, r: 2.2, fill: 'currentColor', stroke: 'none' } ),
			el( 'path', { d: 'M53 35 C60 36 62 43 56 47' } ),
			el( 'path', { d: 'M24 47 L40 47 L37 52 L27 52 Z' } )
		);
	}

	function Sparkle() {
		return el(
			'svg',
			{ width: 14, height: 14, viewBox: '0 0 16 16', 'aria-hidden': true, className: 'djinn-sparkle' },
			el( 'path', { d: 'M8 1 L9.4 6.6 L15 8 L9.4 9.4 L8 15 L6.6 9.4 L1 8 L6.6 6.6 Z', fill: 'currentColor' } )
		);
	}

	// ---- Button: thin wrapper over wp.components.Button, accepting `busy` as a `isBusy` alias --
	function Button( props ) {
		const { busy, isBusy, disabled, ...rest } = props;
		const b = busy || isBusy;
		return el( WpButton, Object.assign( {}, rest, { isBusy: b, disabled: disabled || b } ) );
	}

	// ---- Tile: the Cave's panel chrome (gold ✦ header + scrollable body) ----------------------
	function Tile( props ) {
		return el(
			'section',
			{ className: 'djinn-tile' + ( props.tone ? ' djinn-tile--' + props.tone : '' ) + ( props.className ? ' ' + props.className : '' ) },
			el( 'header', { className: 'djinn-tile-head' },
				el( 'span', { className: 'djinn-tile-mark' }, '✦' ),
				el( 'h2', null, props.title ),
				props.actions ? el( 'div', { className: 'djinn-tile-actions' }, props.actions ) : null
			),
			el( 'div', { className: 'djinn-tile-body' }, props.children )
		);
	}

	// ---- Card / StatCard ---------------------------------------------------------------------
	function Card( props ) {
		return el( 'div', { className: 'djinn-card' + ( props.className ? ' ' + props.className : '' ) }, props.children );
	}

	function StatCard( { value, label, sub } ) {
		return el( 'div', { className: 'djinn-card djinn-stat' },
			el( 'div', { className: 'djinn-card-value' }, value ),
			el( 'div', { className: 'djinn-card-label' }, label ),
			sub ? el( 'div', { className: 'djinn-card-sub' }, sub ) : null
		);
	}

	// ---- Popover: immediate (no-delay) hover panel, portaled to <body> and fixed-positioned from
	// the trigger's rect so it can't be clipped by an overflow ancestor or run off a screen edge. ---
	function Popover( props ) {
		const [ rect, setRect ] = useState( null );
		const ref = useRef( null );
		const show = () => { if ( ref.current ) { setRect( ref.current.getBoundingClientRect() ); } };
		const hide = () => setRect( null );
		let panel = null;
		if ( rect ) {
			const placement = props.placement || 'top';
			const style = { position: 'fixed', zIndex: 100002 };
			// Hug whichever horizontal edge keeps the panel on screen.
			if ( rect.left + rect.width / 2 > window.innerWidth / 2 ) {
				style.right = Math.max( 8, window.innerWidth - rect.right ) + 'px';
			} else {
				style.left = Math.max( 8, rect.left ) + 'px';
			}
			if ( placement === 'bottom' ) {
				style.top = ( rect.bottom + 8 ) + 'px';
			} else {
				style.bottom = ( window.innerHeight - rect.top + 8 ) + 'px';
			}
			panel = createPortal( el( 'span', { className: 'djinn-pop', role: 'tooltip', style }, props.content ), document.body );
		}
		return el( 'span', {
			ref,
			className: 'djinn-pop-anchor' + ( props.className ? ' ' + props.className : '' ),
			onMouseEnter: show,
			onMouseLeave: hide,
			onFocus: show,
			onBlur: hide,
		}, props.children, panel );
	}

	// ---- Table -------------------------------------------------------------------------------
	function Table( { columns, rows, empty, className } ) {
		if ( ! rows || ! rows.length ) {
			return empty ? el( 'p', { className: 'djinn-table-empty' }, empty ) : null;
		}
		return el( 'table', { className: 'widefat striped djinn-table' + ( className ? ' ' + className : '' ) },
			el( 'thead', null, el( 'tr', null, ...columns.map( ( c, i ) => el( 'th', { key: i }, c.label ) ) ) ),
			el( 'tbody', null, ...rows.map( ( row, ri ) =>
				el( 'tr', { key: ri }, ...columns.map( ( c, ci ) =>
					el( 'td', { key: ci }, c.render ? c.render( row ) : row[ c.key ] )
				) )
			) )
		);
	}

	// ---- Form primitives ---------------------------------------------------------------------
	function Field( { label, htmlFor, description, children } ) {
		return el( 'div', { className: 'djinn-field' },
			label ? el( 'label', { className: 'djinn-field-label', htmlFor }, label ) : null,
			el( 'div', { className: 'djinn-field-control' },
				children,
				description ? el( 'p', { className: 'djinn-field-desc' }, description ) : null
			)
		);
	}

	function Select( { id, value, onChange, options, groups, disabled, placeholder } ) {
		const opt = ( o ) => el( 'option', { key: o.value, value: o.value }, o.label );
		const body = groups
			? groups.filter( ( g ) => g.options && g.options.length ).map( ( g, i ) => el( 'optgroup', { key: i, label: g.label }, ...g.options.map( opt ) ) )
			: ( options || [] ).map( opt );
		const children = placeholder != null ? [ el( 'option', { key: '__ph', value: '' }, placeholder ) ].concat( body ) : body;
		return el( 'select', {
			id,
			className: 'djinn-select',
			value: value == null ? '' : value,
			disabled: !! disabled,
			onChange: ( e ) => onChange( e.target.value ),
		}, children );
	}

	function PasswordField( { id, value, onChange, placeholder } ) {
		return el( 'input', {
			type: 'password',
			id,
			className: 'regular-text djinn-text',
			value: value || '',
			placeholder,
			autoComplete: 'off',
			onChange: ( e ) => onChange( e.target.value ),
		} );
	}

	// ---- usePanelResize: drag-to-size hook, persisted to localStorage -------------------------
	// Generalized from the Lamp sidebar resizer. The consumer renders the seam element and wires
	// onMouseDown:startResize, guarding it (e.g. when collapsed) however it likes.
	function usePanelResize( { storageKey, min, max, initial, axis } ) {
		const vertical = axis === 'y';
		const read = () => {
			try {
				const w = parseInt( localStorage.getItem( storageKey ), 10 );
				return ( w >= min && w <= max ) ? w : initial;
			} catch ( e ) {
				return initial;
			}
		};
		const [ size, setSize ] = useState( read );
		const [ resizing, setResizing ] = useState( false );
		function startResize( e ) {
			e.preventDefault();
			const startPos = vertical ? e.clientY : e.clientX;
			const startW = size;
			let last = startW;
			setResizing( true );
			function move( ev ) {
				const pos = vertical ? ev.clientY : ev.clientX;
				last = Math.min( max, Math.max( min, startW + ( pos - startPos ) ) );
				setSize( last );
			}
			function up() {
				document.removeEventListener( 'mousemove', move );
				document.removeEventListener( 'mouseup', up );
				setResizing( false );
				try { localStorage.setItem( storageKey, String( last ) ); } catch ( e2 ) {}
			}
			document.addEventListener( 'mousemove', move );
			document.addEventListener( 'mouseup', up );
		}
		return { size, setSize, resizing, startResize };
	}

	// ---- Toasts: transient, on-theme notifications (a tiny pub/sub + a host per app) -------------
	// Call DjinnUI.toast(message, status) from anywhere; mount one <ToastHost/> in each app's tree.
	const toastListeners = new Set();
	let toastSeq = 0;
	function toast( message, status ) {
		const t = { id: ++toastSeq, message, status: status || 'success' };
		toastListeners.forEach( ( fn ) => fn( t ) );
	}
	function ToastHost() {
		const [ items, setItems ] = useState( [] );
		useEffect( () => {
			const fn = ( t ) => {
				setItems( ( list ) => list.concat( t ) );
				setTimeout( () => setItems( ( list ) => list.filter( ( x ) => x.id !== t.id ) ), t.status === 'error' ? 7000 : 4500 );
			};
			toastListeners.add( fn );
			return () => toastListeners.delete( fn );
		}, [] );
		const dismiss = ( id ) => setItems( ( list ) => list.filter( ( x ) => x.id !== id ) );
		return el( 'div', { className: 'djinn-toasts' },
			items.map( ( t ) => el( 'div', {
				key: t.id,
				className: 'djinn-toast djinn-toast--' + t.status,
				role: 'status',
				onClick: () => dismiss( t.id ),
			}, t.message ) )
		);
	}

	// ---- ResizeHandle: a draggable seam (horizontal or vertical), paired with usePanelResize ------
	function ResizeHandle( { axis, onMouseDown, title, className, children } ) {
		const a = axis === 'y' ? 'y' : 'x';
		return el( 'div', {
			className: 'djinn-resize-handle djinn-resize-handle--' + a + ( className ? ' ' + className : '' ),
			onMouseDown,
			title,
			role: 'separator',
			'aria-orientation': a === 'x' ? 'vertical' : 'horizontal',
		}, children );
	}

	window.DjinnUI = {
		el, useState, useRef, useEffect, useCallback, Fragment,
		Button, Spinner, Notice, Lamp, Sparkle,
		Tile, Card, StatCard, Popover, Table, Field, Select, PasswordField,
		usePanelResize, ResizeHandle, ToastHost, toast,
		makeApi, formatCost, formatBytes, renderMarkdown, safeUrl,
	};
} )();
