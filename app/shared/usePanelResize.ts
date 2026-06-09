import { useState } from '@wordpress/element';

export interface PanelResize {
	size: number;
	setSize: (n: number) => void;
	resizing: boolean;
	startResize: (e: {
		preventDefault: () => void;
		clientX: number;
		clientY: number;
	}) => void;
}

interface Options {
	storageKey: string;
	min: number;
	max: number;
	initial: number;
	axis?: 'x' | 'y';
}

/** Drag-to-size hook, persisted to localStorage. The consumer renders the seam and wires startResize. */
export function usePanelResize({
	storageKey,
	min,
	max,
	initial,
	axis,
}: Options): PanelResize {
	const vertical = axis === 'y';
	const read = (): number => {
		try {
			const n = parseInt(localStorage.getItem(storageKey) || '', 10);
			return n >= min && n <= max ? n : initial;
		} catch {
			return initial;
		}
	};
	const [size, setSize] = useState<number>(read);
	const [resizing, setResizing] = useState(false);

	function startResize(e: {
		preventDefault: () => void;
		clientX: number;
		clientY: number;
	}): void {
		e.preventDefault();
		const startPos = vertical ? e.clientY : e.clientX;
		const startSize = size;
		let last = startSize;
		setResizing(true);
		const move = (ev: MouseEvent): void => {
			const pos = vertical ? ev.clientY : ev.clientX;
			last = Math.min(max, Math.max(min, startSize + (pos - startPos)));
			setSize(last);
		};
		const up = (): void => {
			document.removeEventListener('mousemove', move);
			document.removeEventListener('mouseup', up);
			setResizing(false);
			try {
				localStorage.setItem(storageKey, String(last));
			} catch {
				/* ignore */
			}
		};
		document.addEventListener('mousemove', move);
		document.addEventListener('mouseup', up);
	}

	return { size, setSize, resizing, startResize };
}
