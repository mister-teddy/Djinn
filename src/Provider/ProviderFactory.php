<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Settings;
use RuntimeException;

class ProviderFactory {

	public static function make(): Provider {
		if ( ! Settings::isConfigured() ) {
			$msg = Settings::usesProxy()
				? 'Connect your Djinn account under Djinn → Settings to start wishing.'
				: 'The lamp is empty — add an API key under Djinn → Settings.';
			throw new RuntimeException( $msg );
		}

		// The proxy picks the model server-side; a bring-your-own-key provider needs one chosen.
		if ( ! Settings::usesProxy() && Settings::chatModel() === '' ) {
			throw new RuntimeException( 'Choose a chat model under Djinn → Settings before making a wish.' );
		}

		switch ( Settings::provider() ) {
			case 'proxy':
				return new ProxyProvider( Settings::siteToken(), Settings::proxyUrl() );
			case 'gemini':
				return new GeminiProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
			case 'openai':
			default:
				return new OpenAIProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
		}
	}
}
