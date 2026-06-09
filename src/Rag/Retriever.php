<?php

declare( strict_types=1 );

namespace Djinn\Rag;

use Djinn\GraphQL\SchemaFactory;
use Djinn\Provider\ProviderFactory;
use Djinn\Store\Repository;
use GraphQL\Utils\SchemaPrinter;

/**
 * Semantic retrieval over the indexed schema. The Djinn's `search_schema` tool calls this to
 * pull in only the type definitions relevant to a wish. If the index is empty, it falls back
 * to returning the entire schema SDL so the lamp still works.
 */
class Retriever {

	public static function search( string $query, int $k = 8 ): string {
		$chunks = Repository::getChunks();

		if ( empty( $chunks ) ) {
			return "Schema index is empty; returning the full schema.\n\n" . SchemaPrinter::doPrint( SchemaFactory::build() );
		}

		$needle = ProviderFactory::make()->embed( array( $query ) )[0] ?? array();
		if ( empty( $needle ) ) {
			return SchemaPrinter::doPrint( SchemaFactory::build() );
		}

		$scored = array();
		foreach ( $chunks as $chunk ) {
			$scored[] = array(
				'score'    => self::cosine( $needle, $chunk['embedding'] ),
				'fragment' => $chunk['fragment'],
			);
		}
		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		$top = array_slice( $scored, 0, $k );
		return implode( "\n\n", array_column( $top, 'fragment' ) );
	}

	/**
	 * @param array<int,float> $a
	 * @param array<int,float> $b
	 */
	private static function cosine( array $a, array $b ): float {
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		$len = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $len; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$na  += $a[ $i ] * $a[ $i ];
			$nb  += $b[ $i ] * $b[ $i ];
		}
		if ( $na <= 0.0 || $nb <= 0.0 ) {
			return 0.0;
		}
		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}
}
