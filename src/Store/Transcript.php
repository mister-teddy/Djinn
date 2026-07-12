<?php

declare( strict_types=1 );

namespace Djinn\Store;

use Djinn\GraphQL\Runner;
use Throwable;

/**
 * A replayable view of a conversation: wishes, Djinn's replies, surfaced tool actions, and any
 * write still awaiting a grant.
 *
 * Calls are paired with their results by ORDER, not by tool_call_id — the loop runs exactly one
 * tool per assistant turn and its result follows immediately, and some providers reuse the same id
 * for every call. A surfaced write call that never gets a result is pending confirmation.
 */
class Transcript {

	/** @return array<int,array<string,mixed>> */
	public static function of( int $chatId ): array {
		$out      = array();
		$awaiting = -1; // index in $out of a surfaced action awaiting its result; -2 = ignore

		foreach ( Repository::getMessages( $chatId ) as $entry ) {
			$role = $entry['role'] ?? '';

			if ( $role === 'user' ) {
				$content     = (string) ( $entry['content'] ?? '' );
				$attachments = $entry['attachments'] ?? array();
					if ( $content !== '' || $attachments ) {
						$msg = array(
							'id'      => (int) ( $entry['_id'] ?? 0 ),
							'role'    => 'user',
							'content' => $content,
						);
					if ( $attachments ) {
						$msg['attachments'] = $attachments;
					}
					$out[] = $msg;
				}
				continue;
			}

			if ( $role === 'tool' ) {
				if ( $awaiting >= 0 ) {
					$out[ $awaiting ] = self::applyResult( $out[ $awaiting ], $entry );
				}
				$awaiting = -1;
				continue;
			}

			if ( $role !== 'assistant' ) {
				continue;
			}

				if ( ! empty( $entry['content'] ) ) {
					$out[] = array(
						'id'      => (int) ( $entry['_id'] ?? 0 ),
						'role'    => 'assistant',
						'content' => (string) $entry['content'],
					);
				}
			foreach ( $entry['tool_calls'] ?? array() as $call ) {
				$name   = $call['name'] ?? '';
					$action = self::baseAction( $call );
					if ( $action ) {
						$action['id'] = (int) ( $entry['_id'] ?? 0 );
						$out[]    = $action;
						$awaiting = count( $out ) - 1;
					} elseif ( $name !== '' ) {
					$awaiting = -2; // e.g. rest_call reads — consume the result, don't surface it
				}
			}
		}

		// A surfaced write action that never received a result is still awaiting a grant.
		if ( $awaiting >= 0 ) {
			$open              = Repository::openPending( $chatId );
			$out[ $awaiting ] = $open ? PendingWish::transcriptEntry( $open ) : self::pendingFromAction( $out[ $awaiting ] );
		}

		return $out;
	}

	/**
	 * A surfaced tool call as a transcript entry, before its result is known.
	 *
	 * @param array<string,mixed> $call
	 * @return array<string,mixed>|null Null for tool calls that should stay hidden from transcript.
	 */
	private static function baseAction( array $call ): ?array {
		$name = (string) ( $call['name'] ?? '' );
		if ( $name === 'rest_call' ) {
			return self::restAction( $call );
		}
		if ( $name !== 'run_graphql' ) {
			return null;
		}

		$args      = $call['arguments'] ?? array();
		$operation = (string) ( $args['operation'] ?? '' );

		try {
			$kind = Runner::operationType( $operation );
		} catch ( Throwable $e ) {
			$kind = 'query';
		}

		$entry = array(
			'role'      => 'action',
			'kind'      => $kind,
			'status'    => self::isWriteActionKind( $kind ) ? 'granted' : 'ok', // refined once the result arrives
			'operation' => $operation,
			'variables' => (array) ( $args['variables'] ?? array() ),
		);
		if ( ! empty( $args['summary'] ) ) {
			$entry['summary'] = (string) $args['summary'];
		}
		return $entry;
	}

	/**
	 * @param array<string,mixed> $call
	 * @return array<string,mixed>|null
	 */
	private static function restAction( array $call ): ?array {
		$args   = (array) ( $call['arguments'] ?? array() );
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		if ( ! PendingWish::isRestWrite( $method ) ) {
			return null;
		}

		$path      = (string) ( $args['path'] ?? '' );
		$variables = PendingWish::restVariables(
			$method,
			(array) ( $args['body'] ?? array() ),
			(array) ( $args['params'] ?? array() )
		);
		$pending   = array(
			'kind'      => PendingWish::KIND_REST,
			'operation' => $path,
			'variables' => $variables,
			'summary'   => (string) ( $args['summary'] ?? "$method $path" ),
		);

		return array_merge(
			array(
				'role'   => 'action',
				'status' => 'granted',
			),
			PendingWish::displayPayload( $pending )
		);
	}

	/**
	 * @param array<string,mixed> $action
	 * @return array<string,mixed>
	 */
	private static function pendingFromAction( array $action ): array {
			return array(
				'id'         => (int) ( $action['id'] ?? 0 ),
				'role'       => 'pending',
				'pending_id' => 0,
			'kind'       => (string) ( $action['kind'] ?? '' ),
			'summary'    => (string) ( $action['summary'] ?? '' ),
			'operation'  => (string) ( $action['operation'] ?? '' ),
			'variables'  => (array) ( $action['variables'] ?? array() ),
		);
	}

	/**
	 * Fold a tool result into its action entry: refused, error (with message), or success with the
	 * tool response payload.
	 *
	 * @param array<string,mixed> $action
	 * @param array<string,mixed> $toolEntry
	 * @return array<string,mixed>
	 */
	private static function applyResult( array $action, array $toolEntry ): array {
		$res = json_decode( (string) ( $toolEntry['content'] ?? '' ), true );
		if ( ! is_array( $res ) ) {
			return $action;
		}
		if ( ! empty( $res['refused'] ) ) {
			$action['status'] = 'refused';
		} elseif ( isset( $res['error'] ) ) {
			$action['status']  = 'error';
			$action['message'] = (string) $res['error'];
		} else {
			$action['status'] = self::isWriteActionKind( (string) ( $action['kind'] ?? '' ) ) ? 'granted' : 'ok';
			$action['result'] = $res; // the tool response the Djinn got back
		}
		return $action;
	}

	private static function isWriteActionKind( string $kind ): bool {
		return $kind === 'mutation' || $kind === PendingWish::KIND_REST;
	}
}
