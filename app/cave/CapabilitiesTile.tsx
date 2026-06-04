import { useState, useEffect } from '@wordpress/element';
import { Tile, Spinner, Popover } from '@shared/ui';
import { loadOperations, type OperationsData, type OperationInfo } from './data';

export function CapabilitiesTile() {
	const [ ops, setOps ] = useState<OperationsData | null>( null );
	useEffect( () => {
		loadOperations().then( setOps ).catch( () => {} );
	}, [] );

	return (
		<Tile title="Capabilities">
			<p className="text-[#787c82]">
				Everything the Djinn can do here — the operations it can run, grouped by area. Build the index from the Lamp so it can find them by meaning.
			</p>
			{ ops ? <OperationsList ops={ ops } /> : <Spinner /> }
			{ ops && <UnindexedSection ops={ ops } /> }
		</Tile>
	);
}

function opDetails( o: OperationInfo ) {
	return (
		<div>
			{ o.description && <p className="mb-2">{ o.description }</p> }
			{ o.args.length ? (
				<dl className="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
					{ o.args.map( ( a, i ) => [
						<dt key={ 'k' + i } className="text-ivory-muted">
							{ a.name }
							{ a.required && <span className="text-gold" title="required"> *</span> }
						</dt>,
						<dd key={ 'v' + i } className="m-0 text-right [font-variant-numeric:tabular-nums]">{ a.type || '—' }</dd>,
					] ) }
				</dl>
			) : (
				<p className="m-0 text-ivory-muted">No parameters.</p>
			) }
			<div className="mt-2 border-t border-gold/20 pt-2">Returns <code className="text-ivory">{ o.returns || '—' }</code></div>
		</div>
	);
}

function OperationsList( { ops }: { ops: OperationsData } ) {
	const byDomain: Record<string, OperationInfo[]> = {};
	ops.operations.forEach( ( o ) => {
		( byDomain[ o.domain ] = byDomain[ o.domain ] || [] ).push( o );
	} );
	const domains = Object.keys( byDomain ).sort();
	const [ open, setOpen ] = useState<Record<string, boolean>>( {} );
	const toggle = ( d: string ) => setOpen( ( o ) => ( { ...o, [ d ]: ! o[ d ] } ) );

	return (
		<div className="mt-2">
			{ domains.map( ( d ) => {
				const isOpen = !! open[ d ];
				const list = byDomain[ d ].slice().sort( ( a, b ) => a.name.localeCompare( b.name ) );
				return (
					<div key={ d } className="border-t border-[#f0f0f1]">
						<button
							type="button"
							className="flex w-full items-center gap-2 bg-transparent px-0.5 py-2 text-left text-[#1d2327] hover:text-black"
							aria-expanded={ isOpen }
							onClick={ () => toggle( d ) }
						>
							<span className={ `text-[10px] leading-none text-[#787c82] transition-transform ${ isOpen ? 'rotate-90' : '' }` } aria-hidden>▶</span>
							<h3 className="m-0 text-[13px] font-semibold uppercase tracking-wide text-[#50575e]">{ d }</h3>
							<span className="ml-auto text-[11px] font-normal text-[#787c82]">{ list.length }</span>
						</button>
						{ isOpen && (
							<div className="mb-2">
								{ list.map( ( o ) => (
									<Popover key={ o.kind + ':' + o.name } placement="top" content={ opDetails( o ) } className="flex w-full items-center gap-2 border-t border-[#f6f7f7] py-1.5 pl-3.5">
										<span className={ `inline-block rounded px-1.5 text-[10px] font-semibold uppercase tracking-wide ${ o.kind === 'query' ? 'bg-[rgba(110,231,183,0.16)] text-[#1f7a5c]' : 'bg-violet-soft text-[#6d28d9]' }` }>{ o.kind }</span>
										<code className="flex-none font-semibold">{ o.name }</code>
										{ o.description && <span className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-xs text-[#787c82]">{ o.description }</span> }
									</Popover>
								) ) }
							</div>
						) }
					</div>
				);
			} ) }
		</div>
	);
}

function UnindexedSection( { ops }: { ops: OperationsData } ) {
	if ( ! ops.unindexed.length && ! ops.outdated.length ) {
		return null;
	}
	return (
		<div className="mt-[18px]">
			<h3 className="mb-2 mt-5 text-[13px] font-semibold uppercase tracking-wide text-[#50575e]">Not yet indexed</h3>
			<p className="text-[#787c82]">Build or update the index so the Djinn can find these by meaning.</p>
			<ul className="m-0 mt-2 flex list-none flex-wrap gap-1.5 p-0">
				{ ops.unindexed.map( ( t ) => (
					<li key={ 'u' + t } className="rounded-md bg-[rgba(251,191,36,0.18)] px-2 py-0.5 text-xs text-[#7a5c12]">{ t }</li>
				) ) }
				{ ops.outdated.map( ( t ) => (
					<li key={ 'o' + t } className="rounded-md bg-[rgba(245,158,11,0.22)] px-2 py-0.5 text-xs text-[#92400e]">{ t } (changed)</li>
				) ) }
			</ul>
		</div>
	);
}
