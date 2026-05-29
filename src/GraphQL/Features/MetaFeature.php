<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Generic custom-field (post meta) access. Plugins store much of their per-post data here, so this
 * reaches them with no plugin cooperation. Post-type/taxonomy *discovery* is not here — it lives as
 * synthetic chunks in the RAG index (Rag\Indexer::siteChunks), so the Djinn learns what a site
 * exposes through search_schema rather than a round-trip query. Meta writes refuse protected keys.
 */
class MetaFeature implements Feature {

	public function register( Registry $r ): void {
		$meta = new ObjectType(
			[
				'name'        => 'MetaEntry',
				'description' => 'A single post-meta (custom field) key/value. Plugins store much of their data here.',
				'fields'      => [
					'key'       => [ 'type' => Type::string() ],
					'value'     => [ 'type' => Type::string(), 'description' => 'Scalar as-is; arrays/objects JSON-encoded.' ],
					'protected' => [ 'type' => Type::boolean(), 'description' => 'Protected (internal) meta — readable here but not writable via setPostMeta.' ],
				],
			]
		);
		$r->setType( 'MetaEntry', $meta );

		$r->addQuery( 'postMeta', [
			'type'        => Type::listOf( $meta ),
			'description' => 'Read a post\'s custom fields (post meta). Optionally filter to one key. Works for any post type.',
			'args'        => [
				'postId' => [ 'type' => Type::nonNull( Type::id() ) ],
				'key'    => [ 'type' => Type::string() ],
			],
			'resolve'     => [ $this, 'postMeta' ],
		] );

		$r->addMutation( 'setPostMeta', [
			'type'        => Type::boolean(),
			'description' => 'Set a custom field (post meta) on a post. Refuses protected (underscore/internal) keys.',
			'args'        => [
				'postId' => [ 'type' => Type::nonNull( Type::id() ) ],
				'key'    => [ 'type' => Type::nonNull( Type::string() ) ],
				'value'  => [ 'type' => Type::nonNull( Type::string() ), 'description' => 'Stored as-is; pass JSON for non-scalar values.' ],
			],
			'resolve'     => [ $this, 'setPostMeta' ],
		] );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	public function postMeta( $root, array $args ): array {
		$id = (int) $args['postId'];
		if ( ! get_post( $id ) ) {
			throw new UserError( "No post with id $id." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new UserError( 'You do not have permission to read this post\'s custom fields.' );
		}
		$key = isset( $args['key'] ) ? (string) $args['key'] : null;
		$out = [];
		foreach ( get_post_meta( $id ) as $k => $values ) {
			if ( $key !== null && $k !== $key ) {
				continue;
			}
			foreach ( (array) $values as $v ) {
				$out[] = [
					'key'       => (string) $k,
					'value'     => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ),
					'protected' => is_protected_meta( (string) $k, 'post' ),
				];
			}
		}
		return $out;
	}

	/** @param array<string,mixed> $args */
	public function setPostMeta( $root, array $args ): bool {
		$id  = (int) $args['postId'];
		$key = (string) $args['key'];
		if ( ! get_post( $id ) ) {
			throw new UserError( "No post with id $id." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new UserError( 'You do not have permission to edit this post.' );
		}
		if ( is_protected_meta( $key, 'post' ) ) {
			throw new UserError( "'$key' is protected meta and cannot be set this way." );
		}
		return (bool) update_post_meta( $id, $key, (string) $args['value'] );
	}
}
