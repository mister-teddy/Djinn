import { useState, useEffect } from '@wordpress/element';
import { config } from '@shared/api';
import { Tile, Spinner, Popover } from '@shared/ui';
import {
	loadOperations,
	type OperationsData,
	type OperationInfo,
} from './data';

// Capability areas the free edition can't write — surfaced as the Pro upsell. The free schema never
// reports these (they aren't registered), so this list is curated to mirror the scope gate.
const PRO_CAPABILITIES = [
	'Users & roles',
	'Site settings',
	'Navigation menus',
	'Appearance & the Site Editor',
	'Widgets',
	'Plugins, themes & core',
	'WooCommerce',
	'The REST escape hatch',
];

// A premium, gold-accented teaser shown to Free users at the top of Capabilities: what Pro unlocks,
// plus the purchase link. Matches the plugin's lamp-and-gold language (✦, Cardo serif, gold chips).
function ProUpsell() {
	const url =
		config.proUrl ||
		'https://buy.polar.sh/polar_cl_DGwSeP4nDmqeEXZLw4vC6RFkEBP7frjlGPU3u2768kC';
	return (
		<div className="mb-5 rounded-djinn border border-gold/40 bg-gradient-to-br from-[#fffaf2] to-[#fbeecb] p-4 shadow-[0_2px_14px_-6px_rgba(251,191,36,0.6)]">
			<div className="flex items-center gap-2">
				<span
					className="text-[15px] leading-none text-gold"
					aria-hidden
				>
					✦
				</span>
				<h3 className="m-0 font-serif text-[18px] leading-none text-[#1d2327]">
					Djinn Pro
				</h3>
			</div>
			<p className="mb-0 mt-1.5 text-[13px] text-[#7a5c12]">
				Free grants wishes over your content. Pro unlocks the rest of
				the lamp:
			</p>
			<ul className="m-0 mt-2.5 flex list-none flex-wrap gap-1.5 p-0">
				{PRO_CAPABILITIES.map((c) => (
					<li
						key={c}
						className="rounded-md bg-[rgba(251,191,36,0.2)] px-2 py-0.5 text-xs font-medium text-[#7a5c12]"
					>
						{c}
					</li>
				))}
			</ul>
			<a
				className="mt-3.5 inline-flex items-center rounded-control border-0 bg-gradient-to-b from-gold to-gold-deep px-4 py-2 font-sans text-[13px] font-semibold text-midnight no-underline shadow-glow transition hover:-translate-y-px hover:text-midnight hover:brightness-110 hover:no-underline"
				href={url}
				target="_blank"
				rel="noopener"
			>
				Get Djinn Pro →
			</a>
		</div>
	);
}

function ProLicenseNotice() {
	return (
		<div className="mb-5 rounded-djinn border border-[#dcdcde] bg-[#f6f7f7] p-4">
			<div className="flex items-center gap-2">
				<span
					className="text-[15px] leading-none text-gold"
					aria-hidden
				>
					✦
				</span>
				<h3 className="m-0 font-serif text-[18px] leading-none text-[#1d2327]">
					Djinn Pro installed
				</h3>
			</div>
			<p className="mb-0 mt-1.5 text-[13px] text-[#646970]">
				Activate your license in the Account tile to unlock the full
				schema scope on this site.
			</p>
		</div>
	);
}

export function CapabilitiesTile() {
	const [ops, setOps] = useState<OperationsData | null>(null);
	useEffect(() => {
		loadOperations()
			.then(setOps)
			.catch(() => {});
	}, []);

	return (
		<Tile title="Capabilities">
			{!config.isPro &&
				(config.edition === 'pro' ? (
					<ProLicenseNotice />
				) : (
					<ProUpsell />
				))}
			<p className="text-[#787c82]">
				Everything the Djinn can do here — the operations it can run,
				grouped by area.
			</p>
			{ops ? <OperationsList ops={ops} /> : <Spinner />}
		</Tile>
	);
}

function opDetails(o: OperationInfo) {
	return (
		<div>
			{o.description && <p className="mb-2">{o.description}</p>}
			{o.args.length ? (
				<dl className="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
					{o.args.map((a, i) => [
						<dt key={'k' + i} className="text-ivory-muted">
							{a.name}
							{a.required && (
								<span className="text-gold" title="required">
									{' '}
									*
								</span>
							)}
						</dt>,
						<dd
							key={'v' + i}
							className="m-0 text-right [font-variant-numeric:tabular-nums]"
						>
							{a.type || '—'}
						</dd>,
					])}
				</dl>
			) : (
				<p className="m-0 text-ivory-muted">No parameters.</p>
			)}
			<div className="mt-2 border-t border-gold/20 pt-2">
				Returns <code className="text-ivory">{o.returns || '—'}</code>
			</div>
		</div>
	);
}

function OperationsList({ ops }: { ops: OperationsData }) {
	const byDomain: Record<string, OperationInfo[]> = {};
	ops.operations.forEach((o) => {
		(byDomain[o.domain] = byDomain[o.domain] || []).push(o);
	});
	const domains = Object.keys(byDomain).sort();
	const [open, setOpen] = useState<Record<string, boolean>>({});
	const toggle = (d: string) => setOpen((o) => ({ ...o, [d]: !o[d] }));

	return (
		<div className="mt-2">
			{domains.map((d) => {
				const isOpen = !!open[d];
				const list = byDomain[d]
					.slice()
					.sort((a, b) => a.name.localeCompare(b.name));
				return (
					<div key={d}>
						<button
							type="button"
							className="flex w-full items-center gap-2 rounded px-1.5 py-2 text-left text-[#1d2327] transition hover:bg-[#f6f7f7]"
							aria-expanded={isOpen}
							onClick={() => toggle(d)}
						>
							<span
								className={`text-[9px] leading-none text-[#787c82] transition-transform ${isOpen ? 'rotate-90' : ''}`}
								aria-hidden
							>
								▶
							</span>
							<h3 className="m-0 text-[13px] font-semibold uppercase tracking-wide text-[#50575e]">
								{d}
							</h3>
							<span className="ml-auto text-[11px] font-normal text-[#787c82]">
								{list.length}
							</span>
						</button>
						{isOpen && (
							<div className="mb-2 pl-1.5">
								{list.map((o) => (
									<Popover
										key={o.kind + ':' + o.name}
										placement="top"
										content={opDetails(o)}
										className="w-full items-center gap-2.5 rounded px-2 py-1.5 transition hover:bg-[#f6f7f7]"
									>
										<span
											className={`flex h-[18px] w-[18px] flex-none items-center justify-center rounded-[5px] text-[11px] font-bold leading-none text-white ${o.kind === 'query' ? 'bg-[#10b981]' : 'bg-[#8b5cf6]'}`}
											title={
												o.kind === 'query'
													? 'Query (read)'
													: 'Mutation (write)'
											}
										>
											{o.kind === 'query' ? 'Q' : 'M'}
										</span>
										<code className="flex-none font-semibold text-[13px] text-[#1d2327]">
											{o.name}
										</code>
										{o.description && (
											<span className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-xs text-[#787c82]">
												{o.description}
											</span>
										)}
									</Popover>
								))}
							</div>
						)}
					</div>
				);
			})}
		</div>
	);
}
