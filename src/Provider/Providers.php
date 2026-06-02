<?php

declare( strict_types=1 );

namespace Djinn\Provider;

/**
 * The one place every provider's identity lives: its dropdown label, adapter class, whether it needs
 * an API key, its API family (for model discovery), and whether it offers embeddings. Settings
 * validation, the factory, model discovery, the index-status check, and the Cave's Account form all
 * read from here, so the set of providers and their traits stay in a single table.
 */
final class Providers {

	/**
	 * @return array<string,array{label:string,class:class-string<Provider>,needsKey:bool,family:string,embeddings:bool}>
	 */
	public static function all(): array {
		return [
			'openai'     => [ 'label' => 'OpenAI (your key)',                      'class' => OpenAIProvider::class,    'needsKey' => true,  'family' => 'openai',    'embeddings' => true ],
			'gemini'     => [ 'label' => 'Google Gemini (your key)',               'class' => GeminiProvider::class,    'needsKey' => true,  'family' => 'gemini',    'embeddings' => true ],
			'anthropic'  => [ 'label' => 'Anthropic Claude (your key)',            'class' => AnthropicProvider::class, 'needsKey' => true,  'family' => 'anthropic', 'embeddings' => false ],
			'claude-max' => [ 'label' => 'Claude Max subscription — experimental', 'class' => ClaudeMaxProvider::class, 'needsKey' => true,  'family' => 'anthropic', 'embeddings' => false ],
			'proxy'      => [ 'label' => 'Djinn proxy (your account)',             'class' => ProxyProvider::class,     'needsKey' => false, 'family' => 'openai',    'embeddings' => true ],
		];
	}

	/** @return array<int,string> */
	public static function ids(): array {
		return array_keys( self::all() );
	}

	public static function has( string $id ): bool {
		return isset( self::all()[ $id ] );
	}

	public static function family( string $id ): string {
		return self::all()[ $id ]['family'] ?? 'openai';
	}

	public static function hasEmbeddings( string $id ): bool {
		return self::all()[ $id ]['embeddings'] ?? true;
	}

	/** The adapter class for a provider, or the OpenAI adapter for an unknown id. */
	public static function adapterClass( string $id ): string {
		return self::all()[ $id ]['class'] ?? OpenAIProvider::class;
	}

	/**
	 * Provider metadata the Cave's Account form needs: dropdown options, which need an API key (so it
	 * knows when to discover models), and which embed (so it knows when to show the embedding field).
	 *
	 * @return array<int,array{value:string,label:string,needsKey:bool,embeddings:bool}>
	 */
	public static function forClient(): array {
		$out = [];
		foreach ( self::all() as $id => $p ) {
			$out[] = [ 'value' => $id, 'label' => $p['label'], 'needsKey' => $p['needsKey'], 'embeddings' => $p['embeddings'] ];
		}
		return $out;
	}
}
