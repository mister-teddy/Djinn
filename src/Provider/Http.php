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
			throw new RuntimeException( "LLM request returned HTTP $code: $msg" . self::modelHint( $code ) );
		}

		if ( ! is_array( $json ) ) {
			throw new RuntimeException( 'LLM returned a non-JSON response.' );
		}
		return $json;
	}

	/**
	 * POST and stream the response body in chunks (for SSE token streaming). The WordPress HTTP
	 * API can't surface a streaming body, so this uses cURL directly; callers should fall back to
	 * postJson() when cURL is unavailable.
	 *
	 * @param array<string,string> $headers
	 * @param array<string,mixed>  $body
	 * @param callable(string):void $onChunk
	 */
	protected function postStream( string $url, array $headers, array $body, callable $onChunk ): void {
		if ( ! function_exists( 'curl_init' ) ) {
			throw new RuntimeException( 'Streaming requires the cURL PHP extension.' );
		}
		$hdr = [ 'Content-Type: application/json' ];
		foreach ( $headers as $k => $v ) {
			$hdr[] = $k . ': ' . $v;
		}
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => $hdr,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => static function ( $ch, $data ) use ( $onChunk ) {
				$onChunk( $data );
				return strlen( $data );
			},
		] );
		$ok   = curl_exec( $ch );
		$err  = curl_error( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $ok === false && $err ) {
			throw new RuntimeException( 'LLM stream failed: ' . $err );
		}
		if ( $code >= 400 ) {
			throw new RuntimeException( "LLM stream returned HTTP $code." . self::modelHint( $code ) );
		}
	}

	/**
	 * A 404 (and often a 400) from a provider almost always means the configured model name is
	 * unknown — usually a retired or mistyped id — so point the user straight at the fix.
	 */
	private static function modelHint( int $code ): string {
		return ( $code === 404 || $code === 400 )
			? ' The selected model looks unavailable or retired — choose a current one in the Account tile of Djinn → Cave of Wonders.'
			: '';
	}
}
