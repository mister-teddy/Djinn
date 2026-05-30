<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Usage\Pricing;

/**
 * Discovers which models a provider key can actually use, so Settings can offer a dropdown
 * instead of error-prone free text. Providers expose capabilities (not prices) via their list
 * endpoints; we map those to Djinn's two roles — chat (generation) and embed.
 *
 * Results are cached per provider+key for a few hours. If discovery fails (bad key, network),
 * we fall back to the priced models we know about so the dropdown is never empty.
 */
class ModelCatalog {

	private const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * @return array{chat:array<int,string>,embed:array<int,string>,error:?string,live:bool}
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

		$result = $provider === 'gemini'
			? self::discoverGemini( $apiKey )
			: self::discoverOpenAI( $apiKey );

		set_transient( $cacheKey, $result, self::TTL );
		return $result;
	}

	/** Drop cached catalogs so the next Settings render re-discovers. */
	public static function flush(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_djinn_models_%' OR option_name LIKE '_transient_timeout_djinn_models_%'" );
	}

	/** @return array{chat:array<int,string>,embed:array<int,string>,error:?string,live:bool} */
	private static function discoverGemini( string $apiKey ): array {
		$url      = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $apiKey ) . '&pageSize=200';
		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $response ) ) {
			return self::fallback( 'gemini', 'Could not reach Gemini: ' . $response->get_error_message() );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( isset( $json['error'] ) ) {
			return self::fallback( 'gemini', 'Gemini rejected the key: ' . ( $json['error']['message'] ?? 'unknown error' ) );
		}

		$chat  = [];
		$embed = [];
		foreach ( $json['models'] ?? [] as $model ) {
			$id      = preg_replace( '#^models/#', '', (string) ( $model['name'] ?? '' ) );
			$methods = (array) ( $model['supportedGenerationMethods'] ?? [] );
			if ( $id === '' ) {
				continue;
			}
			if ( in_array( 'embedContent', $methods, true ) ) {
				$embed[] = $id;
			} elseif ( in_array( 'generateContent', $methods, true ) && ! preg_match( '/tts|image|vision|audio|aqa/i', $id ) ) {
				$chat[] = $id;
			}
		}
		return self::finish( $chat, $embed );
	}

	/** @return array{chat:array<int,string>,embed:array<int,string>,error:?string,live:bool} */
	private static function discoverOpenAI( string $apiKey ): array {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			[ 'timeout' => 20, 'headers' => [ 'Authorization' => 'Bearer ' . $apiKey ] ]
		);
		if ( is_wp_error( $response ) ) {
			return self::fallback( 'openai', 'Could not reach OpenAI: ' . $response->get_error_message() );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( isset( $json['error'] ) ) {
			return self::fallback( 'openai', 'OpenAI rejected the key: ' . ( $json['error']['message'] ?? 'unknown error' ) );
		}

		$chat  = [];
		$embed = [];
		foreach ( $json['data'] ?? [] as $model ) {
			$id = (string) ( $model['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			if ( str_contains( $id, 'embedding' ) ) {
				$embed[] = $id;
			} elseif ( preg_match( '/^(gpt-|o[0-9]|chatgpt)/', $id ) && ! preg_match( '/audio|realtime|transcribe|tts|image|search|moderation/i', $id ) ) {
				$chat[] = $id;
			}
		}
		return self::finish( $chat, $embed );
	}

	/**
	 * Normalise a discovered list: dedupe, then sort priced models first (alphabetically),
	 * unpriced ones after — so the familiar, cost-known options surface at the top.
	 *
	 * @param array<int,string> $chat
	 * @param array<int,string> $embed
	 * @return array{chat:array<int,string>,embed:array<int,string>,error:?string,live:bool}
	 */
	private static function finish( array $chat, array $embed ): array {
		return [
			'chat'  => self::order( $chat ),
			'embed' => self::order( $embed ),
			'error' => null,
			'live'  => true,
		];
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
	 * Offline fallback: the models we have prices for, split by role (embedding models are the
	 * ones with no output price). Keeps the dropdown usable when discovery fails.
	 *
	 * @return array{chat:array<int,string>,embed:array<int,string>,error:?string,live:bool}
	 */
	private static function fallback( string $provider, string $error ): array {
		$prefix = $provider === 'gemini' ? [ 'gemini', 'gemma' ] : [ 'gpt', 'o1', 'o3', 'o4', 'chatgpt', 'text-embedding' ];
		$chat   = [];
		$embed  = [];
		foreach ( Pricing::table() as $model => $rates ) {
			$matches = false;
			foreach ( $prefix as $p ) {
				if ( str_starts_with( $model, $p ) ) {
					$matches = true;
					break;
				}
			}
			if ( ! $matches ) {
				continue;
			}
			if ( str_contains( $model, 'embedding' ) ) {
				$embed[] = $model;
			} else {
				$chat[] = $model;
			}
		}
		return [
			'chat'  => self::order( $chat ),
			'embed' => self::order( $embed ),
			'error' => $error,
			'live'  => false,
		];
	}
}
