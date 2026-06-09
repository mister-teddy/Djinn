<?php

declare( strict_types=1 );

namespace Djinn\Engine;

use Djinn\Settings;

/**
 * The Djinn's entire tool surface: discover the schema, then run GraphQL.
 * A single run_graphql handles reads and writes; the engine decides which by parsing the
 * operation type, and gates mutations behind human confirmation ("grant this wish?").
 *
 * `rest_call` (the universal escape hatch) is Pro-only and matches `RestFeature` being unregistered
 * in Free — so the gate is purely which capabilities exist, with no tier checks in the agent loop.
 */
class Tools {

	/** @return array<int,array<string,mixed>> */
	public static function specs(): array {
		$specs = array(
			array(
				'name'        => 'search_schema',
				'description' => 'Search the site\'s GraphQL schema for types and fields relevant to the user\'s wish. Call this before writing a query so you use real field/argument names. Returns SDL fragments.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'What you are looking for, e.g. "create a page", "site tagline", "list recent posts".',
						),
					),
					'required'   => array( 'query' ),
				),
			),
			array(
				'name'        => 'run_graphql',
				'description' => 'Execute a GraphQL operation against the site. A `query` runs immediately; a `mutation` is shown to the user for confirmation before it runs. Use the exact types/fields from search_schema.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array(
							'type'        => 'string',
							'description' => 'A complete GraphQL document, e.g. "mutation($i: PostInput!){ createPost(input:$i){ id link } }".',
						),
						'variables' => array(
							'type'        => 'object',
							'description' => 'Variables object for the operation. Omit if the operation has none.',
						),
						'summary'   => array(
							'type'        => 'string',
							'description' => 'For mutations: a one-sentence plain-language description of the wish, shown on the confirmation card.',
						),
					),
					'required'   => array( 'operation' ),
				),
			),
		);

		if ( Settings::isPro() ) {
			$specs[] = array(
				'name'        => 'rest_call',
				'description' => 'Call a WordPress REST route — the escape hatch for plugins with no native GraphQL field. Discover routes first via the `restRoutes` GraphQL query. GET/HEAD run immediately; POST/PUT/PATCH/DELETE are paused for the user to Grant. Each route enforces its own permissions.',
				'parameters'  => array(
					'type'       => 'object',
					'properties' => array(
						'method'  => array(
							'type'        => 'string',
							'description' => 'HTTP method: GET, POST, PUT, PATCH, or DELETE.',
						),
						'path'    => array(
							'type'        => 'string',
							'description' => 'REST route path beginning with "/", e.g. "/wp/v2/pages" or "/wc/v3/products/12".',
						),
						'body'    => array(
							'type'        => 'object',
							'description' => 'Body parameters for writes. Omit for reads.',
						),
						'params'  => array(
							'type'        => 'object',
							'description' => 'Query-string parameters, e.g. {"per_page": 5, "search": "hat"}. Omit if none.',
						),
						'summary' => array(
							'type'        => 'string',
							'description' => 'For writes: a one-sentence plain-language description, shown on the confirmation card.',
						),
					),
					'required'   => array( 'method', 'path' ),
				),
			);
		}

		return $specs;
	}
}
