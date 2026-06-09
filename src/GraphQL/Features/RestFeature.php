<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\Engine\RestRunner;
use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Tier-2 discovery half: a read-only `restRoutes` query that lists the site's registered REST
 * routes so the Djinn can find a plugin's endpoints. Executing one is the job of the dedicated
 * `rest_call` tool (see Engine\Tools / AgentLoop), kept separate from GraphQL because a REST
 * write is identified by its HTTP method, not by a GraphQL operation type — so it needs its own
 * method-based confirmation gate.
 */
class RestFeature implements Feature {

	public function register( Registry $r ): void {
		$route = new ObjectType(
			array(
				'name'        => 'RestRoute',
				'description' => 'A registered REST API route. Execute one with the rest_call tool.',
				'fields'      => array(
					'route'     => array(
						'type'        => Type::string(),
						'description' => 'Path to pass to rest_call, e.g. "/wp/v2/pages".',
					),
					'namespace' => array( 'type' => Type::string() ),
					'methods'   => array(
						'type'        => Type::listOf( Type::string() ),
						'description' => 'GET reads; POST/PUT/PATCH/DELETE are writes (Grant-gated).',
					),
				),
			)
		);
		$r->setType( 'RestRoute', $route );

		$r->addQuery(
			'restRoutes',
			array(
				'type'        => Type::listOf( $route ),
				'description' => 'List REST routes registered on this site — including those added by plugins Djinn has no built-in support for. Use when search_schema finds no native field for a plugin feature; then call the rest_call tool to act on the chosen route.',
				'args'        => array(
					'namespace' => array(
						'type'        => Type::string(),
						'description' => 'Filter by namespace prefix, e.g. "wc/v3" or "wp/v2".',
					),
					'search'    => array(
						'type'        => Type::string(),
						'description' => 'Substring match on the route path.',
					),
				),
				'resolve'     => static fn( $root, array $args ): array => RestRunner::routes(
					isset( $args['namespace'] ) ? (string) $args['namespace'] : null,
					isset( $args['search'] ) ? (string) $args['search'] : null
				),
			)
		);
	}
}
