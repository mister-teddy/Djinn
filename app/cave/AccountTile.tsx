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
	activateLicense,
	deactivateLicense,
	type SettingsData,
	type AccountData,
	type ModelsData,
} from './data';


const REGISTRY: ProviderInfo[] = config.providers || [];
const PROVIDERS = REGISTRY.map((p) => ({ value: p.value, label: p.label }));
const KEY_PROVIDERS = REGISTRY.filter((p) => p.needsKey).map((p) => p.value);
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
		if (KEY_PROVIDERS.includes(provider)) {
			loadModels(provider)
				.then(setModels)
				.catch(() => {});
		}
	}, [provider]);

	// The proxy chooses server-side; a key provider needs a concrete model, so default an unset
	// choice to the top recommended one rather than leaving it blank (which the backend rejects).
	useEffect(() => {
		if (models && !chatModel) {
			const rec =
				models.chat.find((m) => m.tier === 'recommended') ||
				models.chat[0];
			if (rec) setChatModel(rec.id);
		}
	}, [models]);

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
			{isProBuild && (
				<LicenseView settings={settings} setSettings={setSettings} />
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
				{config.polarEnabled && <PaymentBlock account={account} />}
			</Card>
		</Cards>
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

function PaymentBlock({ account }: { account: AccountData }) {
	const [loading, setLoading] = useState<'' | 'credit' | 'subscription'>('');
	const checkout = (kind: 'credit' | 'subscription') => async () => {
		if (loading) {
			return;
		}
		setLoading(kind);
		try {
			const url = await billingCheckout(kind);
			window.location.href = url; // Polar's hosted checkout; keep the spinner through the navigation
			return;
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
	hasApiKey,
}: {
	provider: string;
	apiKey: string;
	setApiKey: (v: string) => void;
	chatModel: string;
	setChatModel: (v: string) => void;
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
				/>
			</Field>
			{models?.error && (
				<p className="text-[#787c82]">
					⚠ {models.error} Showing known models as a fallback.
				</p>
			)}
		</div>
	);
}
