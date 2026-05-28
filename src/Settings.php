<?php

declare( strict_types=1 );

namespace Djinn;

/**
 * Plugin settings: edition, which LLM provider to use, the API key / proxy token, and model names.
 * Stored in a single option array; secrets are never sent back to the client.
 *
 * Editions (DJINN_EDITION, stamped at build time, default 'byo'):
 *   - 'org' — free WordPress.org build: always uses our hosted proxy, no key entry.
 *   - 'byo' — bring-your-own-key build: OpenAI/Gemini directly, or optionally our proxy.
 */
class Settings {

	private const OPTION = 'djinn_settings';

	public static function edition(): string {
		return defined( 'DJINN_EDITION' ) && DJINN_EDITION === 'org' ? 'org' : 'byo';
	}

	public static function isOrg(): bool {
		return self::edition() === 'org';
	}

	/** @return array{provider:string,api_key:string,site_token:string,chat_model:string,embedding_model:string} */
	public static function all(): array {
		$defaults = [
			'provider'        => 'openai',
			'api_key'         => '',
			'site_token'      => '',
			'chat_model'      => '',
			'embedding_model' => '',
		];
		$stored = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	}

	/** The effective provider. ORG always routes through the proxy. */
	public static function provider(): string {
		if ( self::isOrg() ) {
			return 'proxy';
		}
		return self::all()['provider'];
	}

	public static function usesProxy(): bool {
		return self::provider() === 'proxy';
	}

	public static function apiKey(): string {
		// Allow overriding the key with a constant so it never has to live in the DB.
		if ( defined( 'DJINN_API_KEY' ) && DJINN_API_KEY ) {
			return (string) DJINN_API_KEY;
		}
		return self::all()['api_key'];
	}

	/** Per-site token identifying this install to the proxy. */
	public static function siteToken(): string {
		if ( defined( 'DJINN_SITE_TOKEN' ) && DJINN_SITE_TOKEN ) {
			return (string) DJINN_SITE_TOKEN;
		}
		return self::all()['site_token'];
	}

	/** Base URL of the hosted proxy (no trailing /v1). */
	public static function proxyUrl(): string {
		if ( defined( 'DJINN_PROXY_URL' ) && DJINN_PROXY_URL ) {
			return rtrim( (string) DJINN_PROXY_URL, '/' );
		}
		return 'https://proxy.djinn.app';
	}

	public static function chatModel(): string {
		$model = self::all()['chat_model'];
		if ( $model ) {
			return $model;
		}
		return self::provider() === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4o';
	}

	public static function embeddingModel(): string {
		$model = self::all()['embedding_model'];
		if ( $model ) {
			return $model;
		}
		return self::provider() === 'gemini' ? 'gemini-embedding-001' : 'text-embedding-3-small';
	}

	public static function isConfigured(): bool {
		return self::usesProxy() ? self::siteToken() !== '' : self::apiKey() !== '';
	}

	public static function register(): void {
		register_setting(
			'djinn',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	/** @param mixed $input */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : [];
		$current = self::all();

		$provider = isset( $input['provider'] ) && in_array( $input['provider'], [ 'openai', 'gemini', 'proxy' ], true )
			? $input['provider']
			: $current['provider'];

		// Keep existing secrets if a field is submitted empty (avoids wiping on every save).
		$api_key = isset( $input['api_key'] ) && trim( (string) $input['api_key'] ) !== ''
			? trim( (string) $input['api_key'] )
			: $current['api_key'];

		$site_token = isset( $input['site_token'] ) && trim( (string) $input['site_token'] ) !== ''
			? trim( (string) $input['site_token'] )
			: $current['site_token'];

		return [
			'provider'        => $provider,
			'api_key'         => $api_key,
			'site_token'      => $site_token,
			'chat_model'      => isset( $input['chat_model'] ) ? sanitize_text_field( (string) $input['chat_model'] ) : '',
			'embedding_model' => isset( $input['embedding_model'] ) ? sanitize_text_field( (string) $input['embedding_model'] ) : '',
		];
	}
}
