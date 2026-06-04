import { useState, useEffect } from '@wordpress/element';
import { config } from '@shared/api';
import { Tile, Spinner, Button, StatCard, Cards, Table, toast, type Column } from '@shared/ui';
import { formatCost, cap } from '@shared/format';
import { loadUsage, resetUsage, type UsageData, type UsageRow, type UsageRecentRow } from './data';

export function SpendTile() {
	const [ data, setData ] = useState<UsageData | null>( null );
	const [ resetting, setResetting ] = useState( false );
	const isOrg = config.isOrg;

	const load = () => loadUsage().then( setData ).catch( () => {} );
	useEffect( () => {
		load();
	}, [] );

	if ( ! data ) {
		return <Tile title="Spend"><Spinner /></Tile>;
	}
	const t = data.totals;
	const empty = ! t.calls;

	async function reset() {
		if ( ! window.confirm( 'Reset the entire usage tally? This cannot be undone.' ) ) {
			return;
		}
		setResetting( true );
		try {
			await resetUsage();
			toast( 'Usage tally reset.' );
			load();
		} finally {
			setResetting( false );
		}
	}

	type AnyRow = UsageRow | UsageRecentRow;
	const providerCol: Column<AnyRow> = { label: 'Provider', render: ( r ) => cap( r.provider || '' ) };
	const modelCol: Column<AnyRow> = { label: 'Model', render: ( r ) => <code>{ r.model }</code> };
	const costCol: Column<AnyRow> = { label: isOrg ? 'Cost' : 'Est. cost', render: ( r ) => formatCost( r.cost ) };

	const byModelCols: Column<UsageRow>[] = [
		...( isOrg ? [] : [ providerCol, modelCol ] ),
		{ label: isOrg ? 'Type' : 'Kind', key: 'kind' },
		{ label: 'Calls', render: ( r ) => r.calls.toLocaleString() },
		{ label: 'Input', render: ( r ) => r.prompt.toLocaleString() },
		{ label: 'Output', render: ( r ) => r.completion.toLocaleString() },
		costCol,
	];
	const recentCols: Column<UsageRecentRow>[] = [
		{ label: 'When (UTC)', key: 'createdAt' },
		...( isOrg ? [] : [ providerCol, modelCol ] ),
		{ label: isOrg ? 'Type' : 'Kind', key: 'kind' },
		{ label: 'In', render: ( r ) => r.promptTokens.toLocaleString() },
		{ label: 'Out', render: ( r ) => r.completionTokens.toLocaleString() },
		costCol,
	];

	return (
		<Tile title="Spend">
			<p className="text-[#787c82]">
				{ isOrg
					? 'What using the Djinn has cost — billed to your account as you go.'
					: 'Estimated spend — based on public list prices, so treat it as a guide.' }
			</p>
			<Cards>
				<StatCard
					value={ formatCost( t.cost ) }
					label={ isOrg ? 'Charged so far' : 'Estimated spend' }
					sub={ isOrg ? 'billed to your account' : t.hasEstimates ? 'includes estimated tokens' : 'all metered' }
				/>
				<StatCard value={ t.calls.toLocaleString() } label="Provider calls" sub="chat + embed" />
				<StatCard value={ t.prompt.toLocaleString() } label="Input tokens" />
				<StatCard value={ t.completion.toLocaleString() } label="Output tokens" />
			</Cards>
			<h3 className="mb-2 mt-5 text-[13px] font-semibold uppercase tracking-wide text-[#50575e]">Last 14 days</h3>
			<DailyBars byDay={ data.byDay } />
			{ ! empty && (
				<>
					<h3 className="mb-2 mt-5 text-[13px] font-semibold uppercase tracking-wide text-[#50575e]">{ isOrg ? 'By type' : 'By model' }</h3>
					<Table columns={ byModelCols } rows={ data.byModel } />
					<h3 className="mb-2 mt-5 text-[13px] font-semibold uppercase tracking-wide text-[#50575e]">Recent calls</h3>
					<Table columns={ recentCols } rows={ data.recent } />
					{ ! isOrg && (
						<div className="mt-6">
							<Button isDestructive busy={ resetting } onClick={ reset }>Reset the tally</Button>
						</div>
					) }
				</>
			) }
		</Tile>
	);
}

function DailyBars( { byDay }: { byDay: { day: string; cost: number }[] } ) {
	const byKey: Record<string, number> = {};
	byDay.forEach( ( r ) => {
		byKey[ r.day ] = Number( r.cost ) || 0;
	} );
	const now = new Date();
	const days: { key: string; cost: number }[] = [];
	for ( let i = 13; i >= 0; i-- ) {
		const d = new Date( Date.UTC( now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - i ) );
		const key = d.toISOString().slice( 0, 10 );
		days.push( { key, cost: byKey[ key ] || 0 } );
	}
	const max = days.reduce( ( m, d ) => Math.max( m, d.cost ), 0 );
	return (
		<div className="flex h-40 items-end gap-2 rounded-control border border-line bg-white p-4">
			{ days.map( ( d ) => {
				const pct = max > 0 ? Math.max( 3, Math.round( ( d.cost / max ) * 100 ) ) : 3;
				return (
					<div key={ d.key } className="flex h-full flex-1 flex-col items-center justify-end" title={ `${ d.key } — ${ formatCost( d.cost ) }` }>
						<div className="w-3/5 min-h-[3px] rounded-t bg-gradient-to-b from-gold to-gold-ember" style={ { height: pct + '%' } } />
						<div className="mt-1.5 whitespace-nowrap text-[10px] text-[#787c82]">{ d.key.slice( 5 ) }</div>
					</div>
				);
			} ) }
		</div>
	);
}
