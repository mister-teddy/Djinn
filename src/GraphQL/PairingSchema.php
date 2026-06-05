<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

use Djinn\Settings;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * The public pairing schema, served unauthenticated at `POST /wp-json/djinn/v1/claim`. The hosted
 * proxy calls its one mutation during connect() to push this site's token. We store the token only
 * when the pushed nonce matches the secret AdminResolvers::connect() generated and stashed in the
 * pairing transient — so a stranger who knows our domain, or who races the open window, can't get
 * us to store a token they control (which would otherwise redirect our top-ups to them). The echoed
 * nonce also lets the proxy confirm it reached a real, reachable Djinn install.
 *
 * Kept apart from the AdminSchema control plane: that surface is capability-gated, this one is
 * public, and a public operation must never share a schema with privileged ones.
 */
class PairingSchema {

	/** Transient holding the secret pairing nonce connect() expects the proxy to echo on claim. */
	public const PENDING = 'djinn_pending_pairing';

	private static ?Schema $schema = null;

	public static function build(): Schema {
		if ( self::$schema !== null ) {
			return self::$schema;
		}

		$result = new ObjectType( [
			'name'   => 'ClaimResult',
			'fields' => [
				'nonce'   => Type::nonNull( Type::string() ),
				'claimed' => Type::nonNull( Type::boolean() ),
			],
		] );

		$query = new ObjectType( [
			'name'   => 'Query',
			'fields' => [
				// GraphQL requires a non-empty Query type; doubles as a liveness ping.
				'ping' => [ 'type' => Type::nonNull( Type::boolean() ), 'resolve' => static fn() => true ],
			],
		] );

		$mutation = new ObjectType( [
			'name'   => 'Mutation',
			'fields' => [
				'claim' => [
					'type'    => Type::nonNull( $result ),
					'args'    => [
						'nonce' => Type::nonNull( Type::string() ),
						'token' => Type::nonNull( Type::string() ),
					],
					'resolve' => static fn( $root, $args ) => self::claim( (string) $args['nonce'], (string) $args['token'] ),
				],
			],
		] );

		return self::$schema = new Schema( [ 'query' => $query, 'mutation' => $mutation ] );
	}

	/**
	 * Accept the proxy-pushed token only when its nonce matches the open pairing window, then close
	 * the window (single-use). The nonce is echoed back either way so the proxy can confirm it
	 * reached a live Djinn install.
	 *
	 * @return array{nonce:string,claimed:bool}
	 */
	private static function claim( string $nonce, string $token ): array {
		$expected = get_transient( self::PENDING );
		$claimed  = false;
		if ( $token !== '' && is_string( $expected ) && $expected !== '' && hash_equals( $expected, $nonce ) ) {
			Settings::storeSiteToken( $token );
			delete_transient( self::PENDING );
			$claimed = true;
		}
		return [ 'nonce' => $nonce, 'claimed' => $claimed ];
	}
}
