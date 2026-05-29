<?php

declare( strict_types=1 );

namespace Djinn\Rag;

use Djinn\GraphQL\SchemaFactory;
use Djinn\Provider\ProviderFactory;
use Djinn\Store\Repository;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Utils\SchemaPrinter;

/**
 * Builds the RAG index: one chunk per named GraphQL type (its printed SDL fragment), each
 * embedded via the configured provider and stored for cosine retrieval.
 */
class Indexer {

	private const META_OPTION = 'djinn_index_meta';

	/**
	 * The current schema as RAG chunks: one printed SDL fragment per named type. Shared by the
	 * indexer and the index-status page (which diffs these against what's stored).
	 *
	 * @return array<string,string> type name => SDL fragment, sorted by name.
	 */
	public static function chunks(): array {
		$chunks = [];
		// Build the schema first — this also registers features (e.g. WooCommerce) that claim
		// post types via the djinn_curated_post_types filter siteChunks() reads below.
		foreach ( SchemaFactory::build()->getTypeMap() as $name => $type ) {
			if ( str_starts_with( $name, '__' ) ) {
				continue; // introspection types
			}
			if ( ! ( $type instanceof ObjectType
				|| $type instanceof InputObjectType
				|| $type instanceof EnumType
				|| $type instanceof InterfaceType
				|| $type instanceof UnionType ) ) {
				continue; // built-in scalars
			}
			$chunks[ $name ] = SchemaPrinter::printType( $type );
		}
		foreach ( self::siteChunks() as $name => $fragment ) {
			$chunks[ $name ] = $fragment;
		}
		ksort( $chunks );
		return $chunks;
	}

	/**
	 * Synthetic, natural-language chunks describing the custom post types and taxonomies *this*
	 * site registers (via plugins), so the Djinn discovers them through search_schema and drives
	 * them with the generic post/term operations — no plugin cooperation, no extra round-trip.
	 * Skips WordPress built-ins (already covered by the core schema) and any type a curated feature
	 * has claimed. Because these are part of chunks(), they also flow into fingerprint(), so the
	 * "needs reindex" red dot trips automatically when a CPT/taxonomy is added or removed.
	 *
	 * @return array<string,string>
	 */
	private static function siteChunks(): array {
		$out          = [];
		$curatedTypes = (array) apply_filters( 'djinn_curated_post_types', [] );
		$curatedTax   = (array) apply_filters( 'djinn_curated_taxonomies', [] );

		foreach ( get_post_types( [ '_builtin' => false ], 'objects' ) as $pt ) {
			if ( ( ! $pt->public && ! $pt->show_ui ) || in_array( $pt->name, $curatedTypes, true ) ) {
				continue;
			}
			$taxes = get_object_taxonomies( $pt->name );
			$out[ 'site:cpt:' . $pt->name ] = sprintf(
				'Custom content type "%s" — post type `%s`%s. Work with it using the generic post '
				. 'operations and this exact postType: list with posts(postType: "%1$s"), read with '
				. 'post(id), create with createPost(input: { postType: "%2$s", ... }), edit with '
				. 'updatePost, delete with deletePost. Custom fields via postMeta / setPostMeta.%s%s',
				$pt->labels->name ?? $pt->label ?? $pt->name,
				$pt->name,
				$pt->hierarchical ? ' (hierarchical, page-like)' : '',
				$taxes ? ' Taxonomies: ' . implode( ', ', $taxes ) . '.' : '',
				$pt->description ? ' ' . $pt->description : ''
			);
		}

		foreach ( get_taxonomies( [ '_builtin' => false ], 'objects' ) as $tax ) {
			if ( ( ! $tax->public && ! $tax->show_ui ) || in_array( $tax->name, $curatedTax, true ) ) {
				continue;
			}
			$out[ 'site:tax:' . $tax->name ] = sprintf(
				'Custom taxonomy "%s" — `%s`%s, applies to: %s. List terms with terms(taxonomy: '
				. '"%2$s"), create with createTerm, attach to a post with assignTerms.',
				$tax->labels->name ?? $tax->label ?? $tax->name,
				$tax->name,
				$tax->hierarchical ? ' (hierarchical, category-like)' : ' (flat, tag-like)',
				implode( ', ', (array) $tax->object_type ) ?: 'posts'
			);
		}

		return $out;
	}

	/** A stable hash of the current schema, so we can tell when the index is out of date. */
	public static function fingerprint(): string {
		$parts = [];
		foreach ( self::chunks() as $name => $fragment ) {
			$parts[] = $name . ':' . $fragment;
		}
		return md5( implode( "\n", $parts ) );
	}

	/** @return array{fingerprint?:string,model?:string,count?:int,indexed_at?:string} */
	public static function meta(): array {
		$meta = get_option( self::META_OPTION, [] );
		return is_array( $meta ) ? $meta : [];
	}

	/** @return int Number of chunks indexed. */
	public static function reindex(): int {
		$chunks   = self::chunks();
		$provider = ProviderFactory::make();
		$vectors  = $provider->embed( array_values( $chunks ) );

		$rows = [];
		$i    = 0;
		foreach ( $chunks as $name => $fragment ) {
			$rows[] = [
				'name'      => $name,
				'fragment'  => $fragment,
				'embedding' => $vectors[ $i ] ?? [],
			];
			$i++;
		}

		Repository::replaceChunks( $rows, $provider->embeddingModel() );

		update_option(
			self::META_OPTION,
			[
				'fingerprint' => self::fingerprint(),
				'model'       => $provider->embeddingModel(),
				'count'       => count( $rows ),
				'indexed_at'  => current_time( 'mysql', true ),
			]
		);
		return count( $rows );
	}
}
