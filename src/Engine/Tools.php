<?php

declare( strict_types=1 );

namespace Djinn\Engine;

/**
 * The Djinn's entire tool surface: discover the schema, then run GraphQL.
 * A single run_graphql handles reads and writes; the engine decides which by parsing the
 * operation type, and gates mutations behind human confirmation ("grant this wish?").
 */
class Tools {

	/** @return array<int,array<string,mixed>> */
	public static function specs(): array {
		return [
			[
				'name'        => 'search_schema',
				'description' => 'Search the site\'s GraphQL schema for types and fields relevant to the user\'s wish. Call this before writing a query so you use real field/argument names. Returns SDL fragments.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => 'What you are looking for, e.g. "create a page", "site tagline", "list recent posts".',
						],
					],
					'required'   => [ 'query' ],
				],
			],
			[
				'name'        => 'run_graphql',
				'description' => 'Execute a GraphQL operation against the site. A `query` runs immediately; a `mutation` is shown to the user for confirmation before it runs. Use the exact types/fields from search_schema.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'operation' => [
							'type'        => 'string',
							'description' => 'A complete GraphQL document, e.g. "mutation($i: PostInput!){ createPost(input:$i){ id link } }".',
						],
						'variables' => [
							'type'        => 'object',
							'description' => 'Variables object for the operation. Omit if the operation has none.',
						],
						'summary'   => [
							'type'        => 'string',
							'description' => 'For mutations: a one-sentence plain-language description of the wish, shown on the confirmation card.',
						],
					],
					'required'   => [ 'operation' ],
				],
			],
		];
	}
}
