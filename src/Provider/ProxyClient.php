<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Settings;

/**
 * Minimal GraphQL client for the hosted proxy's site API (`POST /graphql`): account/systemPrompt
 * queries and the register/billingCheckout mutations. This speaks to the *proxy* over HTTP — it is
 * unrelated to the plugin's own admin GraphQL endpoint (webonyx, served from this site).
 */
class ProxyClient {

	/**
	 * Run a GraphQL document against the proxy and return its `data` payload. Pass the site token to
	 * authenticate (Bearer); omit it for the public `register` mutation.
	 *
	 * @param array<string,mixed> $variables
	 * @return array<string,mixed>
	 * @throws ProxyException on transport failure or a GraphQL error.
	 */
	public static function call( string $query, array $variables = array(), ?string $token = null, int $timeout = 20 ): array {
		$headers = array( 'content-type' => 'application/json' );
		if ( $token !== null && $token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$res = wp_remote_post(
			Settings::proxyUrl() . '/graphql',
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => (object) $variables,
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new ProxyException( 'Could not reach the Djinn service.', true );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) {
			throw new ProxyException( esc_html( 'The Djinn service returned an invalid response.' ) );
		}
		if ( ! empty( $json['errors'] ) ) {
			$msg = $json['errors'][0]['message'] ?? 'The Djinn service returned an error.';
			throw new ProxyException( esc_html( (string) $msg ) );
		}
		return is_array( $json['data'] ?? null ) ? $json['data'] : array();
	}
}
