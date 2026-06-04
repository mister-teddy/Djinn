<?php

declare( strict_types=1 );

namespace Djinn\Provider;

/**
 * A failed proxy GraphQL call. `$unreachable` is true when the transport itself failed (the proxy
 * could not be contacted at all) and false when a reachable proxy returned a GraphQL error.
 */
class ProxyException extends \RuntimeException {

	public bool $unreachable;

	public function __construct( string $message, bool $unreachable = false ) {
		parent::__construct( $message );
		$this->unreachable = $unreachable;
	}
}
