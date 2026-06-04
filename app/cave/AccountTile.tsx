import { useState, useEffect, useRef } from '@wordpress/element';
import { config, type ProviderInfo } from '@shared/api';
import { Tile, Spinner, Notice, Button, Field, Select, PasswordField, StatCard, Cards, toast, type OptionGroup } from '@shared/ui';
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

declare global {
	interface Window {
		PolarEmbedCheckout?: { create: ( url: string, opts?: { theme?: string } ) => Promise<{ addEventListener: ( ev: string, fn: () => void ) => void }> };
	}
}

const REGISTRY: ProviderInfo[] = config.providers || [];
const PROVIDERS = REGISTRY.map( ( p ) => ( { value: p.value, label: p.label } ) );
const KEY_PROVIDERS = REGISTRY.filter( ( p ) => p.needsKey ).map( ( p ) => p.value );
const HAS_EMBEDDINGS: Record<string, boolean> = {};
const DESC: Record<string, string> = {};
const KEY_HINT: Record<string, string> = {};
REGISTRY.forEach( ( p ) => {
	HAS_EMBEDDINGS[ p.value ] = p.embeddings !== false;
	DESC[ p.value ] = p.description || '';
	KEY_HINT[ p.value ] = p.keyHint || '';
} );
const TIER_LABELS: Record<string, string> = {
	recommended: 'Recommended',
	standard: 'Other models',
	limited: 'Not recommended — too small for multi-step wishes',
};

export function AccountTile() {
	const [ settings, setSettings ] = useState<SettingsData | null>( null );
	const [ account, setAccount ] = useState<AccountData | null>( null );
	const [ provider, setProvider ] = useState( '' );
	const [ apiKey, setApiKey ] = useState( '' );
	const [ chatModel, setChatModel ] = useState( '' );
	const [ embedModel, setEmbedModel ] = useState( '' );
	const [ models, setModels ] = useState<ModelsData | null>( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		loadAccountSettings()
			.then( ( { settings: s, account: a } ) => {
				setSettings( s );
				setAccount( a );
				setProvider( s.provider );
				setChatModel( s.chatModel || '' );
				setEmbedModel( s.embeddingModel || '' );
			} )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		if ( KEY_PROVIDERS.includes( provider ) ) {
			loadModels( provider ).then( setModels ).catch( () => {} );
		}
	}, [ provider ] );

	if ( ! settings ) {
		return <Tile title="Account"><Spinner /></Tile>;
	}
	const isOrg = settings.isOrg;

	async function save() {
		setSaving( true );
		try {
			const input = { provider, chatModel, embeddingModel: embedModel, ...( apiKey ? { apiKey } : {} ) };
			const s = await saveSettings( input );
			setSettings( s );
			setProvider( s.provider );
			setApiKey( '' );
			toast( 'Settings saved.' );
		} catch ( e ) {
			toast( String( ( e as Error )?.message || e ), 'error' );
		} finally {
			setSaving( false );
		}
	}

	return (
		<Tile title="Account">
			{ ! isOrg && (
				<Field label="Provider" htmlFor="djinn-provider" description={ DESC[ provider ] || '' }>
					<Select id="djinn-provider" value={ provider } onChange={ setProvider } options={ PROVIDERS } />
				</Field>
			) }
			{ provider === 'proxy' ? (
				<ProxyView account={ account } setAccount={ setAccount } isOrg={ isOrg } />
			) : (
				<KeyView
					provider={ provider }
					apiKey={ apiKey }
					setApiKey={ setApiKey }
					chatModel={ chatModel }
					setChatModel={ setChatModel }
					embedModel={ embedModel }
					setEmbedModel={ setEmbedModel }
					models={ models }
					hasApiKey={ settings.hasApiKey }
				/>
			) }
			{ ! isOrg && (
				<Button variant="primary" className="mt-2" busy={ saving } onClick={ save }>Save settings</Button>
			) }
		</Tile>
	);
}

function ProxyView( { account, setAccount, isOrg }: { account: AccountData | null; setAccount: ( a: AccountData ) => void; isOrg: boolean } ) {
	const [ connecting, setConnecting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const tried = useRef( false );

	function link() {
		setError( '' );
		setConnecting( true );
		connect()
			.then( setAccount )
			.catch( ( e ) => setError( String( ( e as Error )?.message || 'Could not reach the Djinn service.' ) ) )
			.finally( () => setConnecting( false ) );
	}

	useEffect( () => {
		if ( ! tried.current && account && account.usesProxy && ! account.connected ) {
			tried.current = true;
			link();
		}
	}, [ account ] );

	if ( ! account ) {
		return <Spinner />;
	}
	if ( ! account.connected ) {
		if ( error ) {
			return (
				<Notice status="error">
					<span>{ error }</span>
					<Button className="ml-auto" busy={ connecting } onClick={ () => { tried.current = true; link(); } }>Try again</Button>
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
				{ isOrg && (
					<StatCard value={ account.wishesLeft != null ? String( account.wishesLeft ) : '—' } label="Free wishes left" />
				) }
				<StatCard
					value={ '$' + ( account.balanceUsd || 0 ).toFixed( 2 ) }
					label="Account credit"
					sub={ account.subscribed ? 'auto-renew on' : 'top up to continue' }
				/>
			</Cards>
			{ config.polarEnabled && <PaymentBlock account={ account } /> }
		</div>
	);
}

function PaymentBlock( { account }: { account: AccountData } ) {
	const checkout = ( kind: 'credit' | 'subscription' ) => async () => {
		try {
			const url = await billingCheckout( kind );
			const embed = window.PolarEmbedCheckout;
			if ( embed ) {
				const co = await embed.create( url, { theme: 'dark' } );
				co.addEventListener( 'success', () => window.location.reload() );
			} else {
				window.location.href = url;
			}
		} catch ( e ) {
			toast( String( ( e as Error )?.message || 'Could not start checkout.' ), 'error' );
		}
	};
	return (
		<div className="mt-3.5">
			<p className="text-[#787c82]">Add credit to keep wishing — you pay only for what you use, billed by Polar.</p>
			<div className="mt-2 flex flex-wrap items-center gap-2">
				<Button variant="primary" onClick={ checkout( 'credit' ) }>Add credit</Button>
				{ account.subscribed ? (
					<span className="text-[#787c82]">✓ Auto-renew on</span>
				) : (
					<Button onClick={ checkout( 'subscription' ) }>Subscribe (auto-renew)</Button>
				) }
			</div>
		</div>
	);
}

function KeyView( {
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
	setApiKey: ( v: string ) => void;
	chatModel: string;
	setChatModel: ( v: string ) => void;
	embedModel: string;
	setEmbedModel: ( v: string ) => void;
	models: ModelsData | null;
	hasApiKey: boolean;
} ) {
	const chatList = models?.chat || [];
	const withPrice = ( m: { id: string; price: string | null } ) => ( { value: m.id, label: m.price ? `${ m.id } — ${ m.price }` : m.id } );
	const chatGroups: OptionGroup[] = [ 'recommended', 'standard', 'limited' ].map( ( t ) => ( {
		label: TIER_LABELS[ t ],
		options: chatList.filter( ( m ) => m.tier === t ).map( withPrice ),
	} ) );
	const embedOptions = ( models?.embed || [] ).map( withPrice );
	const hasEmbeddings = HAS_EMBEDDINGS[ provider ] !== false;
	return (
		<div>
			<Field label="API key" htmlFor="djinn-key" description={ KEY_HINT[ provider ] || '' }>
				<PasswordField
					id="djinn-key"
					value={ apiKey }
					onChange={ setApiKey }
					placeholder={ hasApiKey ? '•••••••• (saved — leave blank to keep)' : 'Paste your key' }
				/>
			</Field>
			<Field label="Chat model" htmlFor="djinn-chat">
				<Select id="djinn-chat" value={ chatModel } onChange={ setChatModel } groups={ chatGroups } placeholder="Provider default" />
			</Field>
			{ hasEmbeddings ? (
				<Field label="Embedding model" htmlFor="djinn-embed">
					<Select id="djinn-embed" value={ embedModel } onChange={ setEmbedModel } options={ embedOptions } placeholder="Provider default" />
				</Field>
			) : (
				<p className="text-[#787c82]">This provider has no embeddings API — schema search runs on the full schema (no index needed).</p>
			) }
			{ models?.error && <p className="text-[#787c82]">⚠ { models.error } Showing known models as a fallback.</p> }
		</div>
	);
}
