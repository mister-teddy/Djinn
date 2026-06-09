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
	private array $queries = array();

	/** @var array<string,array<string,mixed>> */
	private array $mutations = array();

	/** @var array<string,Type> Shared object/input types, by name, for features that reuse them. */
	private array $types = array();

	/** @var array<string,string> Capability domain per "kind:name", for the admin Capabilities view. */
	private array $domains = array();

	/** The domain operations are filed under until changed — set per feature by SchemaFactory. */
	private string $currentDomain = 'Core';

	public function setCurrentDomain( string $domain ): void {
		$this->currentDomain = $domain;
	}

	/** @param array<string,mixed> $config A graphql-php field definition. */
	public function addQuery( string $name, array $config ): void {
		$this->queries[ $name ]            = $config;
		$this->domains[ 'query:' . $name ] = $this->currentDomain;
	}

	/** @param array<string,mixed> $config */
	public function addMutation( string $name, array $config ): void {
		$this->mutations[ $name ]             = $config;
		$this->domains[ 'mutation:' . $name ] = $this->currentDomain;
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

	/**
	 * Flat operation catalog for the admin Capabilities view — every query and mutation with its
	 * domain, kind, description, argument shape, and return type. Capability gating is per-resolver,
	 * so this is the full surface ("what the Djinn can do"), not a per-user permission check.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function operations(): array {
		$out = array();
		foreach ( array(
			'query'    => $this->queries,
			'mutation' => $this->mutations,
		) as $kind => $fields ) {
			foreach ( $fields as $name => $config ) {
				$out[] = array(
					'domain'      => $this->domains[ $kind . ':' . $name ] ?? 'Core',
					'name'        => $name,
					'kind'        => $kind,
					'description' => (string) ( $config['description'] ?? '' ),
					'args'        => self::describeArgs( $config['args'] ?? array() ),
					'returns'     => isset( $config['type'] ) ? (string) $config['type'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,string|bool>>
	 */
	private static function describeArgs( array $args ): array {
		$out = array();
		foreach ( $args as $argName => $arg ) {
			$type  = isset( $arg['type'] ) ? (string) $arg['type'] : '';
			$out[] = array(
				'name'     => (string) $argName,
				'type'     => $type,
				'required' => str_ends_with( $type, '!' ),
			);
		}
		return $out;
	}
}
