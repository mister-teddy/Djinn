<?php

declare( strict_types=1 );

namespace Djinn\Engine;

/**
 * The Djinn's entire tool surface. The full schema rides in the system prompt, so a single
 * run_graphql handles reads and writes; the engine decides which by parsing the
 * operation type, and gates mutations behind human confirmation ("grant this wish?").
 *
 * Add-ons can append tool specs with the `djinn_tool_specs` filter.
 */
class Tools {

	/** @return array<int,array<string,mixed>> */
	public static function specs(): array {
		$specs = array(
			array(
				'name'        => 'run_graphql',
				'description' => 'Execute a GraphQL operation against the site. A `query` runs immediately; a `mutation` is shown to the user for confirmation before it runs. Use the exact types/fields from the schema in the system prompt.',
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

		return (array) apply_filters( 'djinn_tool_specs', $specs );
	}
}
