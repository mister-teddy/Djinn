<?php

declare( strict_types=1 );

namespace Djinn\Usage;

/**
 * Per-model price table in USD per 1,000,000 tokens. These are public list prices and they
 * drift, so the dashboard presents totals as estimates. Extend or correct them with the
 * `djinn_model_pricing` filter. Embedding models have no output price.
 */
class Pricing {

	/** @return array<string,array{input:float,output:float}> */
	public static function table(): array {
		$table = [
			// OpenAI — chat
			'gpt-4o'                 => [ 'input' => 2.50, 'output' => 10.00 ],
			'gpt-4o-mini'            => [ 'input' => 0.15, 'output' => 0.60 ],
			'gpt-4.1'                => [ 'input' => 2.00, 'output' => 8.00 ],
			'gpt-4.1-mini'           => [ 'input' => 0.40, 'output' => 1.60 ],
			// OpenAI — embeddings
			'text-embedding-3-small' => [ 'input' => 0.02, 'output' => 0.0 ],
			'text-embedding-3-large' => [ 'input' => 0.13, 'output' => 0.0 ],
			// Gemini — chat
			'gemini-2.5-pro'         => [ 'input' => 1.25, 'output' => 10.00 ],
			'gemini-2.5-flash'       => [ 'input' => 0.30, 'output' => 2.50 ],
			'gemini-2.5-flash-lite'  => [ 'input' => 0.10, 'output' => 0.40 ],
			'gemini-2.0-flash'       => [ 'input' => 0.10, 'output' => 0.40 ],
			'gemini-2.0-flash-lite'  => [ 'input' => 0.075, 'output' => 0.30 ],
			'gemini-1.5-flash'       => [ 'input' => 0.075, 'output' => 0.30 ],
			'gemini-1.5-pro'         => [ 'input' => 1.25, 'output' => 5.00 ],
			// Gemini — embeddings
			'gemini-embedding-001'   => [ 'input' => 0.15, 'output' => 0.0 ],
		];

		/** @var array<string,array{input:float,output:float}> $filtered */
		$filtered = apply_filters( 'djinn_model_pricing', $table );
		return $filtered;
	}

	public static function isKnown( string $model ): bool {
		return isset( self::table()[ $model ] );
	}

	/**
	 * Human-readable price descriptor for a model, e.g. "$2.50 in · $10.00 out / 1M",
	 * "$0.15 / 1M", "free", or "price unknown". For dropdown labels and tooltips.
	 */
	public static function describe( string $model ): string {
		$rates = self::table()[ $model ] ?? null;
		if ( $rates === null ) {
			return 'price unknown';
		}
		if ( $rates['input'] == 0.0 && $rates['output'] == 0.0 ) {
			return 'free';
		}
		if ( $rates['output'] == 0.0 ) {
			return self::per( $rates['input'] ) . ' / 1M tokens';
		}
		return self::per( $rates['input'] ) . ' in · ' . self::per( $rates['output'] ) . ' out / 1M';
	}

	private static function per( float $v ): string {
		return '$' . rtrim( rtrim( number_format( $v, 4 ), '0' ), '.' );
	}

	/**
	 * Cost in USD for a single call. Unknown models cost 0 — their tokens are still tracked,
	 * and the dashboard flags them so you know a price is missing.
	 */
	public static function cost( string $model, int $promptTokens, int $completionTokens ): float {
		$rates = self::table()[ $model ] ?? null;
		if ( ! $rates ) {
			return 0.0;
		}
		return ( $promptTokens * $rates['input'] + $completionTokens * $rates['output'] ) / 1_000_000;
	}
}
