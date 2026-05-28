<?php

declare( strict_types=1 );

namespace Djinn\Rag;

use Djinn\Settings;
use Djinn\Store\Repository;
use Djinn\Usage\Pricing;

/**
 * Read-only view of the RAG index for the management page: whether it's current, what a reindex
 * would cost, and a diff of which schema types would be added / removed / changed.
 */
class IndexStatus {

	/** @return array<string,mixed> */
	public static function summary(): array {
		$current = Indexer::chunks();                 // name => fragment (live schema)
		$stored  = self::storedFragments();           // name => fragment (what's indexed)
		$meta    = Indexer::meta();
		$model   = Settings::embeddingModel();

		$indexed   = Repository::chunkCount() > 0;
		$upToDate  = $indexed
			&& ( $meta['fingerprint'] ?? '' ) === Indexer::fingerprint()
			&& ( $meta['model'] ?? '' ) === $model;

		return [
			'indexed'      => $indexed,
			'up_to_date'   => $upToDate,
			'model'        => $model,
			'stored_model' => $meta['model'] ?? '',
			'indexed_at'   => $meta['indexed_at'] ?? '',
			'count_stored' => $indexed ? count( $stored ) : 0,
			'count_live'   => count( $current ),
			'estimate'     => self::estimate( $current, $model ),
			'diff'         => self::diff( $current, $stored ),
		];
	}

	/** @return array<string,string> */
	private static function storedFragments(): array {
		$out = [];
		foreach ( Repository::getChunks() as $chunk ) {
			$out[ $chunk['name'] ] = $chunk['fragment'];
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Cost of embedding the whole live schema (reindex re-embeds everything). ~4 chars/token.
	 *
	 * @param array<string,string> $current
	 * @return array{chunks:int,tokens:int,cost:float,free:bool,unpriced:bool}
	 */
	private static function estimate( array $current, string $model ): array {
		$chars  = 0;
		foreach ( $current as $fragment ) {
			$chars += strlen( $fragment );
		}
		$tokens = (int) ceil( $chars / 4 );
		$cost   = Pricing::cost( $model, $tokens, 0 );
		return [
			'chunks'   => count( $current ),
			'tokens'   => $tokens,
			'cost'     => $cost,
			'free'     => Pricing::isKnown( $model ) && $cost === 0.0,
			'unpriced' => ! Pricing::isKnown( $model ),
		];
	}

	/**
	 * What a reindex would change, by type name.
	 *
	 * @param array<string,string> $current
	 * @param array<string,string> $stored
	 * @return array{added:array<int,string>,removed:array<int,string>,changed:array<int,string>}
	 */
	private static function diff( array $current, array $stored ): array {
		$added   = array_values( array_diff( array_keys( $current ), array_keys( $stored ) ) );
		$removed = array_values( array_diff( array_keys( $stored ), array_keys( $current ) ) );
		$changed = [];
		foreach ( $current as $name => $fragment ) {
			if ( isset( $stored[ $name ] ) && $stored[ $name ] !== $fragment ) {
				$changed[] = $name;
			}
		}
		sort( $added );
		sort( $removed );
		sort( $changed );
		return [ 'added' => $added, 'removed' => $removed, 'changed' => $changed ];
	}
}
