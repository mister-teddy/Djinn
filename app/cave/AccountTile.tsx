import { useState, useEffect, useRef } from '@wordpress/element';
import { config, type ProviderInfo } from '@shared/api';
import {
	Tile,
	Spinner,
	Skeleton,
	Notice,
	Button,
	Field,
	Select,
	PasswordField,
	StatCard,
	Cards,
	toast,
	type OptionGroup,
} from '@shared/ui';
import {
	loadAccountSettings,
	loadModels,
	saveSettings,
	connect,
	billingCheckout,
	activateLicense,
	deactivateLicense,
	type SettingsData,
	type AccountData,
	type ModelsData,
} from './data';

interface PolarCheckoutInstance {
	addEventListener: (ev: string, fn: () => void) => void;
	close: () => void;
}
interface PolarCheckout {
	create: (
		url: string,
		opts?: { theme?: 'light' | 'dark' },
	) => Promise<PolarCheckoutInstance>;
}
declare global {
	interface Window {
		Polar?: { EmbedCheckout?: PolarCheckout };
	}
}

// Polar's embedded-checkout SDK exposes `window.Polar.EmbedCheckout` (the Cave preloads the script).
// Resolve it if it's ready, else load it on demand — and always settle (a timeout falls back to a
// redirect) so the button can never get stuck spinning.
const POLAR_EMBED_SRC =
	'https://cdn.jsdelivr.net/npm/@polar-sh/checkout@latest/dist/embed.global.js';
let polarEmbedPromise: Promise<PolarCheckout | null> | null = null;
function ensurePolarEmbed(): Promise<PolarCheckout | null> {
	if (window.Polar?.EmbedCheckout) {
		return Promise.resolve(window.Polar.EmbedCheckout);
	}
	if (!polarEmbedPromise) {
		polarEmbedPromise = new Promise((resolve) => {
			let settled = false;
			const settle = (v: PolarCheckout | null) => {
				if (!settled) {
					settled = true;
					resolve(v);
				}
			};
			const timer = setTimeout(
				() => settle(window.Polar?.EmbedCheckout ?? null),
				4000,
			);
			const done = () => {
				clearTimeout(timer);
				settle(window.Polar?.EmbedCheckout ?? null);
			};
			const existing = document.querySelector<HTMLScriptElement>(
				`script[src="${POLAR_EMBED_SRC}"]`,
			);
			if (existing) {
				// Preloaded — its load event may already have fired, so resolve now if it's ready.
				if (window.Polar?.EmbedCheckout) {
					done();
				} else {
					existing.addEventListener('load', done);
					existing.addEventListener('error', done);
				}
				return;
			}
			const node = document.createElement('script');
			node.src = POLAR_EMBED_SRC;
			node.async = true;
			node.addEventListener('load', done);
			node.addEventListener('error', done);
			document.head.appendChild(node);
		});
	}
	return polarEmbedPromise;
}

const REGISTRY: ProviderInfo[] = config.providers || [];
const PROVIDERS = REGISTRY.map((p) => ({ value: p.value, label: p.label }));
const KEY_PROVIDERS = REGISTRY.filter((p) => p.needsKey).map((p) => p.value);
const HAS_EMBEDDINGS: Record<string, boolean> = {};
const DESC: Record<string, string> = {};
const KEY_HINT: Record<string, string> = {};
REGISTRY.forEach((p) => {
	HAS_EMBEDDINGS[p.value] = p.embeddings !== false;
	DESC[p.value] = p.description || '';
	KEY_HINT[p.value] = p.keyHint || '';
});
const TIER_LABELS: Record<string, string> = {
	recommended: 'Recommended',
	standard: 'Other models',
	limited: 'Not recommended — too small for multi-step wishes',
};

export function AccountTile() {
	const [settings, setSettings] = useState<SettingsData | null>(null);
	const [account, setAccount] = useState<AccountData | null>(null);
	const [provider, setProvider] = useState('');
	const [apiKey, setApiKey] = useState('');
	const [chatModel, setChatModel] = useState('');
	const [embedModel, setEmbedModel] = useState('');
	const [models, setModels] = useState<ModelsData | null>(null);
	const [saving, setSaving] = useState(false);

	useEffect(() => {
		loadAccountSettings()
			.then(({ settings: s, account: a }) => {
				setSettings(s);
				setAccount(a);
				setProvider(s.provider);
				setChatModel(s.chatModel || '');
				setEmbedModel(s.embeddingModel || '');
			})
			.catch(() => {});
	}, []);

	useEffect(() => {
		if (KEY_PROVIDERS.includes(provider)) {
			loadModels(provider)
				.then(setModels)
				.catch(() => {});
		}
	}, [provider]);

	if (!settings) {
		return (
			<Tile title="Account">
				<CardsSkeleton count={2} />
			</Tile>
		);
	}
	const isProBuild = settings.edition === 'pro';

	async function save() {
		setSaving(true);
		try {
			const input = {
				provider,
				chatModel,
				embeddingModel: embedModel,
				...(apiKey ? { apiKey } : {}),
			};
			const s = await saveSettings(input);
			setSettings(s);
			setProvider(s.provider);
			setApiKey('');
			toast('Settings saved.');
		} catch (e) {
			toast(String((e as Error)?.message || e), 'error');
		} finally {
			setSaving(false);
		}
	}

	return (
		<Tile title="Account">
			<Field
				label="Provider"
				htmlFor="djinn-provider"
				description={DESC[provider] || ''}
			>
				<Select
					id="djinn-provider"
					value={provider}
					onChange={setProvider}
					options={PROVIDERS}
				/>
			</Field>
			{provider === 'proxy' ? (
				<ProxyView account={account} setAccount={setAccount} />
			) : (
				<KeyView
					provider={provider}
					apiKey={apiKey}
					setApiKey={setApiKey}
					chatModel={chatModel}
					setChatModel={setChatModel}
					embedModel={embedModel}
					setEmbedModel={setEmbedModel}
					models={models}
					hasApiKey={settings.hasApiKey}
				/>
			)}
			<Button
				variant="primary"
				className="mt-2"
				busy={saving}
				onClick={save}
			>
				Save settings
			</Button>
			{isProBuild ? (
				<LicenseView settings={settings} setSettings={setSettings} />
			) : (
				<UpgradeView />
			)}
		</Tile>
	);
}

function CardsSkeleton({ count }: { count: number }) {
	return (
		<Cards>
			{Array.from({ length: count }).map((_, i) => (
				<div
					key={i}
					className="min-w-[160px] flex-1 rounded-djinn bg-[#f5f5f7] px-[18px] py-4"
				>
					<Skeleton className="h-[26px] w-24" />
					<Skeleton className="mt-1.5 h-4 w-28" />
					<Skeleton className="mt-0.5 h-3 w-20" />
				</div>
			))}
		</Cards>
	);
}

function ProxyView({
	account,
	setAccount,
}: {
	account: AccountData | null;
	setAccount: (a: AccountData) => void;
}) {
	const [connecting, setConnecting] = useState(false);
	const [error, setError] = useState('');
	const tried = useRef(false);

	function link() {
		setError('');
		setConnecting(true);
		connect()
			.then(setAccount)
			.catch((e) =>
				setError(
					String(
						(e as Error)?.message ||
							'Could not reach the Djinn service.',
					),
				),
			)
			.finally(() => setConnecting(false));
	}

	useEffect(() => {
		if (
			!tried.current &&
			account &&
			account.usesProxy &&
			!account.connected
		) {
			tried.current = true;
			link();
		}
	}, [account]);

	if (!account) {
		return <CardsSkeleton count={1} />;
	}
	if (!account.connected) {
		if (error) {
			return (
				<Notice status="error">
					<span>{error}</span>
					<Button
						className="ml-auto"
						busy={connecting}
						onClick={() => {
							tried.current = true;
							link();
						}}
					>
						Try again
					</Button>
				</Notice>
			);
		}
		return (
			<Notice status="info">
				<Spinner />
				<span>Linking this site to Djinn…</span>
			</Notice>
		);
	}

	return (
		<div>
			<Cards>
				<StatCard
					value={'$' + (account.balanceUsd || 0).toFixed(2)}
					label="Account credit"
					sub={
						account.subscribed
							? 'auto-renew on'
							: 'top up to continue'
					}
				/>
			</Cards>
			{config.polarEnabled && <PaymentBlock account={account} />}
		</div>
	);
}

// Pro build: paste a Polar license key to unlock the full schema scope on this site.
function LicenseView({
	settings,
	setSettings,
}: {
	settings: SettingsData;
	setSettings: (s: SettingsData) => void;
}) {
	const [key, setKey] = useState('');
	const [busy, setBusy] = useState(false);

	async function activate() {
		setBusy(true);
		try {
			setSettings(await activateLicense(key));
			setKey('');
			toast('Pro unlocked — the full schema is available.');
		} catch (e) {
			toast(String((e as Error)?.message || e), 'error');
		} finally {
			setBusy(false);
		}
	}

	async function remove() {
		setBusy(true);
		try {
			setSettings(await deactivateLicense());
			toast('License removed from this site.');
		} catch (e) {
			toast(String((e as Error)?.message || e), 'error');
		} finally {
			setBusy(false);
		}
	}

	if (settings.isPro) {
		return (
			<div className="mt-4 border-t border-[#e4e4e7] pt-3.5">
				<p className="text-[#787c82]">
					✓ Djinn Pro is active on this site — the full schema scope
					is unlocked.
				</p>
				<Button className="mt-2" busy={busy} onClick={remove}>
					Remove license
				</Button>
			</div>
		);
	}
	return (
		<div className="mt-4 border-t border-[#e4e4e7] pt-3.5">
			<Field
				label="Pro license key"
				htmlFor="djinn-license"
				description="From your Polar purchase. Unlocks the full schema scope on this site."
			>
				<PasswordField
					id="djinn-license"
					value={key}
					onChange={setKey}
					placeholder="Paste your license key"
				/>
			</Field>
			<Button
				variant="primary"
				className="mt-2"
				busy={busy}
				disabled={!key.trim()}
				onClick={activate}
			>
				Activate Pro
			</Button>
		</div>
	);
}

// Free build: surface what Pro adds and link to the purchase page.
function UpgradeView() {
	return (
		<div className="mt-4 border-t border-[#e4e4e7] pt-3.5">
			<p className="text-[#787c82]">
				This is Djinn Free — wishes read anything and write content
				(posts, pages, media, categories, comments). Djinn Pro unlocks
				the full schema: users, settings, navigation, appearance,
				plugins/themes/core, WooCommerce, and the universal REST escape
				hatch.
			</p>
			<a
				className="mt-2 inline-block text-gold hover:underline"
				href={
					config.proUrl ||
					'https://buy.polar.sh/polar_cl_DGwSeP4nDmqeEXZLw4vC6RFkEBP7frjlGPU3u2768kC'
				}
				target="_blank"
				rel="noopener"
			>
				Get Djinn Pro →
			</a>
		</div>
	);
}

function PaymentBlock({ account }: { account: AccountData }) {
	const [loading, setLoading] = useState<'' | 'credit' | 'subscription'>('');
	const checkout = (kind: 'credit' | 'subscription') => async () => {
		if (loading) {
			return;
		}
		setLoading(kind);
		try {
			const url = await billingCheckout(kind);
			const embed = await ensurePolarEmbed();
			if (embed) {
				const co = await embed.create(url, { theme: 'light' });
				// The SDK's own ✕ posts a message the parent only accepts from polar.sh/sandbox.polar.sh;
				// if the checkout is served from another origin it's ignored, so guarantee a way out via
				// the instance's close() on Escape.
				const onKey = (ev: KeyboardEvent) => {
					if (ev.key === 'Escape') {
						co.close();
					}
				};
				window.addEventListener('keydown', onKey);
				co.addEventListener('success', () => window.location.reload());
				co.addEventListener('close', () => {
					window.removeEventListener('keydown', onKey);
					setLoading('');
				});
			} else {
				window.location.href = url; // keep the spinner running through the navigation
				return;
			}
		} catch (e) {
			toast(
				String((e as Error)?.message || 'Could not start checkout.'),
				'error',
			);
		}
		setLoading('');
	};
	return (
		<div className="mt-3.5">
			<div className="mt-2 flex flex-wrap items-center gap-2">
				<Button
					variant="primary"
					busy={loading === 'credit'}
					disabled={!!loading}
					onClick={checkout('credit')}
				>
					Add credit
				</Button>
				{account.subscribed ? (
					<span className="text-[#787c82]">✓ Auto-renew on</span>
				) : (
					<Button
						busy={loading === 'subscription'}
						disabled={!!loading}
						onClick={checkout('subscription')}
					>
						Subscribe (auto-renew)
					</Button>
				)}
			</div>
		</div>
	);
}

function KeyView({
	provider,
	apiKey,
	setApiKey,
	chatModel,
	setChatModel,
	embedModel,
	setEmbedModel,
	models,
	hasApiKey,
}: {
	provider: string;
	apiKey: string;
	setApiKey: (v: string) => void;
	chatModel: string;
	setChatModel: (v: string) => void;
	embedModel: string;
	setEmbedModel: (v: string) => void;
	models: ModelsData | null;
	hasApiKey: boolean;
}) {
	const chatList = models?.chat || [];
	const withPrice = (m: { id: string; price: string | null }) => ({
		value: m.id,
		label: m.price ? `${m.id} — ${m.price}` : m.id,
	});
	const chatGroups: OptionGroup[] = [
		'recommended',
		'standard',
		'limited',
	].map((t) => ({
		label: TIER_LABELS[t],
		options: chatList.filter((m) => m.tier === t).map(withPrice),
	}));
	const embedOptions = (models?.embed || []).map(withPrice);
	const hasEmbeddings = HAS_EMBEDDINGS[provider] !== false;
	return (
		<div>
			<Field
				label="API key"
				htmlFor="djinn-key"
				description={KEY_HINT[provider] || ''}
			>
				<PasswordField
					id="djinn-key"
					value={apiKey}
					onChange={setApiKey}
					placeholder={
						hasApiKey
							? '•••••••• (saved — leave blank to keep)'
							: 'Paste your key'
					}
				/>
			</Field>
			<Field label="Chat model" htmlFor="djinn-chat">
				<Select
					id="djinn-chat"
					value={chatModel}
					onChange={setChatModel}
					groups={chatGroups}
					placeholder="Provider default"
				/>
			</Field>
			{hasEmbeddings ? (
				<Field label="Embedding model" htmlFor="djinn-embed">
					<Select
						id="djinn-embed"
						value={embedModel}
						onChange={setEmbedModel}
						options={embedOptions}
						placeholder="Provider default"
					/>
				</Field>
			) : (
				<p className="text-[#787c82]">
					This provider has no embeddings API — schema search runs on
					the full schema (no index needed).
				</p>
			)}
			{models?.error && (
				<p className="text-[#787c82]">
					⚠ {models.error} Showing known models as a fallback.
				</p>
			)}
		</div>
	);
}
