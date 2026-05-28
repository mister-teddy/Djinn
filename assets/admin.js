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

	function Message( { msg, busy, onConfirm, onCancel } ) {
		if ( msg.role === 'pending' ) {
			return el( PendingCard, { pending: msg.pending, busy, onConfirm, onCancel } );
		}
		return el(
			'div',
			{ className: 'djinn-msg djinn-msg-' + msg.role },
			msg.role === 'assistant' ? el( 'div', { className: 'djinn-msg-avatar' }, el( Lamp, { size: 20 } ) ) : null,
			el( 'div', { className: 'djinn-bubble' }, msg.content )
		);
	}

	function App() {
		const [ messages, setMessages ] = useState( [] );
		const [ input, setInput ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ chatId, setChatId ] = useState( 0 );
		const [ indexed, setIndexed ] = useState( Djinn.indexed );
		const [ error, setError ] = useState( '' );
		const scroller = useRef( null );

		useEffect( () => {
			if ( scroller.current ) {
				scroller.current.scrollTop = scroller.current.scrollHeight;
			}
		}, [ messages, busy ] );

		function handleResult( result ) {
			if ( result.chat_id ) {
				setChatId( result.chat_id );
			}
			if ( result.status === 'error' ) {
				setError( result.message || 'The lamp dimmed.' );
				return;
			}
			setError( '' );
			if ( result.status === 'awaiting_confirmation' ) {
				setMessages( ( m ) => [ ...m, { role: 'pending', pending: result.pending } ] );
			} else if ( result.status === 'complete' ) {
				setMessages( ( m ) => [ ...m, { role: 'assistant', content: result.message } ] );
			}
		}

		async function send() {
			const text = input.trim();
			if ( ! text || busy ) {
				return;
			}
			setInput( '' );
			setMessages( ( m ) => [ ...m, { role: 'user', content: text } ] );
			setBusy( true );
			try {
				handleResult( await api( '/wish', { chat_id: chatId, message: text } ) );
			} catch ( e ) {
				setError( String( e ) );
			} finally {
				setBusy( false );
			}
		}

		async function resolvePending( idx, confirmed ) {
			const pending = messages[ idx ].pending;
			setMessages( ( m ) => m.filter( ( _, i ) => i !== idx ) );
			setBusy( true );
			try {
				handleResult( await api( '/grant', { chat_id: chatId, pending_id: pending.id, confirmed } ) );
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
				el(
					Button,
					{ variant: 'secondary', isBusy: busy, disabled: busy, onClick: reindex },
					indexed ? 'Refresh the lamp' : 'Awaken the lamp'
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
								onConfirm: () => resolvePending( i, true ),
								onCancel: () => resolvePending( i, false ),
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
		);
	}

	wp.element.render( el( App ), document.getElementById( 'djinn-root' ) );
} )();
