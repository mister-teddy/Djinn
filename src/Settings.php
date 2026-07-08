<?php

declare( strict_types=1 );

namespace Djinn;

use Djinn\Provider\Providers;

/**
 * Plugin settings: edition, which LLM provider to use, the API key / proxy token, and model names.
 * Stored in a single option array; secrets are never sent back to the client.
 *
 * Editions (DJINN_EDITION, stamped at build time, default 'free'):
 *   - 'free' — WordPress.org build: any provider (BYO key or the managed proxy), content-only writes.
 *   - 'pro'  — paid build: full schema scope, unlocked by a valid Polar license key.
 * The edition gates capability scope only; every provider is available in both.
 */
class Settings {

	private const OPTION = 'djinn_settings';

	public static function edition(): string {
		return defined( 'DJINN_EDITION' ) && DJINN_EDITION === 'pro' ? 'pro' : 'free';
	}

	/** A licensed Pro build. The free build and an unlicensed Pro build both report false. */
	public static function isPro(): bool {
		return self::edition() === 'pro' && \Djinn\License\LicenseClient::active();
	}

	/** @return array{provider:string,api_key:string,site_token:string,chat_model:string} */
	public static function all(): array {
		$defaults = array(
			'provider'   => 'proxy',
			'api_key'    => '',
			'site_token' => '',
			'chat_model' => '',
		);
		$stored   = get_option( self::OPTION, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/** The effective provider (default proxy; 'proxy' routes through the hosted gateway). */
	public static function provider(): string {
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

	/** Persist a settings payload through the canonical sanitizer (same path as the options.php form). */
	public static function update( array $input ): void {
		update_option( self::OPTION, self::sanitize( $input ) );
	}

	/** Persist the per-site proxy token (used by automatic ORG site registration). */
	public static function storeSiteToken( string $token ): void {
		$stored               = get_option( self::OPTION, array() );
		$stored               = is_array( $stored ) ? $stored : array();
		$stored['site_token'] = $token;
		update_option( self::OPTION, $stored );
	}

	/** Drop the cached option so a value written in another request (e.g. the pairing claim callback) reads fresh. */
	public static function flushCache(): void {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/** Base URL of the hosted proxy (no trailing /v1). */
	public static function proxyUrl(): string {
		if ( defined( 'DJINN_PROXY_URL' ) && DJINN_PROXY_URL ) {
			return rtrim( (string) DJINN_PROXY_URL, '/' );
		}
		return 'https://djinn-proxy-351601184057.asia-northeast1.run.app';
	}

	/** Polar checkout URL for the Pro upgrade. Baked at build (DJINN_PRO_URL); defaults to the live link. */
	public static function proUrl(): string {
		if ( defined( 'DJINN_PRO_URL' ) && DJINN_PRO_URL ) {
			return (string) DJINN_PRO_URL;
		}
		return 'https://buy.polar.sh/polar_cl_DGwSeP4nDmqeEXZLw4vC6RFkEBP7frjlGPU3u2768kC';
	}

	/** The chosen chat model, or '' if none is set. No fallback: the user must pick one explicitly. */
	public static function chatModel(): string {
		return self::all()['chat_model'];
	}

	public static function isConfigured(): bool {
		return self::usesProxy() ? self::siteToken() !== '' : self::apiKey() !== '';
	}

	public static function register(): void {
		register_setting(
			'djinn',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/** @param mixed $input */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();

		$provider = isset( $input['provider'] ) && is_string( $input['provider'] ) && Providers::has( $input['provider'] )
			? $input['provider']
			: $current['provider'];

		// Keep existing secrets if a field is submitted empty (avoids wiping on every save).
		$api_key = isset( $input['api_key'] ) && trim( (string) $input['api_key'] ) !== ''
			? trim( (string) $input['api_key'] )
			: $current['api_key'];

		$site_token = isset( $input['site_token'] ) && trim( (string) $input['site_token'] ) !== ''
			? trim( (string) $input['site_token'] )
			: $current['site_token'];

		return array(
			'provider'   => $provider,
			'api_key'    => $api_key,
			'site_token' => $site_token,
			'chat_model' => isset( $input['chat_model'] ) ? sanitize_text_field( (string) $input['chat_model'] ) : '',
		);
	}
}
