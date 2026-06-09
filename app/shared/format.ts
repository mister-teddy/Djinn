/** Adaptive currency: keep tiny amounts legible instead of rounding to $0.00. */
export function formatCost(usd: number | string | null | undefined): string {
	const n = Number(usd) || 0;
	if (n === 0) {
		return '$0.00';
	}
	if (n < 0.01) {
		return '$' + n.toFixed(6).replace(/0+$/, '').replace(/\.$/, '');
	}
	return '$' + n.toFixed(n < 1 ? 4 : 2);
}

export function formatBytes(bytes: number | string | null | undefined): string {
	let n = Number(bytes) || 0;
	if (n < 1024) {
		return n + ' B';
	}
	const units = ['KB', 'MB', 'GB'];
	let i = -1;
	do {
		n /= 1024;
		i++;
	} while (n >= 1024 && i < units.length - 1);
	return n.toFixed(1) + ' ' + units[i];
}

export const cap = (s: string): string =>
	s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
