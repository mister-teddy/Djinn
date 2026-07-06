import apiFetch from '@wordpress/api-fetch';
import { createClient } from '@gql';

export interface ProviderInfo {
	value: string;
	label: string;
	needsKey?: boolean;
	description?: string;
	keyHint?: string;
}

/** The shape injected via wp_localize_script as `Djinn` (Lamp) or `DjinnCave` (Cave). */
export interface DjinnConfig {
	restUrl: string;
	gqlUrl: string;
	nonce: string;
	usesProxy: boolean;
	configured: boolean;
	privacyUrl?: string;
	provider?: string;
	providerLabel?: string;
	chatModel?: string;
	// Lamp
	settingsUrl?: string;
	siteName?: string;
	// Cave
	edition?: string;
	isPro?: boolean;
	proUrl?: string;
	polarEnabled?: boolean;
	providers?: ProviderInfo[];
}

declare global {
	interface Window {
		Djinn?: DjinnConfig;
		DjinnCave?: DjinnConfig;
	}
}

export const config: DjinnConfig = (window.DjinnCave ??
	window.Djinn) as DjinnConfig;

// apiFetch's nonce middleware sends X-WP-Nonce and refreshes it from the response header, so a
// long-lived tab keeps working past the original nonce's lifetime.
apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));

/** Typed GraphQL client for the admin control plane (settings, account, usage, chat CRUD). */
export const gql = createClient({
	fetcher: (operation) =>
		apiFetch({
			url: config.gqlUrl,
			method: 'POST',
			data: operation,
		}) as Promise<any>,
});

// ---- REST helpers — the streaming turn and binary I/O that don't fit GraphQL ------------------

export interface Attachment {
	filename: string;
	token: string;
	size: number;
	mime?: string | null;
}

export interface UploadResult {
	token: string;
	filename: string;
	mime: string;
	size: number;
}

export async function uploadFile(file: File): Promise<UploadResult> {
	const form = new FormData();
	form.append('file', file);
	const res = await fetch(config.restUrl + '/upload', {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': config.nonce },
		body: form,
	});
	const json = await res.json();
	if (!res.ok) {
		throw new Error(json?.message || 'Upload failed.');
	}
	return json as UploadResult;
}

/** A short-lived, nonce-bearing URL the browser can navigate to for a generated download. */
export function downloadUrl(token: string): string {
	return `${config.restUrl}/download?token=${encodeURIComponent(token)}&_wpnonce=${encodeURIComponent(config.nonce)}`;
}

/** Inline preview URL for uploaded images. Falls back to the same gated private-file route. */
export function previewUrl(token: string): string {
	return `${downloadUrl(token)}&inline=1`;
}

/** Non-streaming wish (fallback when SSE is unavailable); returns the turn's final result. */
export function wish(
	body: WishBody,
): Promise<{ status?: string; message?: string; chat_id?: number }> {
	return apiFetch({
		url: `${config.restUrl}/wish`,
		method: 'POST',
		data: body,
	}) as Promise<{ status?: string; message?: string; chat_id?: number }>;
}

/** Confirm or refuse a pending mutation; resumes the agent turn and returns its final result. */
export function grant(
	chatId: number,
	pendingId: number,
	confirmed: boolean,
): Promise<{ status?: string; message?: string }> {
	return apiFetch({
		url: `${config.restUrl}/grant`,
		method: 'POST',
		data: { chat_id: chatId, pending_id: pendingId, confirmed },
	});
}

export interface WishBody {
	message: string;
	chat_id?: number;
	attachments?: Attachment[];
}

/**
 * Stream one wish over Server-Sent Events, dispatching each event to onEvent. Events are:
 * 'open' {chat_id}, 'step', 'delta', and a terminal 'done' | 'pending' | 'error'.
 */
export async function streamWish(
	body: WishBody,
	onEvent: (event: string, data: unknown) => void,
	signal?: AbortSignal,
): Promise<void> {
	const res = await fetch(`${config.restUrl}/wish/stream`, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
		body: JSON.stringify(body),
		signal,
	});
	if (!res.ok || !res.body) {
		throw new Error('The lamp went quiet.');
	}
	const reader = res.body.getReader();
	const decoder = new TextDecoder();
	let buffer = '';
	for (;;) {
		const { value, done } = await reader.read();
		if (done) {
			break;
		}
		buffer += decoder.decode(value, { stream: true });
		let split: number;
		while ((split = buffer.indexOf('\n\n')) !== -1) {
			const frame = buffer.slice(0, split);
			buffer = buffer.slice(split + 2);
			let event = 'message';
			let data = '';
			for (const line of frame.split('\n')) {
				if (line.startsWith('event:')) {
					event = line.slice(6).trim();
				} else if (line.startsWith('data:')) {
					data += line.slice(5).trim();
				}
			}
			if (data) {
				let parsed: unknown = data;
				try {
					parsed = JSON.parse(data);
				} catch {
					/* keep the raw string */
				}
				onEvent(event, parsed);
			}
		}
	}
}
