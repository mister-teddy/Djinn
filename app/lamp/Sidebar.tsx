import { Button, Sparkle } from '@shared/ui';
import { config } from '@shared/api';
import { formatCost } from '@shared/format';
import type { ChatSummary, ChatUsage } from './chat';

// created_at is a UTC MySQL datetime ("YYYY-MM-DD HH:MM:SS"); render it in the admin's locale.
function formatDate(s: string | null): string {
	if (!s) {
		return '';
	}
	const d = new Date(s.replace(' ', 'T') + 'Z');
	return isNaN(d.getTime())
		? ''
		: d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export function Sidebar({
	chats,
	activeId,
	busy,
	onNew,
	onOpen,
	onDelete,
	width,
	collapsed,
}: {
	chats: ChatSummary[];
	activeId: number;
	busy: boolean;
	onNew: () => void;
	onOpen: (id: number) => void;
	onDelete: (id: number) => void;
	width: number;
	collapsed: boolean;
}) {
	return (
		<aside
			className={`flex min-h-0 flex-none flex-col gap-3 overflow-hidden bg-gradient-to-b from-midnight to-violet py-4 transition-[width,padding,opacity] duration-200 ${collapsed ? 'pointer-events-none opacity-0' : 'px-3.5 opacity-100'}`}
			style={{
				width,
				paddingLeft: collapsed ? 0 : undefined,
				paddingRight: collapsed ? 0 : undefined,
			}}
			aria-hidden={collapsed}
		>
			<Button
				variant="primary"
				className="w-full"
				disabled={busy}
				onClick={onNew}
			>
				<Sparkle />
				New wish
			</Button>
			<div className="px-1 pt-1 text-[11px] uppercase tracking-wider text-ivory-muted">
				Past wishes
			</div>
			<div className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto">
				{!chats.length ? (
					<p className="p-1 text-[13px] italic text-ivory-muted">
						Nothing yet.
					</p>
				) : (
					chats.map((c) => (
						<div
							key={c.id}
							className={`group flex items-stretch rounded-[9px] transition-colors ${c.id === activeId ? 'bg-gold/[0.16]' : 'hover:bg-white/[0.08]'}`}
						>
							<button
								type="button"
								className="flex min-w-0 flex-1 cursor-pointer flex-col gap-0.5 rounded-l-[9px] bg-transparent px-2.5 py-2 text-left text-ivory transition-colors focus:bg-white/[0.10] focus:outline-none disabled:cursor-default disabled:opacity-60"
								disabled={busy}
								onClick={() => onOpen(c.id)}
								title={c.title || undefined}
							>
								<span className="overflow-hidden text-ellipsis whitespace-nowrap text-[13px] leading-tight">
									{c.title || 'Untitled wish'}
								</span>
								<span className="text-[11px] text-ivory-muted">
									{formatDate(c.createdAt)}
								</span>
							</button>
							<button
								type="button"
								className="w-7 flex-none rounded-r-[9px] bg-transparent text-lg leading-none text-ivory-muted opacity-0 transition group-hover:opacity-70 hover:!opacity-100 hover:bg-[rgba(248,113,113,0.15)] hover:text-[#fca5a5] disabled:cursor-default"
								disabled={busy}
								title="Delete this wish"
								aria-label="Delete this wish"
								onClick={() => onDelete(c.id)}
							>
								×
							</button>
						</div>
					))
				)}
			</div>
		</aside>
	);
}

// Running token + cost total for the open conversation. Updates after each wish/grant.
export function Meter({ usage }: { usage: ChatUsage | null }) {
	if (!usage || !usage.calls) {
		return null;
	}
	const showCost = !config.usesProxy;
	return (
		<div
			className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-[rgba(15,10,30,0.55)] px-2.5 py-[5px] text-xs text-ivory [font-variant-numeric:tabular-nums]"
			title={`${usage.prompt.toLocaleString()} in · ${usage.completion.toLocaleString()} out · ${usage.calls} calls`}
		>
			<span className="text-gold">
				<Sparkle />
			</span>
			<span>{usage.tokens.toLocaleString()} tokens</span>
			{showCost && <span className="text-ivory-muted">·</span>}
			{showCost && (
				<span className="font-semibold text-gold">
					{formatCost(usage.cost)}
				</span>
			)}
		</div>
	);
}
