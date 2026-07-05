<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Usage\Pricing;

/**
 * Discovers which chat models a provider key can actually use, so Settings can offer a dropdown
 * instead of error-prone free text. Providers expose capabilities (not prices) via their list
 * endpoints.
 *
 * Results are cached per provider+key for a few hours. If discovery fails (bad key, network),
 * we fall back to the priced models we know about so the dropdown is never empty.
 */
class ModelCatalog {

	private const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * @return array{chat:array<int,string>,error:?string,live:bool}
	 */
	public static function forProvider( string $provider, string $apiKey ): array {
		if ( $apiKey === '' ) {
			return self::fallback( $provider, 'No API key yet — save a key to load the model list.' );
		}

		$cacheKey = 'djinn_models_' . $provider . '_' . md5( $apiKey );
		$cached   = get_transient( $cacheKey );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		switch ( Providers::family( $provider ) ) {
			case 'gemini':
				$result = self::discoverGemini( $apiKey );
				break;
			case 'anthropic':
				$result = self::discoverAnthropic( $apiKey, $provider === 'claude-max' );
				break;
			default:
				$result = self::discoverOpenAI( $apiKey );
				break;
		}

		set_transient( $cacheKey, $result, self::TTL );
		return $result;
	}

	/** Drop cached catalogs so the next Settings render re-discovers. */
	public static function flush(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_djinn_models_%' OR option_name LIKE '_transient_timeout_djinn_models_%'" );
	}

	/**
	 * Anthropic models via GET /v1/models. For claude-max the key is an OAuth token (Bearer); for
	 * anthropic it's an x-api-key.
	 *
	 * @return array{chat:array<int,string>,error:?string,live:bool}
	 */
	private static function discoverAnthropic( string $apiKey, bool $oauth ): array {
		$headers  = $oauth
			? array(
				'Authorization'     => 'Bearer ' . $apiKey,
				'anthropic-version' => '2023-06-01',
				'anthropic-beta'    => 'oauth-2025-04-20',
			)
			: array(
				'x-api-key'         => $apiKey,
				'anthropic-version' => '2023-06-01',
			);
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models?limit=100',
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::fallback( 'anthropic', 'Could not reach Anthropic: ' . $response->get_error_message() );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( isset( $json['error'] ) ) {
			return self::fallback( 'anthropic', 'Anthropic rejected the key/token: ' . ( $json['error']['message'] ?? 'unknown error' ) );
		}
		$chat = array();
		foreach ( $json['data'] ?? array() as $model ) {
			$id = (string) ( $model['id'] ?? '' );
			if ( $id !== '' ) {
				$chat[] = $id;
			}
		}
		return self::finish( $chat );
	}

	/** @return array{chat:array<int,string>,error:?string,live:bool} */
	private static function discoverGemini( string $apiKey ): array {
		$url      = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $apiKey ) . '&pageSize=200';
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return self::fallback( 'gemini', 'Could not reach Gemini: ' . $response->get_error_message() );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( isset( $json['error'] ) ) {
			return self::fallback( 'gemini', 'Gemini rejected the key: ' . ( $json['error']['message'] ?? 'unknown error' ) );
		}

		$chat = array();
		foreach ( $json['models'] ?? array() as $model ) {
			$id      = preg_replace( '#^models/#', '', (string) ( $model['name'] ?? '' ) );
			$methods = (array) ( $model['supportedGenerationMethods'] ?? array() );
			if ( $id === '' ) {
				continue;
			}
			if ( in_array( 'generateContent', $methods, true ) && ! preg_match( '/embedding|tts|image|vision|audio|aqa/i', $id ) ) {
				$chat[] = $id;
			}
		}
		return self::finish( $chat );
	}

	/** @return array{chat:array<int,string>,error:?string,live:bool} */
	private static function discoverOpenAI( string $apiKey ): array {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $apiKey ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::fallback( 'openai', 'Could not reach OpenAI: ' . $response->get_error_message() );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( isset( $json['error'] ) ) {
			return self::fallback( 'openai', 'OpenAI rejected the key: ' . ( $json['error']['message'] ?? 'unknown error' ) );
		}

		$chat = array();
		foreach ( $json['data'] ?? array() as $model ) {
			$id = (string) ( $model['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			if ( preg_match( '/^(gpt-|o[0-9]|chatgpt)/', $id ) && ! preg_match( '/embedding|audio|realtime|transcribe|tts|image|search|moderation/i', $id ) ) {
				$chat[] = $id;
			}
		}
		return self::finish( $chat );
	}

	/**
	 * Normalise a discovered list: dedupe, then sort priced models first (alphabetically),
	 * unpriced ones after — so the familiar, cost-known options surface at the top.
	 *
	 * @param array<int,string> $chat
	 * @return array{chat:array<int,string>,error:?string,live:bool}
	 */
	private static function finish( array $chat ): array {
		return array(
			'chat'  => self::order( $chat ),
			'error' => null,
			'live'  => true,
		);
	}

	/**
	 * Classify a chat model by how well it handles Djinn's multi-step tool loop, so Settings can
	 * group the dropdown. Heuristic and name-based — distilled/tiny variants (lite, 8b, nano, gemma,
	 * 3.5) stumble on chained tool calls; large reasoners (pro, gpt-4o/4.1/5, o-series, flash) cope.
	 *
	 * @return 'recommended'|'standard'|'limited'
	 */
	public static function chatTier( string $model ): string {
		$m = strtolower( $model );
		if ( preg_match( '/lite|flash-8b|-8b|gemma|nano|gpt-3\.5/', $m ) ) {
			return 'limited';
		}
		if ( preg_match( '/mini/', $m ) ) {
			return 'standard';
		}
		if ( preg_match( '/pro|gpt-4o|gpt-4\.1|gpt-5|^o[0-9]|flash/', $m ) ) {
			return 'recommended';
		}
		return 'standard';
	}

	/**
	 * @param array<int,string> $models
	 * @return array<int,string>
	 */
	private static function order( array $models ): array {
		$models = array_values( array_unique( $models ) );
		usort(
			$models,
			static function ( $a, $b ) {
				$ka = Pricing::isKnown( $a ) ? 0 : 1;
				$kb = Pricing::isKnown( $b ) ? 0 : 1;
				return $ka === $kb ? strcmp( $a, $b ) : $ka - $kb;
			}
		);
		return $models;
	}

	/**
	 * Offline fallback: the models we have prices for. Keeps the dropdown usable when discovery
	 * fails.
	 *
	 * @return array{chat:array<int,string>,error:?string,live:bool}
	 */
	private static function fallback( string $provider, string $error ): array {
		$prefixes = array(
			'gemini'    => array( 'gemini', 'gemma' ),
			'anthropic' => array( 'claude' ),
			'openai'    => array( 'gpt', 'o1', 'o3', 'o4', 'chatgpt' ),
		);
		$prefix   = $prefixes[ Providers::family( $provider ) ] ?? $prefixes['openai'];
		$chat     = array();
		foreach ( Pricing::table() as $model => $rates ) {
			foreach ( $prefix as $p ) {
				if ( str_starts_with( $model, $p ) ) {
					$chat[] = $model;
					break;
				}
			}
		}
		return array(
			'chat'  => self::order( $chat ),
			'error' => $error,
			'live'  => false,
		);
	}
}
