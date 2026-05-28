<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Settings;
use RuntimeException;

class ProviderFactory {

	public static function make(): Provider {
		if ( ! Settings::isConfigured() ) {
			throw new RuntimeException( 'The lamp is empty — add an API key under Djinn → Settings.' );
		}

		switch ( Settings::provider() ) {
			case 'gemini':
				return new GeminiProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
			case 'openai':
			default:
				return new OpenAIProvider( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
		}
	}
}
