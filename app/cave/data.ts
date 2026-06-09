import { gql } from '@shared/api';

export interface SettingsData {
	edition: string;
	isPro: boolean;
	provider: string;
	chatModel: string | null;
	embeddingModel: string | null;
	hasApiKey: boolean;
	hasSiteToken: boolean;
	usesProxy: boolean;
	configured: boolean;
}

export interface AccountData {
	usesProxy: boolean;
	connected: boolean | null;
	balanceUsd: number | null;
	spentUsd: number | null;
	paid: boolean | null;
	subscribed: boolean | null;
}

export interface ChatModelInfo {
	id: string;
	tier: string | null;
	price: string | null;
}
export interface ModelsData {
	chat: ChatModelInfo[];
	embed: { id: string; price: string | null }[];
	live: boolean;
	error: string | null;
}

export interface OpArg {
	name: string;
	type: string;
	required: boolean;
}
export interface OperationInfo {
	domain: string;
	name: string;
	kind: string;
	description: string | null;
	args: OpArg[];
	returns: string | null;
}
export interface OperationsData {
	operations: OperationInfo[];
	unindexed: string[];
	outdated: string[];
}

export interface UsageRow {
	provider: string | null;
	model: string | null;
	kind: string | null;
	calls: number;
	prompt: number;
	completion: number;
	cost: number;
	estimated: boolean;
}
export interface UsageRecentRow {
	createdAt: string | null;
	provider: string | null;
	model: string | null;
	kind: string | null;
	promptTokens: number;
	completionTokens: number;
	estimated: boolean;
	cost: number;
}
export interface UsageData {
	totals: { calls: number; prompt: number; completion: number; cost: number; hasEstimates: boolean };
	byModel: UsageRow[];
	byDay: { day: string; calls: number; cost: number }[];
	recent: UsageRecentRow[];
}

export interface SettingsInput {
	provider?: string;
	apiKey?: string;
	chatModel?: string;
	embeddingModel?: string;
}

const ACCOUNT_FIELDS = {
	usesProxy: true,
	connected: true,
	balanceUsd: true,
	spentUsd: true,
	paid: true,
	subscribed: true,
} as const;

const SETTINGS_FIELDS = {
	edition: true,
	isPro: true,
	provider: true,
	chatModel: true,
	embeddingModel: true,
	hasApiKey: true,
	hasSiteToken: true,
	usesProxy: true,
	configured: true,
} as const;

const MODELS_FIELDS = {
	chat: { id: true, tier: true, price: true },
	embed: { id: true, price: true },
	live: true,
	error: true,
} as const;

export async function loadAccountSettings(): Promise<{ settings: SettingsData; account: AccountData }> {
	const d = await gql.query( { settings: SETTINGS_FIELDS, account: ACCOUNT_FIELDS } );
	return { settings: d.settings as SettingsData, account: d.account as AccountData };
}

export async function loadModels( provider: string ): Promise<ModelsData> {
	const d = await gql.query( { models: { __args: { provider }, ...MODELS_FIELDS } } );
	return d.models as unknown as ModelsData;
}

export async function saveSettings( input: SettingsInput ): Promise<SettingsData> {
	const d = await gql.mutation( { saveSettings: { __args: { input }, ...SETTINGS_FIELDS } } );
	return d.saveSettings as SettingsData;
}

export async function connect(): Promise<AccountData> {
	const d = await gql.mutation( { connect: ACCOUNT_FIELDS } );
	return d.connect as AccountData;
}

export async function activateLicense( key: string ): Promise<SettingsData> {
	const d = await gql.mutation( { activateLicense: { __args: { key }, ...SETTINGS_FIELDS } } );
	return d.activateLicense as SettingsData;
}

export async function deactivateLicense(): Promise<SettingsData> {
	const d = await gql.mutation( { deactivateLicense: SETTINGS_FIELDS } );
	return d.deactivateLicense as SettingsData;
}

export async function loadOperations(): Promise<OperationsData> {
	const d = await gql.query( {
		operations: {
			operations: { domain: true, name: true, kind: true, description: true, args: { name: true, type: true, required: true }, returns: true },
			unindexed: true,
			outdated: true,
		},
	} );
	return d.operations as OperationsData;
}

export async function loadUsage(): Promise<UsageData> {
	const d = await gql.query( {
		usage: {
			totals: { calls: true, prompt: true, completion: true, cost: true, hasEstimates: true },
			byModel: { provider: true, model: true, kind: true, calls: true, prompt: true, completion: true, cost: true, estimated: true },
			byDay: { day: true, calls: true, cost: true },
			recent: { createdAt: true, provider: true, model: true, kind: true, promptTokens: true, completionTokens: true, estimated: true, cost: true },
		},
	} );
	return d.usage as UsageData;
}

export async function resetUsage(): Promise<void> {
	await gql.mutation( { resetUsage: true } );
}

export async function billingCheckout( kind: 'credit' | 'subscription' ): Promise<string> {
	const d = await gql.mutation( { billingCheckout: { __args: { kind }, url: true } } );
	return ( d.billingCheckout as { url: string } ).url;
}
