import { useState } from '@wordpress/element';
import { Button, Sparkle, Lamp } from '@shared/ui';
import { renderMarkdown } from '@shared/markdown';
import { highlightGraphql, highlightJson } from '@shared/highlight';
import { safeUrl } from '@shared/url';
import { formatBytes } from '@shared/format';
import { downloadUrl } from '@shared/api';
import type { TranscriptMessage } from './chat';

const CODE = 'mt-2 overflow-x-auto whitespace-pre-wrap rounded-[10px] bg-black/45 px-3.5 py-3 font-mono text-xs leading-relaxed text-[#e2e4e7]';
const CODE_LABEL = 'mt-2.5 text-[11px] uppercase tracking-wide text-ivory-muted';

function humanize( s: string ): string {
	return String( s || '' )
		.replace( /([a-z0-9])([A-Z])/g, '$1 $2' )
		.replace( /[_-]+/g, ' ' )
		.replace( /^./, ( c ) => c.toUpperCase() );
}

function actionPurpose( action: TranscriptMessage ): string {
	if ( action.summary ) {
		return action.summary;
	}
	const m = /\{\s*([A-Za-z_][A-Za-z0-9_]*)/.exec( action.operation || '' );
	const field = m ? humanize( m[ 1 ] ) : 'the site';
	return ( action.kind === 'mutation' ? 'Changed ' : 'Looked up ' ) + field.toLowerCase();
}

interface LinkHit { view: string | null; edit: string | null; label: string }
function collectLinks( node: unknown, acc: LinkHit[] = [] ): LinkHit[] {
	if ( ! node || typeof node !== 'object' ) {
		return acc;
	}
	if ( Array.isArray( node ) ) {
		node.forEach( ( n ) => collectLinks( n, acc ) );
		return acc;
	}
	const obj = node as Record<string, unknown>;
	const view = obj.link ? safeUrl( String( obj.link ) ) : null;
	const edit = obj.editUrl ? safeUrl( String( obj.editUrl ) ) : null;
	if ( view || edit ) {
		acc.push( { view, edit, label: String( obj.title || obj.name || obj.id || '' ) } );
	}
	Object.keys( obj ).forEach( ( k ) => collectLinks( obj[ k ], acc ) );
	return acc;
}

interface DownloadHit { token: string; filename: string; bytes?: number }
function collectDownloads( node: unknown, acc: DownloadHit[] = [] ): DownloadHit[] {
	if ( ! node || typeof node !== 'object' ) {
		return acc;
	}
	if ( Array.isArray( node ) ) {
		node.forEach( ( n ) => collectDownloads( n, acc ) );
		return acc;
	}
	const obj = node as Record<string, unknown>;
	if ( obj.token && obj.filename ) {
		acc.push( { token: String( obj.token ), filename: String( obj.filename ), bytes: obj.bytes as number | undefined } );
	}
	Object.keys( obj ).forEach( ( k ) => collectDownloads( obj[ k ], acc ) );
	return acc;
}

function PendingCard( { pending, busy, onConfirm, onCancel }: { pending: TranscriptMessage; busy: boolean; onConfirm: () => void; onCancel: () => void } ) {
	const hasVars = pending.variables && Object.keys( pending.variables ).length > 0;
	return (
		<div className="my-3.5 rounded-djinn bg-gradient-to-b from-gold/[0.18] to-gold/[0.05] px-[18px] py-4 shadow-[0_0_30px_-10px_rgba(251,191,36,0.5)]">
			<div className="mb-1.5 flex items-center gap-1.5 text-sm font-semibold uppercase tracking-wide text-gold">
				<Sparkle />Grant this wish?
			</div>
			<p className="mb-2.5 text-[15px] leading-normal text-ivory">{ pending.summary }</p>
			<details className="mb-2.5">
				<summary className="cursor-pointer select-none text-xs text-ivory-muted hover:text-gold">Show the incantation</summary>
				<pre className={ CODE }>{ highlightGraphql( pending.operation || '' ) }</pre>
				{ hasVars && <pre className={ `${ CODE } bg-black/30` }>{ highlightJson( pending.variables ) }</pre> }
			</details>
			<div className="mt-1.5 flex gap-2.5">
				<Button variant="primary" busy={ busy } onClick={ onConfirm }><Sparkle />Grant</Button>
				<Button variant="tertiary" className="text-ivory-muted hover:bg-white/10 hover:text-ivory" disabled={ busy } onClick={ onCancel }>Refuse</Button>
			</div>
		</div>
	);
}

const GLYPH_TONE: Record<string, string> = {
	ok: 'text-[#6ee7b7]',
	granted: 'text-gold',
	error: 'text-[#f87171]',
	refused: 'text-ivory-muted',
};

function IncantationCard( { action }: { action: TranscriptMessage } ) {
	const [ open, setOpen ] = useState( false );
	const hasVars = action.variables && Object.keys( action.variables ).length > 0;
	const links = action.result ? collectLinks( action.result ) : [];
	const downloads = action.result ? collectDownloads( action.result ) : [];

	return (
		<div className="my-2">
			<button type="button" className="flex w-full items-center gap-2.5 bg-transparent px-0.5 py-0.5 text-left text-[13px]" onClick={ () => setOpen( ( o ) => ! o ) } aria-expanded={ open }>
				<span className={ `inline-flex flex-none ${ GLYPH_TONE[ action.status || 'ok' ] || 'text-gold' }` }><Sparkle /></span>
				<span className="min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap font-serif text-ivory-muted">{ actionPurpose( action ) }</span>
				<span className="flex-none text-[11px] text-ivory-muted opacity-60">{ open ? '▾' : '▸' }</span>
			</button>
			{ open && (
				<div className="ml-1 mt-0.5 px-3 pb-1 pt-0.5">
					{ action.message && <p className="mt-1.5 text-[13px] text-[#f87171]">{ action.message }</p> }
					{ !! links.length && (
						<div className="my-2 flex flex-wrap gap-2">
							{ links.slice( 0, 8 ).map( ( l, idx ) => (
								<span key={ idx } className="inline-flex items-center gap-2 rounded-full bg-gold/[0.14] px-2.5 py-1 text-xs">
									{ l.label && <span className="max-w-[220px] overflow-hidden text-ellipsis whitespace-nowrap text-ivory-muted">{ l.label }</span> }
									{ l.view && <a className="font-semibold text-gold hover:text-gold-deep" href={ l.view } target="_blank" rel="noopener noreferrer">View ↗</a> }
									{ l.edit && <a className="font-semibold text-gold hover:text-gold-deep" href={ l.edit } target="_blank" rel="noopener noreferrer">Edit ✎</a> }
								</span>
							) ) }
						</div>
					) }
					{ !! downloads.length && (
						<div className="my-2 flex flex-wrap gap-2">
							{ downloads.map( ( d, idx ) => (
								<a key={ idx } className="inline-flex items-center rounded-full bg-gold/[0.2] px-3 py-1 font-semibold text-gold hover:bg-gold/[0.28]" href={ downloadUrl( d.token ) }>
									⤓ { d.filename }{ d.bytes ? ` (${ formatBytes( d.bytes ) })` : '' }
								</a>
							) ) }
						</div>
					) }
					<div className={ CODE_LABEL }>Operation</div>
					<pre className={ CODE }>{ highlightGraphql( action.operation || '' ) }</pre>
					{ hasVars && <><div className={ CODE_LABEL }>Variables</div><pre className={ `${ CODE } bg-black/30` }>{ highlightJson( action.variables ) }</pre></> }
					{ !! action.result && <><div className={ CODE_LABEL }>Response</div><pre className={ `${ CODE } max-h-[260px] overflow-y-auto bg-black/30` }>{ highlightJson( action.result ) }</pre></> }
				</div>
			) }
		</div>
	);
}

export function Message( { msg, busy, onConfirm, onCancel }: { msg: TranscriptMessage; busy: boolean; onConfirm: () => void; onCancel: () => void } ) {
	if ( msg.role === 'pending' ) {
		return <PendingCard pending={ msg } busy={ busy } onConfirm={ onConfirm } onCancel={ onCancel } />;
	}
	if ( msg.role === 'action' ) {
		return <IncantationCard action={ msg } />;
	}
	const isAssistant = msg.role === 'assistant';
	const hasText = ( msg.content || '' ) !== '';
	const bubbleBase = 'max-w-full whitespace-pre-wrap rounded-2xl px-[15px] py-2.5 text-sm leading-relaxed';
	const bubble = isAssistant
		? <div className={ `${ bubbleBase } rounded-bl-[4px] bg-white/[0.07] text-ivory` }>{ renderMarkdown( msg.content || '' ) }</div>
		: hasText
			? <div className={ `${ bubbleBase } rounded-br-[4px] bg-gradient-to-b from-[#fff8e7] to-ivory text-[#4a3a1a] shadow-[0_6px_18px_-10px_rgba(0,0,0,0.45)]` }>{ msg.content }</div>
			: null;
	const chips = ( msg.attachments || [] ).map( ( a, i ) => (
		<span key={ 'att' + i } className="inline-flex max-w-full items-center gap-2 self-start rounded-full bg-gold/[0.16] px-3 py-1 text-xs text-ivory">
			📎 { a.filename }{ a.size ? ` (${ formatBytes( a.size ) })` : '' }
		</span>
	) );
	return (
		<div className={ `my-3 flex items-end gap-2 ${ msg.role === 'user' ? 'justify-end' : '' }` }>
			{ isAssistant && (
				<div className="flex h-7 w-7 flex-none items-center justify-center rounded-full border border-gold/35 bg-gold/10 text-gold">
					<Lamp size={ 20 } />
				</div>
			) }
			<div className={ `flex min-w-0 max-w-[76%] flex-col gap-1.5 ${ msg.role === 'user' ? 'items-end' : 'items-start' }` }>
				{ bubble }
				{ chips }
			</div>
		</div>
	);
}
