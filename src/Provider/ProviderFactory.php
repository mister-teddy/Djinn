<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Settings;
use RuntimeException;

class ProviderFactory {

	public static function make(): Provider {
		if ( ! Settings::isConfigured() ) {
			$msg = Settings::usesProxy()
				? 'Connect your Djinn account in the Account tile of Djinn → Cave of Wonders to start wishing.'
				: 'The lamp is empty — add an API key in the Account tile of Djinn → Cave of Wonders.';
			throw new RuntimeException( $msg );
		}

		// The proxy picks the model server-side; a bring-your-own-key provider needs one chosen.
		if ( ! Settings::usesProxy() && Settings::chatModel() === '' ) {
			throw new RuntimeException( 'Choose a chat model in the Account tile of Djinn → Cave of Wonders before making a wish.' );
		}

		switch ( Settings::provider() ) {
			case 'proxy':
				return new ProxyProvider( Settings::siteToken(), Settings::proxyUrl() );
			case 'gemini':
				return new GeminiProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
			case 'anthropic':
				return new AnthropicProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
			case 'claude-max':
				return new ClaudeMaxProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
			case 'openai':
			default:
				return new OpenAIProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
		}
	}
}
