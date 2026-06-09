<?php

declare( strict_types=1 );

namespace Djinn\Provider;

final class Providers {

	/**
	 * @return array<string,array{label:string,class:class-string<Provider>,needsKey:bool,family:string,embeddings:bool,description:string,keyHint:string}>
	 */
	public static function all(): array {
		return [
			'openai'     => [ 'label' => 'OpenAI (your key)',                      'class' => OpenAIProvider::class,    'needsKey' => true,  'family' => 'openai',    'embeddings' => true,  'description' => 'Use your own OpenAI API key.',                                          'keyHint' => 'Paste your OpenAI key (sk-…), or define DJINN_API_KEY in wp-config.php.' ],
			'gemini'     => [ 'label' => 'Google Gemini (your key)',               'class' => GeminiProvider::class,    'needsKey' => true,  'family' => 'gemini',    'embeddings' => true,  'description' => 'Use your own Google Gemini API key.',                                   'keyHint' => 'Paste your Google AI Studio key (AIza…), or define DJINN_API_KEY in wp-config.php.' ],
			'anthropic'  => [ 'label' => 'Anthropic Claude (your key)',            'class' => AnthropicProvider::class, 'needsKey' => true,  'family' => 'anthropic', 'embeddings' => false, 'description' => 'Use your own Anthropic API key. No embeddings — schema search runs on the full schema.', 'keyHint' => 'Paste your Anthropic key (sk-ant-…), or define DJINN_API_KEY in wp-config.php.' ],
			'claude-max' => [ 'label' => 'Claude Max subscription — experimental', 'class' => ClaudeMaxProvider::class, 'needsKey' => true,  'family' => 'anthropic', 'embeddings' => false, 'description' => 'Use your Claude Max subscription. Experimental.',                       'keyHint' => 'Paste your key, or define DJINN_API_KEY in wp-config.php.' ],
			'proxy'      => [ 'label' => 'Djinn key',                              'class' => ProxyProvider::class,     'needsKey' => false, 'family' => 'openai',    'embeddings' => true,  'description' => 'Managed by Djinn — registered automatically by site, no key to paste. Prepaid credit via Polar.', 'keyHint' => '' ],
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

	public static function adapterClass( string $id ): string {
		return self::all()[ $id ]['class'] ?? OpenAIProvider::class;
	}

	/**
	 * @return array<int,array{value:string,label:string,needsKey:bool,embeddings:bool,description:string,keyHint:string}>
	 */
	public static function forClient(): array {
		$out = [];
		foreach ( self::all() as $id => $p ) {
			$out[] = [
				'value'       => $id,
				'label'       => $p['label'],
				'needsKey'    => $p['needsKey'],
				'embeddings'  => $p['embeddings'],
				'description' => $p['description'],
				'keyHint'     => $p['keyHint'],
			];
		}
		return $out;
	}
}
