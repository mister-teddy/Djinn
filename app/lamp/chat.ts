import { gql } from '@shared/api';

export interface ChatSummary {
	id: number;
	title: string | null;
	createdAt: string | null;
}

export interface ChatUsage {
	prompt: number;
	completion: number;
	tokens: number;
	cost: number;
	calls: number;
}

export interface MsgAttachment {
	filename: string | null;
	token: string | null;
	size: number | null;
	mime?: string | null;
}

// A transcript entry — heterogeneous by `role` (user | assistant | action | pending).
export interface TranscriptMessage {
	id?: number | null;
	role: string;
	content?: string | null;
	attachments?: MsgAttachment[] | null;
	kind?: string | null;
	status?: string | null;
	operation?: string | null;
	variables?: Record<string, unknown> | null;
	summary?: string | null;
	message?: string | null;
	result?: unknown;
	pendingId?: number | null;
	// client-only: marks the live streaming bubble before the canonical transcript reloads
	streaming?: boolean;
}

export interface ChatDetail {
	chatId: number;
	messages: TranscriptMessage[];
	usage: ChatUsage;
}

const MESSAGE_FIELDS = {
	id: true,
	role: true,
	content: true,
	attachments: { filename: true, token: true, size: true, mime: true },
	kind: true,
	status: true,
	operation: true,
	variables: true,
	summary: true,
	message: true,
	result: true,
	pendingId: true,
} as const;

export async function loadChats(): Promise<ChatSummary[]> {
	const d = await gql.query({
		chats: { id: true, title: true, createdAt: true },
	});
	return d.chats as ChatSummary[];
}

export async function loadTranscript(id: number): Promise<ChatDetail> {
	const d = await gql.query({
		chat: {
			__args: { id },
			chatId: true,
			messages: MESSAGE_FIELDS,
			usage: {
				prompt: true,
				completion: true,
				tokens: true,
				cost: true,
				calls: true,
			},
		},
	});
	return d.chat as unknown as ChatDetail;
}

export async function deleteChat(id: number): Promise<void> {
	await gql.mutation({ deleteChat: { __args: { id } } });
}

export async function deleteMessage(
	chatId: number,
	messageId: number,
): Promise<boolean> {
	const d = await gql.mutation({
		deleteMessage: { __args: { chatId, messageId } },
	});
	return !!d.deleteMessage;
}
