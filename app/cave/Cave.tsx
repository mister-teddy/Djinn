import { ToastHost, ResizeHandle } from '@shared/ui';
import { usePanelResize } from '@shared/usePanelResize';
import { AccountTile } from './AccountTile';
import { CapabilitiesTile } from './CapabilitiesTile';
import { SpendTile } from './SpendTile';

// Account + Capabilities stack in a resizable left column; Spend fills the right. The page never
// scrolls — each tile body scrolls on its own.
export function Cave() {
	const col = usePanelResize({
		storageKey: 'djinn_cave_split',
		min: 320,
		max: 760,
		initial: 440,
		axis: 'x',
	});
	const acc = usePanelResize({
		storageKey: 'djinn_cave_account_h',
		min: 140,
		max: 640,
		initial: 320,
		axis: 'y',
	});
	const resizing = col.resizing || acc.resizing;
	return (
		<div
			className={`flex h-[calc(100vh-32px)] items-stretch bg-white ${resizing ? 'cursor-col-resize select-none' : ''}`}
		>
			<ToastHost />
			<div
				className="flex min-h-0 min-w-0 flex-none flex-col overflow-hidden"
				style={{ width: col.size }}
			>
				<div
					className="flex min-h-0 min-w-0 flex-none overflow-hidden"
					style={{ height: acc.size }}
				>
					<AccountTile />
				</div>
				<ResizeHandle
					axis="y"
					onMouseDown={acc.startResize}
					title="Drag to resize"
				/>
				<div className="flex min-h-0 min-w-0 flex-1 overflow-hidden">
					<CapabilitiesTile />
				</div>
			</div>
			<ResizeHandle
				axis="x"
				onMouseDown={col.startResize}
				title="Drag to resize"
			/>
			<div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
				<SpendTile />
			</div>
		</div>
	);
}
