import { useState, useRef, useEffect } from '@wordpress/element';
import {
	config,
	streamWish,
	wish as wishApi,
	grant as grantApi,
	uploadFile as uploadFileApi,
	type Attachment,
} from '@shared/api';
import {
	Button,
	Spinner,
	Notice,
	Lamp,
	Sparkle,
	ResizeHandle,
	ToastHost,
} from '@shared/ui';
import { usePanelResize } from '@shared/usePanelResize';
import { formatBytes } from '@shared/format';
import { Message } from './cards';
import { Sidebar, Meter } from './Sidebar';
import {
	loadChats,
	loadTranscript,
	deleteChat as deleteChatApi,
	type ChatSummary,
	type ChatUsage,
	type TranscriptMessage,
} from './chat';

const ACTIVE_CHAT_KEY = 'djinn_active_chat:' + config.restUrl;
const readActiveChat = (): string => {
	try {
		return localStorage.getItem(ACTIVE_CHAT_KEY) || '';
	} catch {
		return '';
	}
};
const writeActiveChat = (id: number): void => {
	try {
		localStorage.setItem(ACTIVE_CHAT_KEY, String(id));
	} catch {
		/* ignore */
	}
};
const clearActiveChat = (): void => {
	try {
		localStorage.removeItem(ACTIVE_CHAT_KEY);
	} catch {
		/* ignore */
	}
};

const isNarrow = () => window.matchMedia('(max-width: 782px)').matches;

function autosize(node: HTMLTextAreaElement | null): void {
	if (!node) {
		return;
	}
	node.style.height = 'auto';
	node.style.height = Math.min(node.scrollHeight, 220) + 'px';
}

const INPUT_CLASS =
	'flex-1 resize-none overflow-y-auto rounded-[10px] bg-black/25 px-3.5 py-3 font-serif text-[17px] leading-normal text-ivory transition placeholder:text-ivory-muted/70 focus:bg-black/35 focus:shadow-[0_0_0_2px_rgba(251,191,36,0.3)] focus:outline-none [max-height:220px] [scrollbar-width:none]';
const ICON_BTN =
	'h-11 w-11 justify-center bg-white/[0.06] text-lg text-ivory hover:bg-white/[0.12]';
const HEADER_BG =
	'bg-[radial-gradient(circle_at_8%_50%,rgba(251,191,36,0.18),transparent_38%),linear-gradient(135deg,var(--djinn-midnight),var(--djinn-violet))]';
const THREAD_BG =
	'bg-[radial-gradient(circle_at_80%_8%,rgba(251,191,36,0.08),transparent_42%),linear-gradient(180deg,var(--djinn-midnight),var(--djinn-midnight-2))]';

export function App() {
	const [messages, setMessages] = useState<TranscriptMessage[]>([]);
	const [input, setInput] = useState('');
	const [busy, setBusy] = useState(false);
	const [chatId, setChatId] = useState(0);
	const [chats, setChats] = useState<ChatSummary[]>([]);
	const [usage, setUsage] = useState<ChatUsage | null>(null);
	const [error, setError] = useState('');
	const [attachment, setAttachment] = useState<Attachment | null>(null);
	const [step, setStep] = useState('');
	const [dragOver, setDragOver] = useState(false);
	const [collapsed, setCollapsed] = useState(() => {
		try {
			const s = localStorage.getItem('djinn_sidebar_collapsed');
			return s === null ? isNarrow() : s === '1';
		} catch {
			return false;
		}
	});
	const sidebar = usePanelResize({
		storageKey: 'djinn_sidebar_width',
		min: 150,
		max: 400,
		initial: 200,
		axis: 'x',
	});
	const scroller = useRef<HTMLDivElement>(null);
	const fileInput = useRef<HTMLInputElement>(null);
	const inputRef = useRef<HTMLTextAreaElement>(null);
	const loadSeq = useRef(0);
	const dragDepth = useRef(0);

	useEffect(() => {
		const node = scroller.current;
		if (!node) {
			return;
		}
		const id = requestAnimationFrame(() => {
			node.scrollTop = node.scrollHeight;
		});
		return () => cancelAnimationFrame(id);
	}, [messages, busy]);

	useEffect(() => {
		refreshChats();
		const saved = parseInt(readActiveChat(), 10);
		if (saved) {
			setChatId(saved);
			refreshTranscript(saved);
		}
	}, []);

	useEffect(() => {
		if (chatId) {
			writeActiveChat(chatId);
		}
	}, [chatId]);

	useEffect(() => {
		autosize(inputRef.current);
	}, [input]);

	async function refreshChats() {
		try {
			setChats(await loadChats());
		} catch {
			/* a failed history fetch shouldn't break the lamp */
		}
	}

	// The server holds the canonical conversation; reload it after each turn for one render path.
	async function refreshTranscript(id: number) {
		const seq = ++loadSeq.current;
		const r = await loadTranscript(id);
		if (seq !== loadSeq.current) {
			return;
		}
		setMessages(r.messages);
		setChatId(r.chatId || id);
		setUsage(r.usage || null);
	}

	async function doUpload(file: File) {
		try {
			const r = await uploadFileApi(file);
			setAttachment({
				token: r.token,
				filename: r.filename,
				size: r.size,
			});
		} catch (e) {
			setError((e as Error)?.message || 'Upload failed.');
		}
	}

	function dragHasFile(e: React.DragEvent): boolean {
		return !!(
			e.dataTransfer &&
			Array.prototype.indexOf.call(
				e.dataTransfer.types || [],
				'Files',
			) !== -1
		);
	}
	const onDragEnter = (e: React.DragEvent) => {
		if (!dragHasFile(e)) return;
		e.preventDefault();
		dragDepth.current++;
		setDragOver(true);
	};
	const onDragOver = (e: React.DragEvent) => {
		if (dragHasFile(e)) e.preventDefault();
	};
	const onDragLeave = (e: React.DragEvent) => {
		if (!dragHasFile(e)) return;
		dragDepth.current = Math.max(0, dragDepth.current - 1);
		if (dragDepth.current === 0) setDragOver(false);
	};
	const onDrop = (e: React.DragEvent) => {
		if (!dragHasFile(e)) return;
		e.preventDefault();
		dragDepth.current = 0;
		setDragOver(false);
		const f = e.dataTransfer.files?.[0];
		if (f && !busy) doUpload(f);
	};

	function setStreamingContent(content: string) {
		setMessages((m) => {
			const c = m.slice();
			for (let i = c.length - 1; i >= 0; i--) {
				if (c[i].streaming) {
					c[i] = { ...c[i], content };
					break;
				}
			}
			return c;
		});
	}

	async function sendBlocking(
		startChatId: number,
		text: string,
		attachments: Attachment[],
	) {
		const r = await wishApi({
			chat_id: startChatId,
			message: text,
			attachments,
		});
		setError(r.status === 'error' ? r.message || 'The lamp dimmed.' : '');
		const id = r.chat_id || startChatId;
		if (id) {
			await refreshTranscript(id);
		}
	}

	async function send() {
		const text = input.trim();
		if ((!text && !attachment) || busy) {
			return;
		}
		const attachments: Attachment[] = attachment ? [attachment] : [];
		const startChatId = chatId;
		loadSeq.current++;
		setInput('');
		setAttachment(null);
		setMessages((m) => [
			...m,
			{
				role: 'user',
				content: text,
				attachments: attachment ? attachments : undefined,
			},
		]);
		setBusy(true);
		setStep('');

		try {
			let acc = '';
			let started = false;
			let resolvedId = startChatId;
			let terminal: {
				event: string;
				data: { chat_id?: number; message?: string };
			} | null = null;
			await streamWish(
				{ chat_id: startChatId, message: text, attachments },
				(event, raw) => {
					const data = (raw || {}) as {
						chat_id?: number;
						label?: string;
						token?: string;
						message?: string;
					};
					if (event === 'open') {
						resolvedId = data.chat_id || resolvedId;
						if (data.chat_id) setChatId(data.chat_id);
					} else if (event === 'step') {
						setStep(data.label || '');
					} else if (event === 'delta') {
						acc += data.token || '';
						if (!started) {
							started = true;
							setMessages((m) => [
								...m,
								{
									role: 'assistant',
									content: acc,
									streaming: true,
								},
							]);
						} else {
							setStreamingContent(acc);
						}
					} else {
						terminal = { event, data };
						resolvedId = data.chat_id || resolvedId;
					}
				},
			);
			const term = terminal as {
				event: string;
				data: { message?: string };
			} | null;
			setError(
				term && term.event === 'error'
					? term.data.message || 'The lamp dimmed.'
					: '',
			);
			if (resolvedId) {
				await refreshTranscript(resolvedId);
			}
		} catch {
			try {
				await sendBlocking(startChatId, text, attachments);
			} catch (e2) {
				setError(String(e2));
			}
		} finally {
			if (startChatId === 0) {
				refreshChats();
			}
			setBusy(false);
			setStep('');
		}
	}

	function newChat() {
		if (busy) return;
		setMessages([]);
		setChatId(0);
		setUsage(null);
		setError('');
		clearActiveChat();
	}

	async function openChat(id: number) {
		if (busy || id === chatId) return;
		setBusy(true);
		setError('');
		if (isNarrow()) setCollapsed(true);
		try {
			await refreshTranscript(id);
		} catch (e) {
			setError(String(e));
		} finally {
			setBusy(false);
		}
	}

	function toggleSidebar() {
		setCollapsed((v) => {
			const next = !v;
			try {
				localStorage.setItem(
					'djinn_sidebar_collapsed',
					next ? '1' : '0',
				);
			} catch {
				/* ignore */
			}
			return next;
		});
	}

	function startResize(e: React.MouseEvent) {
		if (collapsed || isNarrow()) return;
		sidebar.startResize(e);
	}

	async function deleteChat(id: number) {
		if (
			busy ||
			!window.confirm(
				'Delete this wish and its history? This cannot be undone.',
			)
		) {
			return;
		}
		try {
			await deleteChatApi(id);
		} catch {
			setError('Could not delete that wish.');
			return;
		}
		if (id === chatId) newChat();
		refreshChats();
	}

	async function resolvePending(
		pending: TranscriptMessage,
		confirmed: boolean,
	) {
		setBusy(true);
		try {
			const r = await grantApi(chatId, pending.pendingId || 0, confirmed);
			setError(
				r.status === 'error' ? r.message || 'The lamp dimmed.' : '',
			);
			await refreshTranscript(chatId);
		} catch (e) {
			setError(String(e));
		} finally {
			setBusy(false);
		}
	}

	if (!config.configured) {
		return (
			<div className="flex h-full items-center justify-center bg-[radial-gradient(circle_at_50%_30%,rgba(251,191,36,0.16),transparent_50%),linear-gradient(135deg,var(--djinn-midnight),var(--djinn-violet))]">
				<div className="max-w-sm p-8 text-center text-ivory">
					<div className="flex justify-center">
						<Lamp size={104} glow />
					</div>
					<h1 className="mb-2 mt-6 font-serif text-[30px] leading-tight text-ivory">
						The lamp is empty.
					</h1>
					<p className="mx-auto mb-6 max-w-xs leading-relaxed text-ivory-muted">
						Place an offering — an API key — to summon the Djinn.
					</p>
					<a
						className="inline-flex items-center rounded-control border-0 bg-gradient-to-b from-gold to-gold-deep px-5 py-2.5 font-sans font-semibold text-midnight no-underline shadow-glow transition hover:-translate-y-px hover:text-midnight hover:brightness-110 hover:no-underline"
						href={config.settingsUrl}
					>
						Open the Cave of Wonders →
					</a>
				</div>
			</div>
		);
	}

	const empty = messages.length === 0;

	return (
		<div
			className={`relative flex h-full items-stretch ${sidebar.resizing ? 'cursor-col-resize select-none' : ''}`}
			onDragEnter={onDragEnter}
			onDragOver={onDragOver}
			onDragLeave={onDragLeave}
			onDrop={onDrop}
		>
			<ToastHost />
			{dragOver && (
				<div className="pointer-events-none fixed inset-0 z-[100000] flex items-center justify-center bg-[rgba(15,10,30,0.66)] backdrop-blur-sm">
					<div className="flex items-center gap-3 rounded-djinn border-2 border-dashed border-gold/70 bg-gradient-to-br from-midnight-2 to-violet px-8 py-5 text-[1.05rem] font-semibold text-ivory shadow-[0_18px_50px_-12px_rgba(0,0,0,0.6),0_0_44px_-10px_rgba(251,191,36,0.45)]">
						<span className="flex-none text-gold">
							<Lamp size={26} glow />
						</span>
						Drop a file to attach it to your wish
					</div>
				</div>
			)}
			<Sidebar
				chats={chats}
				activeId={chatId}
				busy={busy}
				onNew={newChat}
				onOpen={openChat}
				onDelete={deleteChat}
				width={collapsed ? 0 : sidebar.size}
			/>
			<ResizeHandle axis="x" onMouseDown={startResize}>
				<button
					type="button"
					className="absolute left-1/2 top-1/2 z-[70] flex h-10 w-[22px] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-[7px] bg-gradient-to-br from-midnight-2 to-violet text-[15px] leading-none text-gold shadow-[0_2px_8px_rgba(0,0,0,0.35)] hover:from-violet hover:to-gold-ember hover:text-white"
					onMouseDown={(e) => e.stopPropagation()}
					onClick={toggleSidebar}
					title={collapsed ? 'Show past wishes' : 'Hide past wishes'}
					aria-label="Toggle past wishes"
				>
					{collapsed ? '›' : '‹'}
				</button>
			</ResizeHandle>
			<div className="flex min-h-0 min-w-0 flex-1 flex-col">
				<div
					className={`flex flex-none items-center justify-between gap-4 px-[22px] py-4 ${HEADER_BG}`}
				>
					<div className="flex items-center gap-3.5">
						<span className="flex-none text-gold">
							<Lamp size={32} glow={!empty || busy} />
						</span>
						<div>
							<h1 className="text-[22px] font-semibold tracking-wide text-ivory">
								Djinn
							</h1>
							<p className="mt-0.5 max-w-[520px] text-[11.5px] leading-snug text-ivory-muted">
								{config.usesProxy
									? 'Wishes and the relevant site content travel through Djinn’s gateway to Google Gemini. '
									: 'Wishes and the relevant site content are sent to your AI provider. '}
								{config.usesProxy && (
									<a
										className="text-gold hover:underline"
										href={config.privacyUrl}
										target="_blank"
										rel="noopener"
									>
										Privacy
									</a>
								)}
							</p>
						</div>
					</div>
					<div className="flex items-center gap-3">
						<Meter usage={usage} />
					</div>
				</div>
				{error && <Notice status="error">{error}</Notice>}
				<div
					ref={scroller}
					className={`min-h-0 flex-1 overflow-y-auto p-[22px] [overscroll-behavior:contain] shadow-[inset_0_0_60px_rgba(0,0,0,0.35)] ${THREAD_BG} ${empty ? 'flex items-center justify-center' : ''}`}
				>
					{empty ? (
						<div className="text-center text-ivory-muted">
							<div className="flex justify-center">
								<Lamp size={84} glow />
							</div>
							<p className="mb-1 mt-4 text-[22px] font-medium tracking-wide text-ivory">
								Rub the lamp.
							</p>
							<p className="mx-auto max-w-[480px] text-[13px] text-ivory-muted">
								Try:{' '}
								<em className="not-italic text-gold">
									&quot;Create a draft page titled About&quot;
								</em>{' '}
								·{' '}
								<em className="not-italic text-gold">
									&quot;List my 5 newest posts&quot;
								</em>{' '}
								·{' '}
								<em className="not-italic text-gold">
									&quot;Set the tagline to Built with
									Djinn&quot;
								</em>
							</p>
						</div>
					) : (
						messages.map((msg, i) => (
							<Message
								key={i}
								msg={msg}
								busy={busy}
								onConfirm={() => resolvePending(msg, true)}
								onCancel={() => resolvePending(msg, false)}
							/>
						))
					)}
					{busy && !empty && (
						<div className="mt-3 flex items-center gap-2.5 italic text-ivory-muted">
							<Spinner />
							<span>{step || 'The Djinn ponders…'}</span>
						</div>
					)}
				</div>
				<div
					className={`flex flex-none flex-col items-stretch gap-2 px-4 py-3.5 bg-gradient-to-br from-midnight to-violet`}
				>
					{attachment && (
						<div className="inline-flex max-w-full items-center gap-2 self-start rounded-full bg-gold/[0.16] px-3 py-1 text-xs text-ivory">
							📎 {attachment.filename}
							{attachment.size
								? ` (${formatBytes(attachment.size)})`
								: ''}
							<button
								type="button"
								className="inline-flex h-[18px] w-[18px] flex-none items-center justify-center rounded-full bg-black/25 text-[13px] leading-none text-ivory transition hover:bg-[rgba(248,113,113,0.5)] hover:text-white"
								onClick={() => setAttachment(null)}
								title="Remove"
								aria-label="Remove attachment"
							>
								×
							</button>
						</div>
					)}
					<div className="flex items-start gap-2.5">
						<input
							type="file"
							ref={fileInput}
							className="hidden"
							onChange={(e) => {
								const f = e.target.files?.[0];
								if (f) doUpload(f);
								e.target.value = '';
							}}
						/>
						<Button
							variant="tertiary"
							className={ICON_BTN}
							disabled={busy}
							title="Attach a file"
							onClick={() => fileInput.current?.click()}
						>
							📎
						</Button>
						<textarea
							className={INPUT_CLASS}
							value={input}
							ref={inputRef}
							placeholder="Whisper your wish…  (Enter to send · Shift+Enter for newline)"
							rows={1}
							disabled={busy}
							onChange={(e) => setInput(e.target.value)}
							onKeyDown={(e) => {
								if (e.key === 'Enter' && !e.shiftKey) {
									e.preventDefault();
									send();
								}
							}}
						/>
						<Button
							key="send"
							variant="primary"
							className="h-11 px-[18px]"
							disabled={busy || (!input.trim() && !attachment)}
							onClick={send}
						>
							<Sparkle />
							Make wish
						</Button>
					</div>
				</div>
			</div>
		</div>
	);
}
