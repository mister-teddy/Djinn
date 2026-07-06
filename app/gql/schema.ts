// @ts-nocheck
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */

export type Scalars = {
    String: string,
    Boolean: boolean,
    Int: number,
    Float: number,
    JSON: any,
}

export interface Query {
    settings: Settings
    account: Account
    models: ModelCatalog
    operations: OperationsReport
    usage: Usage
    chats: Chat[]
    chat: (ChatDetail | null)
    __typename: 'Query'
}

export interface Settings {
    edition: Scalars['String']
    isPro: Scalars['Boolean']
    provider: Scalars['String']
    chatModel: (Scalars['String'] | null)
    hasApiKey: Scalars['Boolean']
    hasSiteToken: Scalars['Boolean']
    usesProxy: Scalars['Boolean']
    configured: Scalars['Boolean']
    __typename: 'Settings'
}

export interface Account {
    usesProxy: Scalars['Boolean']
    connected: (Scalars['Boolean'] | null)
    balanceUsd: (Scalars['Float'] | null)
    spentUsd: (Scalars['Float'] | null)
    paid: (Scalars['Boolean'] | null)
    subscribed: (Scalars['Boolean'] | null)
    __typename: 'Account'
}

export interface ModelCatalog {
    chat: ChatModel[]
    live: Scalars['Boolean']
    error: (Scalars['String'] | null)
    __typename: 'ModelCatalog'
}

export interface ChatModel {
    id: Scalars['String']
    tier: (Scalars['String'] | null)
    price: (Scalars['String'] | null)
    __typename: 'ChatModel'
}

export interface OperationsReport {
    operations: Operation[]
    __typename: 'OperationsReport'
}

export interface Operation {
    domain: Scalars['String']
    name: Scalars['String']
    kind: Scalars['String']
    description: (Scalars['String'] | null)
    args: OpArg[]
    returns: (Scalars['String'] | null)
    __typename: 'Operation'
}

export interface OpArg {
    name: Scalars['String']
    type: Scalars['String']
    required: Scalars['Boolean']
    __typename: 'OpArg'
}

export interface Usage {
    totals: UsageTotals
    byModel: UsageByModel[]
    byDay: UsageByDay[]
    recent: UsageRecent[]
    account: (Account | null)
    __typename: 'Usage'
}

export interface UsageTotals {
    calls: Scalars['Int']
    prompt: Scalars['Int']
    completion: Scalars['Int']
    cost: Scalars['Float']
    hasEstimates: Scalars['Boolean']
    __typename: 'UsageTotals'
}

export interface UsageByModel {
    provider: (Scalars['String'] | null)
    model: (Scalars['String'] | null)
    kind: (Scalars['String'] | null)
    calls: Scalars['Int']
    prompt: Scalars['Int']
    completion: Scalars['Int']
    cost: Scalars['Float']
    estimated: Scalars['Boolean']
    __typename: 'UsageByModel'
}

export interface UsageByDay {
    day: Scalars['String']
    calls: Scalars['Int']
    cost: Scalars['Float']
    __typename: 'UsageByDay'
}

export interface UsageRecent {
    createdAt: (Scalars['String'] | null)
    provider: (Scalars['String'] | null)
    model: (Scalars['String'] | null)
    kind: (Scalars['String'] | null)
    promptTokens: Scalars['Int']
    completionTokens: Scalars['Int']
    estimated: Scalars['Boolean']
    cost: Scalars['Float']
    __typename: 'UsageRecent'
}

export interface Chat {
    id: Scalars['Int']
    title: (Scalars['String'] | null)
    createdAt: (Scalars['String'] | null)
    __typename: 'Chat'
}

export interface ChatDetail {
    chatId: Scalars['Int']
    messages: ChatMessage[]
    usage: ChatUsage
    __typename: 'ChatDetail'
}

export interface ChatMessage {
    role: Scalars['String']
    content: (Scalars['String'] | null)
    attachments: (Attachment[] | null)
    kind: (Scalars['String'] | null)
    status: (Scalars['String'] | null)
    operation: (Scalars['String'] | null)
    variables: (Scalars['JSON'] | null)
    summary: (Scalars['String'] | null)
    message: (Scalars['String'] | null)
    result: (Scalars['JSON'] | null)
    pendingId: (Scalars['Int'] | null)
    __typename: 'ChatMessage'
}

export interface Attachment {
    filename: (Scalars['String'] | null)
    token: (Scalars['String'] | null)
    size: (Scalars['Int'] | null)
    mime: (Scalars['String'] | null)
    __typename: 'Attachment'
}

export interface ChatUsage {
    prompt: Scalars['Int']
    completion: Scalars['Int']
    tokens: Scalars['Int']
    cost: Scalars['Float']
    calls: Scalars['Int']
    __typename: 'ChatUsage'
}

export interface Mutation {
    saveSettings: Settings
    connect: Account
    activateLicense: Settings
    deactivateLicense: Settings
    resetUsage: Scalars['Boolean']
    billingCheckout: CheckoutSession
    deleteChat: Scalars['Boolean']
    __typename: 'Mutation'
}

export type BillingKind = 'credit' | 'subscription'

export interface CheckoutSession {
    url: Scalars['String']
    __typename: 'CheckoutSession'
}

export interface QueryGenqlSelection{
    settings?: SettingsGenqlSelection
    account?: AccountGenqlSelection
    models?: (ModelCatalogGenqlSelection & { __args?: {provider?: (Scalars['String'] | null), refresh?: (Scalars['Boolean'] | null)} })
    operations?: OperationsReportGenqlSelection
    usage?: UsageGenqlSelection
    chats?: ChatGenqlSelection
    chat?: (ChatDetailGenqlSelection & { __args: {id: Scalars['Int']} })
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface SettingsGenqlSelection{
    edition?: boolean | number
    isPro?: boolean | number
    provider?: boolean | number
    chatModel?: boolean | number
    hasApiKey?: boolean | number
    hasSiteToken?: boolean | number
    usesProxy?: boolean | number
    configured?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface AccountGenqlSelection{
    usesProxy?: boolean | number
    connected?: boolean | number
    balanceUsd?: boolean | number
    spentUsd?: boolean | number
    paid?: boolean | number
    subscribed?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface ModelCatalogGenqlSelection{
    chat?: ChatModelGenqlSelection
    live?: boolean | number
    error?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface ChatModelGenqlSelection{
    id?: boolean | number
    tier?: boolean | number
    price?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface OperationsReportGenqlSelection{
    operations?: OperationGenqlSelection
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface OperationGenqlSelection{
    domain?: boolean | number
    name?: boolean | number
    kind?: boolean | number
    description?: boolean | number
    args?: OpArgGenqlSelection
    returns?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface OpArgGenqlSelection{
    name?: boolean | number
    type?: boolean | number
    required?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface UsageGenqlSelection{
    totals?: UsageTotalsGenqlSelection
    byModel?: UsageByModelGenqlSelection
    byDay?: UsageByDayGenqlSelection
    recent?: UsageRecentGenqlSelection
    account?: AccountGenqlSelection
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface UsageTotalsGenqlSelection{
    calls?: boolean | number
    prompt?: boolean | number
    completion?: boolean | number
    cost?: boolean | number
    hasEstimates?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface UsageByModelGenqlSelection{
    provider?: boolean | number
    model?: boolean | number
    kind?: boolean | number
    calls?: boolean | number
    prompt?: boolean | number
    completion?: boolean | number
    cost?: boolean | number
    estimated?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface UsageByDayGenqlSelection{
    day?: boolean | number
    calls?: boolean | number
    cost?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface UsageRecentGenqlSelection{
    createdAt?: boolean | number
    provider?: boolean | number
    model?: boolean | number
    kind?: boolean | number
    promptTokens?: boolean | number
    completionTokens?: boolean | number
    estimated?: boolean | number
    cost?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface ChatGenqlSelection{
    id?: boolean | number
    title?: boolean | number
    createdAt?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface ChatDetailGenqlSelection{
    chatId?: boolean | number
    messages?: ChatMessageGenqlSelection
    usage?: ChatUsageGenqlSelection
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface ChatMessageGenqlSelection{
    role?: boolean | number
    content?: boolean | number
    attachments?: AttachmentGenqlSelection
    kind?: boolean | number
    status?: boolean | number
    operation?: boolean | number
    variables?: boolean | number
    summary?: boolean | number
    message?: boolean | number
    result?: boolean | number
    pendingId?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface AttachmentGenqlSelection{
    filename?: boolean | number
    token?: boolean | number
    size?: boolean | number
    mime?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface ChatUsageGenqlSelection{
    prompt?: boolean | number
    completion?: boolean | number
    tokens?: boolean | number
    cost?: boolean | number
    calls?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface MutationGenqlSelection{
    saveSettings?: (SettingsGenqlSelection & { __args: {input: SettingsInput} })
    connect?: AccountGenqlSelection
    activateLicense?: (SettingsGenqlSelection & { __args: {key: Scalars['String']} })
    deactivateLicense?: SettingsGenqlSelection
    resetUsage?: boolean | number
    billingCheckout?: (CheckoutSessionGenqlSelection & { __args: {kind: BillingKind} })
    deleteChat?: { __args: {id: Scalars['Int']} }
    __typename?: boolean | number
    __scalar?: boolean | number
}

export interface SettingsInput {provider?: (Scalars['String'] | null),apiKey?: (Scalars['String'] | null),chatModel?: (Scalars['String'] | null),siteToken?: (Scalars['String'] | null)}

export interface CheckoutSessionGenqlSelection{
    url?: boolean | number
    __typename?: boolean | number
    __scalar?: boolean | number
}


    const Query_possibleTypes: string[] = ['Query']
    export const isQuery = (obj?: { __typename?: any } | null): obj is Query => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isQuery"')
      return Query_possibleTypes.includes(obj.__typename)
    }
    


    const Settings_possibleTypes: string[] = ['Settings']
    export const isSettings = (obj?: { __typename?: any } | null): obj is Settings => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isSettings"')
      return Settings_possibleTypes.includes(obj.__typename)
    }
    


    const Account_possibleTypes: string[] = ['Account']
    export const isAccount = (obj?: { __typename?: any } | null): obj is Account => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isAccount"')
      return Account_possibleTypes.includes(obj.__typename)
    }
    


    const ModelCatalog_possibleTypes: string[] = ['ModelCatalog']
    export const isModelCatalog = (obj?: { __typename?: any } | null): obj is ModelCatalog => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isModelCatalog"')
      return ModelCatalog_possibleTypes.includes(obj.__typename)
    }
    


    const ChatModel_possibleTypes: string[] = ['ChatModel']
    export const isChatModel = (obj?: { __typename?: any } | null): obj is ChatModel => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isChatModel"')
      return ChatModel_possibleTypes.includes(obj.__typename)
    }
    


    const OperationsReport_possibleTypes: string[] = ['OperationsReport']
    export const isOperationsReport = (obj?: { __typename?: any } | null): obj is OperationsReport => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isOperationsReport"')
      return OperationsReport_possibleTypes.includes(obj.__typename)
    }
    


    const Operation_possibleTypes: string[] = ['Operation']
    export const isOperation = (obj?: { __typename?: any } | null): obj is Operation => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isOperation"')
      return Operation_possibleTypes.includes(obj.__typename)
    }
    


    const OpArg_possibleTypes: string[] = ['OpArg']
    export const isOpArg = (obj?: { __typename?: any } | null): obj is OpArg => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isOpArg"')
      return OpArg_possibleTypes.includes(obj.__typename)
    }
    


    const Usage_possibleTypes: string[] = ['Usage']
    export const isUsage = (obj?: { __typename?: any } | null): obj is Usage => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isUsage"')
      return Usage_possibleTypes.includes(obj.__typename)
    }
    


    const UsageTotals_possibleTypes: string[] = ['UsageTotals']
    export const isUsageTotals = (obj?: { __typename?: any } | null): obj is UsageTotals => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isUsageTotals"')
      return UsageTotals_possibleTypes.includes(obj.__typename)
    }
    


    const UsageByModel_possibleTypes: string[] = ['UsageByModel']
    export const isUsageByModel = (obj?: { __typename?: any } | null): obj is UsageByModel => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isUsageByModel"')
      return UsageByModel_possibleTypes.includes(obj.__typename)
    }
    


    const UsageByDay_possibleTypes: string[] = ['UsageByDay']
    export const isUsageByDay = (obj?: { __typename?: any } | null): obj is UsageByDay => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isUsageByDay"')
      return UsageByDay_possibleTypes.includes(obj.__typename)
    }
    


    const UsageRecent_possibleTypes: string[] = ['UsageRecent']
    export const isUsageRecent = (obj?: { __typename?: any } | null): obj is UsageRecent => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isUsageRecent"')
      return UsageRecent_possibleTypes.includes(obj.__typename)
    }
    


    const Chat_possibleTypes: string[] = ['Chat']
    export const isChat = (obj?: { __typename?: any } | null): obj is Chat => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isChat"')
      return Chat_possibleTypes.includes(obj.__typename)
    }
    


    const ChatDetail_possibleTypes: string[] = ['ChatDetail']
    export const isChatDetail = (obj?: { __typename?: any } | null): obj is ChatDetail => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isChatDetail"')
      return ChatDetail_possibleTypes.includes(obj.__typename)
    }
    


    const ChatMessage_possibleTypes: string[] = ['ChatMessage']
    export const isChatMessage = (obj?: { __typename?: any } | null): obj is ChatMessage => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isChatMessage"')
      return ChatMessage_possibleTypes.includes(obj.__typename)
    }
    


    const Attachment_possibleTypes: string[] = ['Attachment']
    export const isAttachment = (obj?: { __typename?: any } | null): obj is Attachment => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isAttachment"')
      return Attachment_possibleTypes.includes(obj.__typename)
    }
    


    const ChatUsage_possibleTypes: string[] = ['ChatUsage']
    export const isChatUsage = (obj?: { __typename?: any } | null): obj is ChatUsage => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isChatUsage"')
      return ChatUsage_possibleTypes.includes(obj.__typename)
    }
    


    const Mutation_possibleTypes: string[] = ['Mutation']
    export const isMutation = (obj?: { __typename?: any } | null): obj is Mutation => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isMutation"')
      return Mutation_possibleTypes.includes(obj.__typename)
    }
    


    const CheckoutSession_possibleTypes: string[] = ['CheckoutSession']
    export const isCheckoutSession = (obj?: { __typename?: any } | null): obj is CheckoutSession => {
      if (!obj?.__typename) throw new Error('__typename is missing in "isCheckoutSession"')
      return CheckoutSession_possibleTypes.includes(obj.__typename)
    }
    

export const enumBillingKind = {
   credit: 'credit' as const,
   subscription: 'subscription' as const
}
