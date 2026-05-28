<?php

declare( strict_types=1 );

namespace Djinn\Usage;

use Djinn\Store\Repository;
use Throwable;

/**
 * Records token usage and estimated cost for every provider call. Best-effort by design: a
 * telemetry failure must never break a wish, so the write is wrapped and any error swallowed.
 */
class UsageRecorder {

	/**
	 * @param string $kind 'chat' or 'embed'
	 * @param bool   $estimated True when token counts were inferred (e.g. Gemini embeddings,
	 *                          whose API returns no usage metadata).
	 */
	public static function record( string $provider, string $model, string $kind, int $promptTokens, int $completionTokens, bool $estimated = false ): void {
		try {
			Repository::recordUsage(
				[
					'user_id'           => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
					'provider'          => $provider,
					'model'             => $model,
					'kind'              => $kind,
					'prompt_tokens'     => max( 0, $promptTokens ),
					'completion_tokens' => max( 0, $completionTokens ),
					'estimated'         => $estimated ? 1 : 0,
					'cost'              => Pricing::cost( $model, max( 0, $promptTokens ), max( 0, $completionTokens ) ),
				]
			);
		} catch ( Throwable $e ) {
			// Telemetry should never block the lamp.
		}
	}
}
