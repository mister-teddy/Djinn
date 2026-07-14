<?php

declare( strict_types=1 );

namespace Djinn\Provider;

final class Providers {

	/**
	 * @return array<string,array{label:string,class:class-string<Provider>,needsKey:bool,needsModel:bool,family:string,description:string,keyHint:string}>
	 */
	public static function all(): array {
		$providers = array(
			'proxy'      => array(
				'label'       => 'Djinn',
				'class'       => ProxyProvider::class,
				'needsKey'    => false,
				'needsModel'  => false,
				'family'      => 'openai',
				'description' => 'No config needed - Pay as you go - via Polar.',
				'keyHint'     => '',
			),
			'openai'     => array(
				'label'       => 'OpenAI (your key)',
				'class'       => OpenAIProvider::class,
				'needsKey'    => true,
				'needsModel'  => true,
				'family'      => 'openai',
				'description' => 'Use your own OpenAI API key.',
				'keyHint'     => 'Paste your OpenAI key (sk-…), or define DJINN_API_KEY in wp-config.php.',
			),
			'gemini'     => array(
				'label'       => 'Google Gemini (your key)',
				'class'       => GeminiProvider::class,
				'needsKey'    => true,
				'needsModel'  => true,
				'family'      => 'gemini',
				'description' => 'Use your own Google Gemini API key.',
				'keyHint'     => 'Paste your Google AI Studio key (AIza…), or define DJINN_API_KEY in wp-config.php.',
			),
			'anthropic'  => array(
				'label'       => 'Anthropic Claude (your key)',
				'class'       => AnthropicProvider::class,
				'needsKey'    => true,
				'needsModel'  => true,
				'family'      => 'anthropic',
				'description' => 'Use your own Anthropic API key.',
				'keyHint'     => 'Paste your Anthropic key (sk-ant-…), or define DJINN_API_KEY in wp-config.php.',
			),
			'claude-max' => array(
				'label'       => 'Claude Max subscription — experimental',
				'class'       => ClaudeMaxProvider::class,
				'needsKey'    => true,
				'needsModel'  => true,
				'family'      => 'anthropic',
				'description' => 'Use your Claude Max subscription. Experimental.',
				'keyHint'     => 'Paste your key, or define DJINN_API_KEY in wp-config.php.',
			),
		);

		if ( WordPressAIClientProvider::isAvailable() ) {
			$providers[ WordPressAIClientProvider::ID ] = array(
				'label'       => 'WordPress AI Client',
				'class'       => WordPressAIClientProvider::class,
				'needsKey'    => false,
				'needsModel'  => false,
				'family'      => 'wp-ai-client',
				'description' => 'Use the site-level AI provider configured in WordPress.',
				'keyHint'     => '',
			);
		}

		return $providers;
	}

	/** @return array<int,string> */
	public static function ids(): array {
		return array_keys( self::all() );
	}

	public static function has( string $id ): bool {
		return isset( self::all()[ $id ] );
	}

	public static function needsKey( string $id ): bool {
		return (bool) ( self::all()[ $id ]['needsKey'] ?? false );
	}

	public static function needsModel( string $id ): bool {
		return (bool) ( self::all()[ $id ]['needsModel'] ?? self::needsKey( $id ) );
	}

	public static function family( string $id ): string {
		return self::all()[ $id ]['family'] ?? 'openai';
	}

	public static function adapterClass( string $id ): string {
		return self::all()[ $id ]['class'] ?? OpenAIProvider::class;
	}

	/**
	 * @return array<int,array{value:string,label:string,needsKey:bool,needsModel:bool,description:string,keyHint:string}>
	 */
	public static function forClient(): array {
		$out = array();
		foreach ( self::all() as $id => $p ) {
			$out[] = array(
				'value'       => $id,
				'label'       => $p['label'],
				'needsKey'    => $p['needsKey'],
				'needsModel'  => $p['needsModel'],
				'description' => $p['description'],
				'keyHint'     => $p['keyHint'],
			);
		}
		return $out;
	}
}
