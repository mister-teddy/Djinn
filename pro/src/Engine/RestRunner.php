<?php

declare( strict_types=1 );

namespace Djinn\Engine;

/**
 * Tier-2 universal adapter: reach plugins that never heard of Djinn through the one extensibility
 * contract most of them already implement — the WordPress REST API. Calls are dispatched *in
 * process* via rest_do_request(), so each route runs its own permission_callback against the
 * current user. That is what preserves the core invariant: the Djinn can never do through REST
 * what the logged-in admin could not do themselves. No external HTTP, so no SSRF surface.
 */
class RestRunner {

	/**
	 * Enumerate the site's registered REST routes, optionally narrowed by namespace or a substring
	 * search. Live (not RAG-indexed), so newly-activated plugins appear immediately.
	 *
	 * @return array<int,array{route:string,namespace:string,methods:array<int,string>}>
	 */
	public static function routes( ?string $namespace = null, ?string $search = null, int $limit = 60 ): array {
		$out = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( $route === '/' || strpos( $route, '/batch' ) === 0 ) {
				continue;
			}
			if ( $namespace !== null && $namespace !== '' && strpos( ltrim( $route, '/' ), trim( $namespace, '/' ) ) !== 0 ) {
				continue;
			}
			if ( $search !== null && $search !== '' && stripos( $route, $search ) === false ) {
				continue;
			}

			$methods = array();
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $m ) {
					$methods[ $m ] = true;
				}
			}

			$parts = explode( '/', ltrim( $route, '/' ) );
			$out[] = array(
				'route'     => $route,
				'namespace' => isset( $parts[1] ) ? $parts[0] . '/' . $parts[1] : ( $parts[0] ?? '' ),
				'methods'   => array_keys( $methods ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Dispatch a single REST request internally and return a compact, model-friendly result.
	 *
	 * @param array<string,mixed> $body   Request body params (for writes).
	 * @param array<string,mixed> $params Query params (for reads/filters).
	 * @return array<string,mixed>
	 */
	public static function execute( string $path, string $method, array $body = array(), array $params = array() ): array {
		$method  = strtoupper( $method );
		$request = new \WP_REST_Request( $method, $path );

		foreach ( $params as $k => $v ) {
			$request->set_param( (string) $k, $v );
		}
		if ( $body ) {
			$request->set_body_params( $body );
			foreach ( $body as $k => $v ) {
				$request->set_param( (string) $k, $v );
			}
		}

		$server   = rest_get_server();
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$err = $response->as_error();
			return array(
				'status' => $response->get_status(),
				'error'  => $err->get_error_message(),
				'code'   => $err->get_error_code(),
			);
		}

		return array(
			'status' => $response->get_status(),
			'data'   => $server->response_to_data( $response, false ),
		);
	}
}
