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

		// The proxy takes a site token + URL; every key-backed adapter takes (key, chat, embed) and is
		// resolved from the provider registry.
		$provider = Settings::provider();
		if ( $provider === 'proxy' ) {
			return new ProxyProvider( Settings::siteToken(), Settings::proxyUrl() );
		}
		$class = Providers::adapterClass( $provider );
		return new $class( Settings::apiKey(), Settings::chatModel(), Settings::embeddingModel() );
	}
}
