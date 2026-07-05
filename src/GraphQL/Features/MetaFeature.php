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
 * site notes in the system prompt (SystemPrompt::siteNotes), so the Djinn learns what a site
 * exposes without a round-trip query. Meta writes refuse protected keys.
 */
class MetaFeature implements Feature {

	public function register( Registry $r ): void {
		$meta = new ObjectType(
			array(
				'name'        => 'MetaEntry',
				'description' => 'A single post-meta (custom field) key/value. Plugins store much of their data here.',
				'fields'      => array(
					'key'       => array( 'type' => Type::string() ),
					'value'     => array(
						'type'        => Type::string(),
						'description' => 'Scalar as-is; arrays/objects JSON-encoded.',
					),
					'protected' => array(
						'type'        => Type::boolean(),
						'description' => 'Protected (internal) meta — readable here but not writable via setPostMeta.',
					),
				),
			)
		);
		$r->setType( 'MetaEntry', $meta );

		$r->addQuery(
			'postMeta',
			array(
				'type'        => Type::listOf( $meta ),
				'description' => 'Read a post\'s custom fields (post meta). Optionally filter to one key. Works for any post type.',
				'args'        => array(
					'postId' => array( 'type' => Type::nonNull( Type::id() ) ),
					'key'    => array( 'type' => Type::string() ),
				),
				'resolve'     => array( $this, 'postMeta' ),
			)
		);

		$r->addMutation(
			'setPostMeta',
			array(
				'type'        => Type::boolean(),
				'description' => 'Set a custom field (post meta) on a post. Refuses protected (underscore/internal) keys.',
				'args'        => array(
					'postId' => array( 'type' => Type::nonNull( Type::id() ) ),
					'key'    => array( 'type' => Type::nonNull( Type::string() ) ),
					'value'  => array(
						'type'        => Type::nonNull( Type::string() ),
						'description' => 'Stored as-is; pass JSON for non-scalar values.',
					),
				),
				'resolve'     => array( $this, 'setPostMeta' ),
			)
		);
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
		$out = array();
		foreach ( get_post_meta( $id ) as $k => $values ) {
			if ( $key !== null && $k !== $key ) {
				continue;
			}
			foreach ( (array) $values as $v ) {
				$out[] = array(
					'key'       => (string) $k,
					'value'     => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ),
					'protected' => is_protected_meta( (string) $k, 'post' ),
				);
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
