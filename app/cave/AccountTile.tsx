import { useState, useEffect, useRef } from '@wordpress/element';
import { PolarEmbedCheckout } from '@polar-sh/checkout/embed';
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
	Card,
	Cards,
	Switch,
	toast,
	type OptionGroup,
} from '@shared/ui';
import {
	loadAccountSettings,
	loadModels,
	saveSettings,
	connect,
	billingCheckout,
	type SettingsData,
	type AccountData,
	type ModelsData,
} from './data';

const REGISTRY: ProviderInfo[] = config.providers || [];
const PROVIDERS = REGISTRY.map((p) => ({ value: p.value, label: p.label }));
const KEY_PROVIDERS = REGISTRY.filter((p) => p.needsKey).map((p) => p.value);
const MODEL_PROVIDERS = REGISTRY.filter((p) => p.needsModel).map(
	(p) => p.value,
);
const DESC: Record<string, string> = {};
const KEY_HINT: Record<string, string> = {};
REGISTRY.forEach((p) => {
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
	const [models, setModels] = useState<ModelsData | null>(null);
	const [modelsLoading, setModelsLoading] = useState(false);
	const [saving, setSaving] = useState(false);

	useEffect(() => {
		loadAccountSettings()
			.then(({ settings: s, account: a }) => {
				setSettings(s);
				setAccount(a);
				setProvider(s.provider);
				setChatModel(s.chatModel || '');
			})
			.catch(() => {});
	}, []);

	useEffect(() => {
		if (MODEL_PROVIDERS.includes(provider)) {
			refreshModels(false);
		} else {
			setModels(null);
		}
	}, [provider]);

	function refreshModels(refresh: boolean) {
		if (!MODEL_PROVIDERS.includes(provider)) {
			return;
		}
		setModelsLoading(true);
		loadModels(provider, refresh)
			.then(setModels)
			.catch(() => {})
			.finally(() => setModelsLoading(false));
	}

	// The proxy chooses server-side; a key provider needs a concrete current model, so default an
	// unset or retired choice to the top recommended one rather than leaving it on a provider 404.
	useEffect(() => {
		if (models && models.chat.length) {
			const available = models.chat.some((m) => m.id === chatModel);
			if (!chatModel || !available) {
				const rec =
					models.chat.find((m) => m.tier === 'recommended') ||
					models.chat[0];
				if (rec) setChatModel(rec.id);
			}
		}
	}, [models]);

	if (!settings) {
		return (
			<Tile title="Account">
				<CardsSkeleton count={2} />
			</Tile>
		);
	}
	async function save() {
		setSaving(true);
		try {
			const input = {
				provider,
				chatModel: MODEL_PROVIDERS.includes(provider) ? chatModel : '',
				...(apiKey ? { apiKey } : {}),
			};
			const s = await saveSettings(input);
			setSettings(s);
			setProvider(s.provider);
			setApiKey('');
			// The proxy connect() requires the saved provider to be 'proxy', so refresh the account
			// after saving — that flips usesProxy and lets ProxyView link without a manual reload.
			if (s.provider === 'proxy') {
				const { account: a } = await loadAccountSettings();
				setAccount(a);
			}
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
			) : provider === 'wp-ai-client' ? (
				<WordPressAIClientView />
			) : (
				<KeyView
					provider={provider}
					apiKey={apiKey}
					setApiKey={setApiKey}
					chatModel={chatModel}
					setChatModel={setChatModel}
					models={models}
					modelsLoading={modelsLoading}
					onRefreshModels={() => refreshModels(true)}
					hasApiKey={settings.hasApiKey}
					needsKey={KEY_PROVIDERS.includes(provider)}
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
		</Tile>
	);
}

function WordPressAIClientView() {
	return (
		<Notice status="info">
			<span>
				Djinn will use the site-level AI provider configured in
				WordPress.
			</span>
		</Notice>
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

	// Link once the provider is actually saved as proxy (usesProxy) — connect() rejects otherwise.
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
	if (!account.usesProxy) {
		return (
			<Notice status="info">
				<span>Click “Save settings” to link this site to Djinn.</span>
			</Notice>
		);
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
		<Cards>
			<Card className="min-w-[220px]">
				<div className="text-[26px] font-semibold leading-tight">
					{'$' + (account.balanceUsd || 0).toFixed(2)}
				</div>
				<div className="mt-1.5 font-semibold text-[#1d2327]">
					Account credit
				</div>
				<div className="mt-0.5 text-xs text-[#787c82]">
					{account.subscribed
						? 'auto-renew on'
						: 'top up to keep wishing'}
				</div>
				<PaymentBlock account={account} />
			</Card>
		</Cards>
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
			const co = await PolarEmbedCheckout.create(url, { theme: 'light' });
			// The overlay is one cross-origin iframe: the SDK's ✕ only closes when the checkout is
			// served from polar.sh, Escape can't reach the parent (focus is inside the iframe), and
			// close() never fires the 'close' listener. Render our own ✕ over the overlay.
			const closeBtn = document.createElement('button');
			closeBtn.setAttribute('aria-label', 'Close checkout');
			closeBtn.textContent = '✕';
			closeBtn.style.cssText =
				'position:fixed;top:16px;right:16px;z-index:2147483647;width:36px;height:36px;padding:0;border:0;border-radius:9999px;background:#fff;color:#1d2327;font-size:18px;line-height:1;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)';
			const cleanup = () => {
				closeBtn.remove();
				setLoading('');
			};
			closeBtn.onclick = () => {
				co.close();
				cleanup();
			};
			document.body.appendChild(closeBtn);
			co.addEventListener('success', () => window.location.reload());
			co.addEventListener('close', cleanup);
		} catch (e) {
			toast(
				String((e as Error)?.message || 'Could not start checkout.'),
				'error',
			);
		}
		setLoading('');
	};
	return (
		<div className="mt-3 flex flex-col items-start gap-2.5 border-t border-[#e4e4e7] pt-3">
			<Button
				variant="primary"
				className="w-full"
				busy={loading === 'credit'}
				disabled={!!loading}
				onClick={checkout('credit')}
			>
				Add credit
			</Button>
			<Switch
				checked={!!account.subscribed}
				disabled={loading === 'subscription'}
				onChange={(on) => {
					if (on) {
						if (!account.subscribed) checkout('subscription')();
					} else if (account.subscribed) {
						toast('Cancel auto-renew from your Polar account.');
					}
				}}
				label="Auto top-up monthly"
			/>
		</div>
	);
}

function KeyView({
	provider,
	apiKey,
	setApiKey,
	chatModel,
	setChatModel,
	models,
	modelsLoading,
	onRefreshModels,
	hasApiKey,
	needsKey,
}: {
	provider: string;
	apiKey: string;
	setApiKey: (v: string) => void;
	chatModel: string;
	setChatModel: (v: string) => void;
	models: ModelsData | null;
	modelsLoading: boolean;
	onRefreshModels: () => void;
	hasApiKey: boolean;
	needsKey: boolean;
}) {
	const chatList = models?.chat || [];
	const selectedMissing =
		!!chatModel &&
		!!chatList.length &&
		!chatList.some((m) => m.id === chatModel);
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
	return (
		<div>
			{needsKey && (
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
			)}
			<Field label="Chat model" htmlFor="djinn-chat">
				<div className="flex flex-wrap items-center gap-2">
					<Select
						id="djinn-chat"
						value={chatModel}
						onChange={setChatModel}
						groups={chatGroups}
						disabled={modelsLoading}
					/>
					<Button
						variant="secondary"
						busy={modelsLoading}
						onClick={onRefreshModels}
					>
						Refresh
					</Button>
				</div>
			</Field>
			{selectedMissing && (
				<p className="text-[#b45309]">
					The saved model is no longer in the provider list. Choose a
					current model and save settings.
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
