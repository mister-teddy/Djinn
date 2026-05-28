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

	/** @return int Number of chunks indexed. */
	public static function reindex(): int {
		$schema = SchemaFactory::build();
		$chunks = [];

		foreach ( $schema->getTypeMap() as $name => $type ) {
			if ( str_starts_with( $name, '__' ) ) {
				continue; // introspection types
			}
			if ( ! ( $type instanceof ObjectType
				|| $type instanceof InputObjectType
				|| $type instanceof EnumType
				|| $type instanceof InterfaceType
				|| $type instanceof UnionType ) ) {
				continue; // skip built-in scalars
			}
			$chunks[] = [
				'name'     => $name,
				'fragment' => SchemaPrinter::printType( $type ),
			];
		}

		$provider = ProviderFactory::make();
		$vectors  = $provider->embed( array_column( $chunks, 'fragment' ) );

		$rows = [];
		foreach ( $chunks as $i => $chunk ) {
			$rows[] = [
				'name'      => $chunk['name'],
				'fragment'  => $chunk['fragment'],
				'embedding' => $vectors[ $i ] ?? [],
			];
		}

		Repository::replaceChunks( $rows, $provider->embeddingModel() );
		return count( $rows );
	}
}
