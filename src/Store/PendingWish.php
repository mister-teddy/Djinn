<?php

declare( strict_types=1 );

namespace Djinn\Store;

/**
 * Shared helpers for the persisted "wish awaiting grant" shape. The same row is rendered in REST
 * responses, transcript cards, and tool-result replay, so all display/execution normalization lives
 * here instead of drifting across AgentLoop and Transcript.
 */
class PendingWish {

	public const KIND_GRAPHQL = 'graphql';
	public const KIND_REST    = 'rest';

	public const STATUS_PENDING   = 'pending';
	public const STATUS_RESOLVING = 'resolving';
	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_CANCELLED = 'cancelled';

	private const REST_WRITE_METHODS = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function normalizeRow( array $row ): array {
		$variables = $row['variables'] ?? array();
		if ( is_string( $variables ) ) {
			$decoded   = json_decode( $variables, true );
			$variables = is_array( $decoded ) ? $decoded : array();
		}
		$row['variables'] = is_array( $variables ) ? $variables : array();
		$row['kind']      = self::kind( $row );
		return $row;
	}

	/**
	 * The tool name that must be echoed back when the pending wish is resolved.
	 *
	 * @param array<string,mixed> $pending
	 */
	public static function toolName( array $pending ): string {
		return self::kind( $pending ) === self::KIND_REST ? 'rest_call' : 'run_graphql';
	}

	public static function isRestWrite( string $method ): bool {
		return in_array( strtoupper( $method ), self::REST_WRITE_METHODS, true );
	}

	/**
	 * @param array<string,mixed> $body
	 * @param array<string,mixed> $params
	 * @return array{method:string,body:array<string,mixed>,params:array<string,mixed>}
	 */
	public static function restVariables( string $method, array $body, array $params ): array {
		return array(
			'method' => strtoupper( $method ),
			'body'   => $body,
			'params' => $params,
		);
	}

	/**
	 * @param array<string,mixed> $pending
	 * @return array{kind:string,summary:string,operation:string,variables:array<string,mixed>}
	 */
	public static function displayPayload( array $pending ): array {
		return array(
			'kind'      => self::kind( $pending ),
			'summary'   => (string) ( $pending['summary'] ?? '' ),
			'operation' => self::displayOperation( $pending ),
			'variables' => self::displayVariables( $pending ),
		);
	}

	/**
	 * @param array<string,mixed> $pending
	 * @return array<string,mixed>
	 */
	public static function response( int $chatId, array $pending ): array {
		return array(
			'status'  => 'awaiting_confirmation',
			'chat_id' => $chatId,
			'pending' => array_merge(
				array( 'id' => (int) ( $pending['id'] ?? 0 ) ),
				self::displayPayload( $pending )
			),
		);
	}

	/**
	 * @param array<string,mixed> $pending
	 * @return array<string,mixed>
	 */
	public static function transcriptEntry( array $pending ): array {
		return array_merge(
			array(
				'role'       => 'pending',
				'pending_id' => (int) ( $pending['id'] ?? 0 ),
			),
			self::displayPayload( $pending )
		);
	}

	/**
	 * @param array<string,mixed> $pending
	 */
	public static function displayOperation( array $pending ): string {
		if ( self::kind( $pending ) !== self::KIND_REST ) {
			return (string) ( $pending['operation'] ?? '' );
		}
		$v      = (array) ( $pending['variables'] ?? array() );
		$method = strtoupper( (string) ( $v['method'] ?? 'GET' ) );
		$path   = (string) ( $pending['operation'] ?? '' );
		return trim( $method . ' ' . $path );
	}

	/**
	 * @param array<string,mixed> $pending
	 * @return array<string,mixed>
	 */
	public static function displayVariables( array $pending ): array {
		$variables = (array) ( $pending['variables'] ?? array() );
		if ( self::kind( $pending ) !== self::KIND_REST ) {
			return $variables;
		}

		$out    = array();
		$body   = (array) ( $variables['body'] ?? array() );
		$params = (array) ( $variables['params'] ?? array() );
		if ( $body ) {
			$out['body'] = $body;
		}
		if ( $params ) {
			$out['params'] = $params;
		}
		return $out;
	}

	/** @param array<string,mixed> $pending */
	private static function kind( array $pending ): string {
		return ( $pending['kind'] ?? self::KIND_GRAPHQL ) === self::KIND_REST ? self::KIND_REST : self::KIND_GRAPHQL;
	}
}
