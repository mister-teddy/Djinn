<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Admin;

use Djinn\GraphQL\PairingSchema;
use Djinn\GraphQL\SchemaFactory;
use Djinn\License\LicenseClient;
use Djinn\Provider\ModelCatalog;
use Djinn\Provider\Providers;
use Djinn\Provider\ProxyAccount;
use Djinn\Provider\ProxyClient;
use Djinn\Provider\ProxyException;
use Djinn\Rag\Indexer;
use Djinn\Rag\IndexStatus;
use Djinn\Settings;
use Djinn\Store\Repository;
use Djinn\Store\Transcript;
use Djinn\Usage\Pricing;
use GraphQL\Error\UserError;
use Throwable;

/**
 * Resolvers for the admin control-plane schema. Each is a thin wrapper over the same service the
 * REST controller used, reshaped to the GraphQL types. The endpoint already gates on
 * manage_options, so only business rules (org-locked settings, chat ownership) gate again here.
 */
class AdminResolvers {

	/** @return array<string,mixed> */
	public static function settings(): array {
		$s = Settings::all();
		return [
			'edition'        => Settings::edition(),
			'isPro'          => Settings::isPro(),
			'provider'       => Settings::provider(),
			'chatModel'      => $s['chat_model'],
			'embeddingModel' => $s['embedding_model'],
			'hasApiKey'      => Settings::apiKey() !== '',
			'hasSiteToken'   => Settings::siteToken() !== '',
			'usesProxy'      => Settings::usesProxy(),
			'configured'     => Settings::isConfigured(),
		];
	}

	/** The hosted-proxy account (credit, payment status) + connection state. */
	public static function account(): array {
		$base = [
			'usesProxy'  => Settings::usesProxy(),
			'connected'  => null,
			'balanceUsd' => null,
			'spentUsd'   => null,
			'paid'       => null,
			'subscribed' => null,
		];
		if ( ! Settings::usesProxy() ) {
			return $base;
		}
		$acct = ProxyAccount::fetch();
		if ( $acct === null ) {
			$base['connected'] = Settings::siteToken() !== '';
			return $base;
		}
		return array_merge( $base, $acct, [ 'connected' => true ] );
	}

	/** @return array<string,mixed> */
	public static function models( ?string $provider, bool $refresh ): array {
		$provider = $provider ?: Settings::provider();
		if ( $refresh ) {
			ModelCatalog::flush();
		}
		$catalog = ModelCatalog::forProvider( $provider, Settings::apiKey() );
		return [
			'chat'  => array_map( static fn( $m ) => [ 'id' => $m, 'tier' => ModelCatalog::chatTier( $m ), 'price' => Pricing::describe( $m ) ], $catalog['chat'] ),
			'embed' => array_map( static fn( $m ) => [ 'id' => $m, 'price' => Pricing::describe( $m ) ], $catalog['embed'] ),
			'live'  => (bool) $catalog['live'],
			'error' => $catalog['error'] ?: null,
		];
	}

	/** @return array<string,mixed> */
	public static function operations(): array {
		$diff = [ 'added' => [], 'changed' => [] ];
		if ( Settings::isConfigured() ) {
			$diff = IndexStatus::summary()['diff'];
		}
		return [
			'operations' => SchemaFactory::operations(),
			'unindexed'  => $diff['added'],
			'outdated'   => $diff['changed'],
		];
	}

	/** @return array<string,mixed> */
	public static function indexStatus(): array {
		$embeds = Providers::hasEmbeddings( Settings::provider() );
		$base   = [
			'configured'  => Settings::isConfigured(),
			'embeds'      => $embeds,
			'indexed'     => null,
			'upToDate'    => null,
			'model'       => null,
			'storedModel' => null,
			'indexedAt'   => null,
			'countStored' => null,
			'countLive'   => null,
			'estimate'    => null,
			'diff'        => null,
		];
		if ( ! Settings::isConfigured() || ! $embeds ) {
			return $base;
		}
		$s = IndexStatus::summary();
		return array_merge( $base, [
			'indexed'     => $s['indexed'],
			'upToDate'    => $s['up_to_date'],
			'model'       => $s['model'],
			'storedModel' => $s['stored_model'],
			'indexedAt'   => $s['indexed_at'],
			'countStored' => $s['count_stored'],
			'countLive'   => $s['count_live'],
			'estimate'    => $s['estimate'],
			'diff'        => [ 'added' => $s['diff']['added'], 'changed' => $s['diff']['changed'] ],
		] );
	}

	/** @return array<string,mixed> */
	public static function usage(): array {
		$u = Repository::usageSummary();
		return [
			'totals'  => [
				'calls'        => (int) $u['totals']['calls'],
				'prompt'       => (int) $u['totals']['prompt'],
				'completion'   => (int) $u['totals']['completion'],
				'cost'         => (float) $u['totals']['cost'],
				'hasEstimates' => (bool) $u['totals']['has_estimates'],
			],
			'byModel' => array_map( static fn( $r ) => [
				'provider'   => $r['provider'],
				'model'      => $r['model'],
				'kind'       => $r['kind'],
				'calls'      => (int) $r['calls'],
				'prompt'     => (int) $r['prompt'],
				'completion' => (int) $r['completion'],
				'cost'       => (float) $r['cost'],
				'estimated'  => (bool) $r['estimated'],
			], $u['by_model'] ),
			'byDay'   => array_map( static fn( $r ) => [
				'day'   => $r['day'],
				'calls' => (int) $r['calls'],
				'cost'  => (float) $r['cost'],
			], $u['by_day'] ),
			'recent'  => array_map( static fn( $r ) => [
				'createdAt'        => $r['created_at'],
				'provider'         => $r['provider'],
				'model'            => $r['model'],
				'kind'             => $r['kind'],
				'promptTokens'     => (int) $r['prompt_tokens'],
				'completionTokens' => (int) $r['completion_tokens'],
				'estimated'        => (bool) $r['estimated'],
				'cost'             => (float) $r['cost'],
			], $u['recent'] ),
			'account' => Settings::usesProxy() ? self::account() : null,
		];
	}

	/** @return array<int,array<string,mixed>> */
	public static function chats(): array {
		return array_map(
			static fn( $r ) => [ 'id' => (int) $r['id'], 'title' => $r['title'], 'createdAt' => $r['created_at'] ],
			Repository::listChats( get_current_user_id() )
		);
	}

	/** @return array<string,mixed> */
	public static function chat( int $id ): array {
		self::assertOwns( $id );
		return [
			'chatId'   => $id,
			'messages' => Transcript::of( $id ),
			'usage'    => Repository::chatUsage( $id ),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function saveSettings( array $input ): array {
		$map = [
			'provider'        => 'provider',
			'apiKey'          => 'api_key',
			'chatModel'       => 'chat_model',
			'embeddingModel'  => 'embedding_model',
			'siteToken'       => 'site_token',
		];
		$update = [];
		foreach ( $map as $in => $key ) {
			if ( array_key_exists( $in, $input ) && $input[ $in ] !== null ) {
				$update[ $key ] = $input[ $in ];
			}
		}
		Settings::update( $update );
		return self::settings();
	}

	/**
	 * Link this site to the hosted proxy. We open a short pairing window, then ask the proxy to
	 * register; the proxy calls our public `claim` mutation back and pushes the token, which
	 * PairingSchema stores only while the window is open. The token never travels in the register
	 * response, so knowing this site's domain isn't enough to obtain its token. Idempotent — once a
	 * token exists, returns the current account.
	 *
	 * @return array<string,mixed>
	 */
	public static function connect(): array {
		if ( ! Settings::usesProxy() ) {
			throw new UserError( 'This site is not using the Djinn proxy.' );
		}
		if ( Settings::siteToken() !== '' ) {
			return self::account();
		}
		// Open a pairing window keyed by a fresh secret nonce. We send the nonce to the proxy over
		// TLS; the proxy echoes it back when it pushes the token to our public claim callback, and
		// PairingSchema accepts the token only if it matches. An attacker never sees the nonce, so
		// they can't push a token of their own during the window.
		$nonce = wp_generate_password( 40, false );
		set_transient( PairingSchema::PENDING, $nonce, 5 * MINUTE_IN_SECONDS );
		try {
			ProxyClient::call(
				'mutation ( $siteUrl: String!, $claimPath: String, $pairingNonce: String! ) {
					register( siteUrl: $siteUrl, claimPath: $claimPath, pairingNonce: $pairingNonce ) { ok }
				}',
				[
					'siteUrl'      => home_url(),
					'claimPath'    => wp_make_link_relative( rest_url( 'djinn/v1/claim' ) ),
					'pairingNonce' => $nonce,
				]
			);
		} catch ( ProxyException $e ) {
			delete_transient( PairingSchema::PENDING );
			throw new UserError( $e->getMessage() );
		}
		delete_transient( PairingSchema::PENDING );
		// The claim callback wrote the token in another request, so our option cache is stale here.
		Settings::flushCache();
		if ( Settings::siteToken() === '' ) {
			throw new UserError( 'The Djinn service could not complete pairing. Make sure this site is publicly reachable, then try again.' );
		}
		return self::account();
	}

	/** @return array<string,mixed> */
	public static function reindex(): array {
		try {
			return [ 'status' => 'ok', 'chunks' => Indexer::reindex(), 'message' => null ];
		} catch ( Throwable $e ) {
			return [ 'status' => 'error', 'chunks' => null, 'message' => $e->getMessage() ];
		}
	}

	public static function resetUsage(): bool {
		Repository::clearUsage();
		return true;
	}

	/** Activate a Polar license key to unlock Pro scope on this site. @return array<string,mixed> */
	public static function activateLicense( string $key ): array {
		if ( Settings::edition() !== 'pro' ) {
			throw new UserError( 'Licensing applies to the Pro edition.' );
		}
		if ( ! LicenseClient::activate( $key ) ) {
			throw new UserError( 'That license key could not be activated. Check the key and that your plan has a spare activation.' );
		}
		return self::settings();
	}

	/** Release this site's activation and forget the key. @return array<string,mixed> */
	public static function deactivateLicense(): array {
		LicenseClient::deactivate();
		return self::settings();
	}

	/** @return array<string,mixed> */
	public static function billingCheckout( string $kind ): array {
		$token = Settings::siteToken();
		if ( $token === '' ) {
			throw new UserError( 'Connect a Djinn account first.' );
		}
		$kind = $kind === 'subscription' ? 'subscription' : 'credit';
		try {
			$data = ProxyClient::call(
				'mutation ( $kind: String! ) { billingCheckout( kind: $kind ) { url } }',
				[ 'kind' => $kind ],
				$token
			);
		} catch ( ProxyException $e ) {
			throw new UserError( $e->unreachable ? 'Could not reach billing.' : $e->getMessage() );
		}
		$url = (string) ( $data['billingCheckout']['url'] ?? '' );
		if ( $url === '' ) {
			throw new UserError( 'Billing is not available yet.' );
		}
		return [ 'url' => $url ];
	}

	public static function deleteChat( int $id ): bool {
		self::assertOwns( $id );
		Repository::deleteChat( $id );
		return true;
	}

	private static function assertOwns( int $chatId ): void {
		if ( Repository::chatOwner( $chatId ) !== get_current_user_id() ) {
			throw new UserError( 'Not your lamp.' );
		}
	}
}
