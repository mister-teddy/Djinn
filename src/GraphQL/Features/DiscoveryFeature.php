<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Tier-1 self-discovery: lets the Djinn learn what a *specific* site exposes at runtime — the
 * custom post types, taxonomies, and post meta that plugins register through WordPress's standard
 * registries. The core resolvers (posts/createPost, terms) are already generic over postType and
 * taxonomy, so once the Djinn can *see* that (e.g.) a `product` post type exists, it can act on it
 * with no plugin cooperation. Reads gate on the post's own caps; meta writes refuse protected keys.
 */
class DiscoveryFeature implements Feature {

	public function register( Registry $r ): void {
		$postType = new ObjectType(
			[
				'name'        => 'PostType',
				'description' => 'A registered post type (built-in or plugin-provided). Use its `name` as the `postType`/`type` argument on posts, createPost, updatePost.',
				'fields'      => [
					'name'         => [ 'type' => Type::string(), 'description' => 'The key to pass as postType, e.g. "product".' ],
					'label'        => [ 'type' => Type::string() ],
					'description'  => [ 'type' => Type::string() ],
					'public'       => [ 'type' => Type::boolean() ],
					'hierarchical' => [ 'type' => Type::boolean(), 'description' => 'Page-like (true) vs post-like (false).' ],
					'restBase'     => [ 'type' => Type::string(), 'description' => 'REST base under /wp/v2 if exposed, else null.' ],
					'supports'     => [ 'type' => Type::listOf( Type::string() ), 'description' => 'Features: title, editor, thumbnail, custom-fields, …' ],
					'taxonomies'   => [ 'type' => Type::listOf( Type::string() ) ],
					'canCreate'    => [ 'type' => Type::boolean(), 'description' => 'Whether the current user may create entries of this type.' ],
					'canEdit'      => [ 'type' => Type::boolean() ],
				],
			]
		);
		$r->setType( 'PostType', $postType );

		$taxonomy = new ObjectType(
			[
				'name'        => 'TaxonomyInfo',
				'description' => 'A registered taxonomy. Use its `name` as the `taxonomy` argument on terms/createTerm/assignTerms.',
				'fields'      => [
					'name'         => [ 'type' => Type::string() ],
					'label'        => [ 'type' => Type::string() ],
					'hierarchical' => [ 'type' => Type::boolean(), 'description' => 'Category-like (true) vs tag-like (false).' ],
					'objectTypes'  => [ 'type' => Type::listOf( Type::string() ), 'description' => 'Post types this taxonomy applies to.' ],
					'restBase'     => [ 'type' => Type::string() ],
				],
			]
		);
		$r->setType( 'TaxonomyInfo', $taxonomy );

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

		$r->addQuery( 'postTypes', [
			'type'        => Type::listOf( $postType ),
			'description' => 'List the post types registered on this site (including ones added by plugins), so you can target them with the generic post operations.',
			'resolve'     => [ $this, 'postTypes' ],
		] );

		$r->addQuery( 'taxonomies', [
			'type'        => Type::listOf( $taxonomy ),
			'description' => 'List the taxonomies registered on this site (including plugin-provided ones).',
			'resolve'     => [ $this, 'taxonomies' ],
		] );

		$r->addQuery( 'postMeta', [
			'type'        => Type::listOf( $meta ),
			'description' => 'Read a post\'s custom fields (post meta). Optionally filter to one key.',
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

	/** @return array<int,array<string,mixed>> */
	public function postTypes(): array {
		$out = [];
		foreach ( get_post_types( [], 'objects' ) as $pt ) {
			if ( ! $pt->public && ! $pt->show_ui ) {
				continue; // internal plumbing (revisions, nav menu items, …) — not actionable
			}
			$out[] = [
				'name'         => $pt->name,
				'label'        => $pt->labels->singular_name ?? $pt->label ?? $pt->name,
				'description'  => $pt->description,
				'public'       => (bool) $pt->public,
				'hierarchical' => (bool) $pt->hierarchical,
				'restBase'     => $pt->show_in_rest ? ( $pt->rest_base ?: $pt->name ) : null,
				'supports'     => array_keys( get_all_post_type_supports( $pt->name ) ),
				'taxonomies'   => get_object_taxonomies( $pt->name ),
				'canCreate'    => current_user_can( $pt->cap->create_posts ?? 'do_not_allow' ),
				'canEdit'      => current_user_can( $pt->cap->edit_posts ?? 'do_not_allow' ),
			];
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function taxonomies(): array {
		$out = [];
		foreach ( get_taxonomies( [], 'objects' ) as $tax ) {
			if ( ! $tax->public && ! $tax->show_ui ) {
				continue;
			}
			$out[] = [
				'name'         => $tax->name,
				'label'        => $tax->labels->singular_name ?? $tax->label ?? $tax->name,
				'hierarchical' => (bool) $tax->hierarchical,
				'objectTypes'  => array_values( (array) $tax->object_type ),
				'restBase'     => $tax->show_in_rest ? ( $tax->rest_base ?: $tax->name ) : null,
			];
		}
		return $out;
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
