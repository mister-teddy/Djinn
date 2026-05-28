<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;

/**
 * Parses and executes the GraphQL the Djinn generates against the in-house schema.
 * operationType() lets the agent decide whether a document is a read (auto-run) or a write
 * (confirmation-gated) — GraphQL guarantees a `query` cannot mutate, so the parsed type is a
 * trustworthy safety signal.
 */
class Runner {

	/**
	 * Returns 'query' or 'mutation' for the first operation in the document.
	 * Anonymous shorthand (`{ ... }`) is always a query. Throws on unparseable input.
	 */
	public static function operationType( string $operation ): string {
		$ast = Parser::parse( $operation );
		foreach ( $ast->definitions as $def ) {
			if ( $def instanceof OperationDefinitionNode ) {
				return $def->operation;
			}
		}
		return 'query';
	}

	/**
	 * Executes the operation and returns a JSON-serializable result array
	 * ({ data?, errors? }) suitable to feed straight back to the model.
	 *
	 * @param array<string,mixed> $variables
	 * @return array<string,mixed>
	 */
	public static function execute( string $operation, array $variables = [] ): array {
		$result = GraphQL::executeQuery(
			SchemaFactory::build(),
			$operation,
			null,
			null,
			$variables ?: null
		);
		return $result->toArray();
	}
}
