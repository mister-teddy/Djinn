<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

/**
 * A self-contained slice of the schema (a domain of capabilities). Each feature adds its own
 * query/mutation fields — and the resolvers behind them — to the registry. Every resolver must
 * gate on current_user_can(); the Grant step adds a second guard for mutations.
 */
interface Feature {

	public function register( Registry $registry ): void;
}
