<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Settings;
use RuntimeException;

class ProviderFactory {

	public static function make(): Provider {
		$provider = Settings::provider();

		if ( ! Settings::isConfigured() ) {
			if ( Settings::usesProxy() ) {
				$msg = 'Connect your Djinn account in the Account tile of Djinn → Cave of Wonders to start wishing.';
			} elseif ( Settings::usesWordPressAIClient() ) {
				$msg = 'Configure the WordPress AI Client with a text model that supports function calling, then try again.';
			} else {
				$msg = 'The lamp is empty — add an API key in the Account tile of Djinn → Cave of Wonders.';
			}
			throw new RuntimeException( esc_html( $msg ) );
		}

		// The proxy and WordPress AI Client pick a site-level model; direct providers need one chosen.
		if ( Providers::needsModel( $provider ) && Settings::chatModel() === '' ) {
			throw new RuntimeException( esc_html( 'Choose a chat model in the Account tile of Djinn → Cave of Wonders before making a wish.' ) );
		}

		// The proxy takes a site token + URL. The WordPress AI Client uses site-level credentials.
		// Every direct adapter takes (key, chat model) and is resolved from the provider registry.
		if ( $provider === 'proxy' ) {
			return new ProxyProvider( Settings::siteToken(), Settings::proxyUrl() );
		}
		if ( $provider === WordPressAIClientProvider::ID ) {
			return new WordPressAIClientProvider();
		}
		$class = Providers::adapterClass( $provider );
		return new $class( Settings::apiKey(), Settings::chatModel() );
	}
}
