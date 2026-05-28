<?php

declare( strict_types=1 );

namespace Djinn;

/**
 * Plugin settings: which LLM provider to use, the API key, and model names.
 * Stored in a single option array; the API key is never sent back to the client.
 */
class Settings {

	private const OPTION = 'djinn_settings';

	/** @return array{provider:string,api_key:string,chat_model:string,embedding_model:string} */
	public static function all(): array {
		$defaults = [
			'provider'        => 'openai',
			'api_key'         => '',
			'chat_model'      => '',
			'embedding_model' => '',
		];
		$stored = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	}

	public static function provider(): string {
		return self::all()['provider'];
	}

	public static function apiKey(): string {
		// Allow overriding the key with a constant so it never has to live in the DB.
		if ( defined( 'DJINN_API_KEY' ) && DJINN_API_KEY ) {
			return (string) DJINN_API_KEY;
		}
		return self::all()['api_key'];
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
		return self::apiKey() !== '';
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

		$provider = isset( $input['provider'] ) && in_array( $input['provider'], [ 'openai', 'gemini' ], true )
			? $input['provider']
			: $current['provider'];

		// Keep the existing key if the field is submitted empty (avoids wiping it on every save).
		$api_key = isset( $input['api_key'] ) && trim( (string) $input['api_key'] ) !== ''
			? trim( (string) $input['api_key'] )
			: $current['api_key'];

		return [
			'provider'        => $provider,
			'api_key'         => $api_key,
			'chat_model'      => isset( $input['chat_model'] ) ? sanitize_text_field( (string) $input['chat_model'] ) : '',
			'embedding_model' => isset( $input['embedding_model'] ) ? sanitize_text_field( (string) $input['embedding_model'] ) : '',
		];
	}
}
