<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use RuntimeException;

/**
 * Thin JSON-over-HTTP helper built on the WordPress HTTP API (works on any host).
 */
trait Http {

	/**
	 * @param array<string,string> $headers
	 * @param array<string,mixed>  $body
	 * @return array<string,mixed>
	 */
	protected function postJson( string $url, array $headers, array $body ): array {
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 60,
				'headers' => array_merge( [ 'Content-Type' => 'application/json' ], $headers ),
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'LLM request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && isset( $json['error']['message'] )
				? $json['error']['message']
				: substr( $raw, 0, 300 );
			throw new RuntimeException( "LLM request returned HTTP $code: $msg" );
		}

		if ( ! is_array( $json ) ) {
			throw new RuntimeException( 'LLM returned a non-JSON response.' );
		}
		return $json;
	}
}
