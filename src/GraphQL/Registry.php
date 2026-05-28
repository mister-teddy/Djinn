<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

use GraphQL\Type\Definition\Type;

/**
 * Collects the schema's query and mutation fields as features register them. Built-in features
 * and third-party plugins (via the `djinn_register_schema` action) add to the same registry, so
 * the Djinn's capabilities grow without anyone editing SchemaFactory.
 */
class Registry {

	/** @var array<string,array<string,mixed>> */
	private array $queries = [];

	/** @var array<string,array<string,mixed>> */
	private array $mutations = [];

	/** @var array<string,Type> Shared object/input types, by name, for features that reuse them. */
	private array $types = [];

	/** @param array<string,mixed> $config A graphql-php field definition. */
	public function addQuery( string $name, array $config ): void {
		$this->queries[ $name ] = $config;
	}

	/** @param array<string,mixed> $config */
	public function addMutation( string $name, array $config ): void {
		$this->mutations[ $name ] = $config;
	}

	public function setType( string $name, Type $type ): void {
		$this->types[ $name ] = $type;
	}

	public function type( string $name ): ?Type {
		return $this->types[ $name ] ?? null;
	}

	/** @return array<string,array<string,mixed>> */
	public function queries(): array {
		return $this->queries;
	}

	/** @return array<string,array<string,mixed>> */
	public function mutations(): array {
		return $this->mutations;
	}
}
