<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Taxonomies: list/create/delete terms (categories, tags, or any registered taxonomy) and assign
 * them to posts.
 */
class TaxonomyFeature implements Feature {

	public function register( Registry $r ): void {
		$term = new ObjectType(
			array(
				'name'        => 'Term',
				'description' => 'A taxonomy term (category, tag, …).',
				'fields'      => array(
					'id'          => array( 'type' => Type::id() ),
					'name'        => array( 'type' => Type::string() ),
					'slug'        => array( 'type' => Type::string() ),
					'taxonomy'    => array( 'type' => Type::string() ),
					'count'       => array(
						'type'        => Type::int(),
						'description' => 'Number of objects with this term.',
					),
					'description' => array( 'type' => Type::string() ),
					'parentId'    => array( 'type' => Type::id() ),
				),
			)
		);
		$r->setType( 'Term', $term );

		$r->addQuery(
			'terms',
			array(
				'type'        => Type::listOf( $term ),
				'description' => 'List terms in a taxonomy (default "category").',
				'args'        => array(
					'taxonomy' => array(
						'type'         => Type::string(),
						'defaultValue' => 'category',
					),
					'search'   => array( 'type' => Type::string() ),
					'first'    => array(
						'type'         => Type::int(),
						'defaultValue' => 50,
					),
				),
				'resolve'     => array( $this, 'terms' ),
			)
		);

		$r->addMutation(
			'createTerm',
			array(
				'type'        => $term,
				'description' => 'Create a term in a taxonomy.',
				'args'        => array(
					'taxonomy'    => array( 'type' => Type::nonNull( Type::string() ) ),
					'name'        => array( 'type' => Type::nonNull( Type::string() ) ),
					'slug'        => array( 'type' => Type::string() ),
					'description' => array( 'type' => Type::string() ),
					'parentId'    => array( 'type' => Type::id() ),
				),
				'resolve'     => array( $this, 'createTerm' ),
			)
		);

		$r->addMutation(
			'deleteTerm',
			array(
				'type'    => Type::boolean(),
				'args'    => array(
					'id'       => array( 'type' => Type::nonNull( Type::id() ) ),
					'taxonomy' => array( 'type' => Type::nonNull( Type::string() ) ),
				),
				'resolve' => array( $this, 'deleteTerm' ),
			)
		);

		$r->addMutation(
			'assignTerms',
			array(
				'type'        => Type::boolean(),
				'description' => 'Set (or append) a post\'s terms in a taxonomy. Pass term IDs.',
				'args'        => array(
					'postId'   => array( 'type' => Type::nonNull( Type::id() ) ),
					'taxonomy' => array( 'type' => Type::nonNull( Type::string() ) ),
					'termIds'  => array( 'type' => Type::nonNull( Type::listOf( Type::id() ) ) ),
					'append'   => array(
						'type'         => Type::boolean(),
						'defaultValue' => false,
					),
				),
				'resolve'     => array( $this, 'assignTerms' ),
			)
		);
	}

	private function shape( \WP_Term $t ): array {
		return array(
			'id'          => (string) $t->term_id,
			'name'        => $t->name,
			'slug'        => $t->slug,
			'taxonomy'    => $t->taxonomy,
			'count'       => (int) $t->count,
			'description' => $t->description,
			'parentId'    => $t->parent ? (string) $t->parent : null,
		);
	}

	private function taxOrFail( string $taxonomy ): \WP_Taxonomy {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			throw new UserError( "No such taxonomy: '$taxonomy'." );
		}
		return $tax;
	}

	/** @param array<string,mixed> $args */
	public function terms( $root, array $args ): array {
		$tax = $this->taxOrFail( (string) ( $args['taxonomy'] ?? 'category' ) );
		if ( ! current_user_can( $tax->cap->assign_terms ) && ! current_user_can( $tax->cap->manage_terms ) ) {
			throw new UserError( 'You do not have permission to view these terms.' );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax->name,
				'hide_empty' => false,
				'search'     => $args['search'] ?? '',
				'number'     => min( max( (int) ( $args['first'] ?? 50 ), 1 ), 200 ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			throw new UserError( $terms->get_error_message() );
		}
		return array_map( array( $this, 'shape' ), $terms );
	}

	/** @param array<string,mixed> $args */
	public function createTerm( $root, array $args ): array {
		$tax = $this->taxOrFail( (string) $args['taxonomy'] );
		if ( ! current_user_can( $tax->cap->edit_terms ) ) {
			throw new UserError( "You do not have permission to create '{$tax->name}' terms." );
		}
		$res = wp_insert_term(
			(string) $args['name'],
			$tax->name,
			array_filter(
				array(
					'slug'        => $args['slug'] ?? null,
					'description' => $args['description'] ?? null,
					'parent'      => isset( $args['parentId'] ) ? (int) $args['parentId'] : null,
				),
				static fn( $v ) => $v !== null
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new UserError( $res->get_error_message() );
		}
		return $this->shape( get_term( $res['term_id'], $tax->name ) );
	}

	/** @param array<string,mixed> $args */
	public function deleteTerm( $root, array $args ): bool {
		$tax = $this->taxOrFail( (string) $args['taxonomy'] );
		if ( ! current_user_can( $tax->cap->delete_terms ) ) {
			throw new UserError( "You do not have permission to delete '{$tax->name}' terms." );
		}
		$res = wp_delete_term( (int) $args['id'], $tax->name );
		if ( is_wp_error( $res ) ) {
			throw new UserError( $res->get_error_message() );
		}
		return (bool) $res;
	}

	/** @param array<string,mixed> $args */
	public function assignTerms( $root, array $args ): bool {
		$postId = (int) $args['postId'];
		$tax    = $this->taxOrFail( (string) $args['taxonomy'] );
		if ( ! current_user_can( 'edit_post', $postId ) || ! current_user_can( $tax->cap->assign_terms ) ) {
			throw new UserError( 'You do not have permission to assign these terms.' );
		}
		$ids = array_map( 'intval', (array) $args['termIds'] );
		$res = wp_set_object_terms( $postId, $ids, $tax->name, (bool) ( $args['append'] ?? false ) );
		if ( is_wp_error( $res ) ) {
			throw new UserError( $res->get_error_message() );
		}
		return true;
	}
}
