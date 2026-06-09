/** Allow only safe URL schemes — guards markdown links/images against javascript: and data: URLs. */
export function safeUrl(url: string | null | undefined): string | null {
	const u = String(url || '').trim();
	return /^(https?:|mailto:|\/|#)/i.test(u) ? u : null;
}
