import { useState, useRef, useEffect, createPortal } from '@wordpress/element';
import type { ReactNode, CSSProperties } from 'react';

// ---- Button + Spinner -------------------------------------------------------------------------

interface ButtonProps {
	variant?: 'primary' | 'secondary' | 'tertiary';
	isDestructive?: boolean;
	busy?: boolean;
	disabled?: boolean;
	onClick?: () => void;
	title?: string;
	className?: string;
	children: ReactNode;
}

const BTN_BASE =
	'inline-flex items-center justify-center gap-2 rounded-control px-3 py-1.5 text-[13px] font-semibold transition disabled:cursor-default disabled:opacity-50';
const BTN_TONE = {
	primary: 'bg-gradient-to-b from-gold to-gold-deep text-midnight hover:shadow-[0_4px_14px_-4px_rgba(251,191,36,0.7)]',
	secondary: 'bg-[#ececef] text-[#1d2327] hover:bg-[#e2e2e6]',
	tertiary: 'bg-transparent',
};

export function Button( { variant = 'secondary', isDestructive, busy, disabled, onClick, title, className = '', children }: ButtonProps ) {
	const destructive = isDestructive ? ' text-[#b32d2e]' : '';
	return (
		<button
			type="button"
			className={ `${ BTN_BASE } ${ BTN_TONE[ variant ] }${ destructive } ${ className }` }
			disabled={ disabled || busy }
			onClick={ onClick }
			title={ title }
		>
			{ busy && <Spinner /> }
			{ children }
		</button>
	);
}

export function Spinner() {
	return <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden />;
}

export function Skeleton( { className = '' }: { className?: string } ) {
	return <span className={ `block animate-pulse rounded bg-black/10 ${ className }` } aria-hidden />;
}

// ---- Notice -----------------------------------------------------------------------------------

type NoticeStatus = 'info' | 'success' | 'error' | 'warning';
const NOTICE_TONE: Record<NoticeStatus, string> = {
	info: 'border-gold bg-[rgba(251,191,36,0.08)]',
	success: 'border-[#34d399] bg-[#ecfdf5]',
	error: 'border-[#f87171] bg-[#fef2f2]',
	warning: 'border-[#f59e0b] bg-[#fffbeb]',
};

export function Notice( { status = 'info', children }: { status?: NoticeStatus; children: ReactNode } ) {
	return (
		<div className={ `flex items-center gap-2 rounded-control border-l-4 px-3 py-2.5 text-[13px] text-[#1d2327] ${ NOTICE_TONE[ status ] }` } role="status">
			{ children }
		</div>
	);
}

// ---- Tile / Card / StatCard -------------------------------------------------------------------

export function Tile( { title, actions, className = '', children }: { title: string; actions?: ReactNode; className?: string; children: ReactNode } ) {
	return (
		<section className={ `flex h-full w-full min-w-0 flex-col overflow-hidden bg-white ${ className }` }>
			<header className="flex flex-none items-center gap-2 bg-gradient-to-br from-midnight to-violet px-5 py-2.5">
				<span className="text-[13px] leading-none text-gold" aria-hidden>✦</span>
				<h2 className="text-[15px] font-semibold leading-tight text-ivory">{ title }</h2>
				{ actions && <div className="ml-auto">{ actions }</div> }
			</header>
			<div className="min-h-0 flex-1 overflow-auto p-5 [overscroll-behavior:contain] [&>*:first-child]:mt-0">{ children }</div>
		</section>
	);
}

export function Card( { className = '', children }: { className?: string; children: ReactNode } ) {
	return <div className={ `flex-1 rounded-djinn bg-[#f5f5f7] px-[18px] py-4 ${ className }` }>{ children }</div>;
}

export function StatCard( { value, label, sub }: { value: ReactNode; label: string; sub?: string } ) {
	return (
		<div className="min-w-[160px] flex-1 rounded-djinn bg-[#f5f5f7] px-[18px] py-4">
			<div className="text-[26px] font-semibold leading-tight">{ value }</div>
			<div className="mt-1.5 font-semibold text-[#1d2327]">{ label }</div>
			{ sub && <div className="mt-0.5 text-xs text-[#787c82]">{ sub }</div> }
		</div>
	);
}

export function Cards( { children }: { children: ReactNode } ) {
	return <div className="my-3 flex flex-wrap gap-4">{ children }</div>;
}

// ---- Form primitives --------------------------------------------------------------------------

export function Field( { label, htmlFor, description, children }: { label?: string; htmlFor?: string; description?: ReactNode; children: ReactNode } ) {
	return (
		<div className="mb-[18px]">
			{ label && <label className="mb-1.5 block font-semibold" htmlFor={ htmlFor }>{ label }</label> }
			<div>
				{ children }
				{ description && <p className="mt-1.5 text-xs text-[#787c82]">{ description }</p> }
			</div>
		</div>
	);
}

export interface Option {
	value: string;
	label: string;
}
export interface OptionGroup {
	label: string;
	options: Option[];
}

const CONTROL = 'min-w-[280px] max-w-full rounded-control border-0 bg-[#ececef] px-2.5 py-1.5 text-[13px] text-[#1d2327] outline-none transition focus:bg-white focus:shadow-[0_0_0_2px_rgba(251,191,36,0.45)]';

export function Select( {
	id,
	value,
	onChange,
	options,
	groups,
	disabled,
	placeholder,
}: {
	id?: string;
	value: string;
	onChange: ( v: string ) => void;
	options?: Option[];
	groups?: OptionGroup[];
	disabled?: boolean;
	placeholder?: string;
} ) {
	const opt = ( o: Option ) => <option key={ o.value } value={ o.value }>{ o.label }</option>;
	return (
		<select id={ id } className={ CONTROL } value={ value ?? '' } disabled={ !! disabled } onChange={ ( e ) => onChange( e.target.value ) }>
			{ placeholder != null && <option value="">{ placeholder }</option> }
			{ groups
				? groups.filter( ( g ) => g.options.length ).map( ( g, i ) => <optgroup key={ i } label={ g.label }>{ g.options.map( opt ) }</optgroup> )
				: ( options || [] ).map( opt ) }
		</select>
	);
}

export function PasswordField( { id, value, onChange, placeholder }: { id?: string; value: string; onChange: ( v: string ) => void; placeholder?: string } ) {
	return (
		<input
			type="password"
			id={ id }
			className={ CONTROL }
			value={ value || '' }
			placeholder={ placeholder }
			autoComplete="off"
			onChange={ ( e ) => onChange( e.target.value ) }
		/>
	);
}

// ---- Table ------------------------------------------------------------------------------------

export interface Column<Row> {
	label: string;
	key?: string;
	render?: ( row: Row ) => ReactNode;
}

export function Table<Row>( { columns, rows, empty }: { columns: Column<Row>[]; rows: Row[]; empty?: string } ) {
	if ( ! rows.length ) {
		return empty ? <p className="text-[#787c82]">{ empty }</p> : null;
	}
	return (
		<table className="w-full overflow-hidden rounded-control text-[13px] [border-collapse:collapse]">
			<thead>
				<tr className="bg-[#ececef] text-left">
					{ columns.map( ( c, i ) => <th key={ i } className="px-3 py-2 font-semibold text-[#3c434a]">{ c.label }</th> ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row, ri ) => (
					<tr key={ ri } className="odd:bg-white even:bg-[#f7f7f9]">
						{ columns.map( ( c, ci ) => (
							<td key={ ci } className="px-3 py-1.5">{ c.render ? c.render( row ) : String( ( row as Record<string, unknown> )[ c.key as string ] ?? '' ) }</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

// ---- Popover: immediate hover panel, portaled to <body>, fixed-positioned from the trigger rect -

export function Popover( { placement = 'top', content, className = '', children }: { placement?: 'top' | 'bottom'; content: ReactNode; className?: string; children: ReactNode } ) {
	const [ rect, setRect ] = useState<DOMRect | null>( null );
	const ref = useRef<HTMLSpanElement>( null );
	const show = () => ref.current && setRect( ref.current.getBoundingClientRect() );
	const hide = () => setRect( null );

	let panel: ReactNode = null;
	if ( rect ) {
		const style: CSSProperties = { position: 'fixed', zIndex: 100002 };
		if ( rect.left + rect.width / 2 > window.innerWidth / 2 ) {
			style.right = Math.max( 8, window.innerWidth - rect.right );
		} else {
			style.left = Math.max( 8, rect.left );
		}
		if ( placement === 'bottom' ) {
			style.top = rect.bottom + 8;
		} else {
			style.bottom = window.innerHeight - rect.top + 8;
		}
		// Portal into a `.djinn-app` wrapper (not bare <body>): Tailwind utilities are scoped under
		// `.djinn-app` (the `important` option), so a body-level portal would render unstyled — which is
		// why the panel looked transparent. The wrapper has no transform/filter, so `position: fixed`
		// stays viewport-relative.
		panel = createPortal(
			<div className="djinn-app">
				<span
					className="pointer-events-none block min-w-[200px] max-w-[320px] rounded-xl bg-[rgba(20,14,38,0.85)] px-3.5 py-2.5 text-xs leading-relaxed text-ivory shadow-[inset_0_0_0_1px_rgba(255,255,255,0.10),inset_0_1px_0_rgba(255,255,255,0.14),0_16px_44px_-12px_rgba(0,0,0,0.75)] [-webkit-backdrop-filter:blur(22px)_saturate(160%)] [backdrop-filter:blur(22px)_saturate(160%)]"
					role="tooltip"
					style={ style }
				>
					{ content }
				</span>
			</div>,
			document.body
		);
	}
	return (
		<span ref={ ref } className={ `inline-flex ${ className }` } onMouseEnter={ show } onMouseLeave={ hide } onFocus={ show } onBlur={ hide }>
			{ children }
			{ panel }
		</span>
	);
}

// ---- ResizeHandle: a thin gold seam, draggable on either axis (paired with usePanelResize) -----

export function ResizeHandle( { axis = 'x', onMouseDown, title, className = '', children }: { axis?: 'x' | 'y'; onMouseDown: ( e: React.MouseEvent ) => void; title?: string; className?: string; children?: ReactNode } ) {
	const shape = axis === 'x' ? 'w-px self-stretch cursor-col-resize' : 'h-px cursor-row-resize';
	return (
		<div
			className={ `relative z-[2] flex-none bg-divider transition hover:bg-gold hover:shadow-glow ${ shape } ${ className }` }
			onMouseDown={ onMouseDown }
			title={ title }
			role="separator"
			aria-orientation={ axis === 'x' ? 'vertical' : 'horizontal' }
		>
			{ children }
		</div>
	);
}

// ---- Icons ------------------------------------------------------------------------------------

export function Lamp( { size = 28, glow = false }: { size?: number; glow?: boolean } ) {
	return (
		<svg
			className={ glow ? 'drop-shadow-[0_0_8px_rgba(251,191,36,0.6)]' : undefined }
			width={ size }
			height={ size }
			viewBox="0 0 64 64"
			fill="none"
			stroke="currentColor"
			strokeWidth={ 2.2 }
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden
		>
			<path d="M7 21 C5 17 9 15 7.5 11 C10.5 14 10.5 18 9 20" />
			<path d="M20 35 C13 32 8 28 6 22 L10 20.5 C12 26 16 30 22 33 Z" />
			<path d="M19 45 C12 45 10 38 15 34 C21 29 31 27 41 29 C50 31 54 35 53 40 C52 45 46 47 39 47 L23 47 C21.5 47 20 46.2 19 45 Z" />
			<path d="M27 28.5 C30 24 38 24 41 28.5" />
			<circle cx={ 34 } cy={ 23 } r={ 2.2 } fill="currentColor" stroke="none" />
			<path d="M53 35 C60 36 62 43 56 47" />
			<path d="M24 47 L40 47 L37 52 L27 52 Z" />
		</svg>
	);
}

export function Sparkle() {
	return (
		<svg width={ 14 } height={ 14 } viewBox="0 0 16 16" aria-hidden>
			<path d="M8 1 L9.4 6.6 L15 8 L9.4 9.4 L8 15 L6.6 9.4 L1 8 L6.6 6.6 Z" fill="currentColor" />
		</svg>
	);
}

// ---- Toasts: a tiny pub/sub + a host per app --------------------------------------------------

type ToastStatus = 'success' | 'error' | 'info';
interface ToastItem {
	id: number;
	message: string;
	status: ToastStatus;
}
const listeners = new Set<( t: ToastItem ) => void>();
let seq = 0;

export function toast( message: string, status: ToastStatus = 'success' ): void {
	const t = { id: ++seq, message, status };
	listeners.forEach( ( fn ) => fn( t ) );
}

const TOAST_TONE: Record<ToastStatus, string> = {
	success: 'border-l-[#34d399]',
	error: 'border-l-[#f87171]',
	info: 'border-l-gold',
};

export function ToastHost() {
	const [ items, setItems ] = useState<ToastItem[]>( [] );
	useEffect( () => {
		const fn = ( t: ToastItem ): void => {
			setItems( ( list ) => [ ...list, t ] );
			setTimeout( () => setItems( ( list ) => list.filter( ( x ) => x.id !== t.id ) ), t.status === 'error' ? 7000 : 4500 );
		};
		listeners.add( fn );
		return () => {
			listeners.delete( fn );
		};
	}, [] );
	return (
		<div className="pointer-events-none fixed right-6 top-[44px] z-[100001] flex flex-col items-end gap-2">
			{ items.map( ( t ) => (
				<div
					key={ t.id }
					className={ `pointer-events-auto max-w-[360px] cursor-pointer rounded-xl border-l-[3px] bg-[rgba(20,14,38,0.82)] px-[15px] py-[11px] text-[13px] leading-snug text-ivory shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_14px_38px_-12px_rgba(0,0,0,0.6)] [-webkit-backdrop-filter:blur(20px)_saturate(160%)] [backdrop-filter:blur(20px)_saturate(160%)] ${ TOAST_TONE[ t.status ] }` }
					role="status"
					style={ { animation: 'djinnToastIn 0.18s ease-out' } }
					onClick={ () => setItems( ( list ) => list.filter( ( x ) => x.id !== t.id ) ) }
				>
					{ t.message }
				</div>
			) ) }
		</div>
	);
}
