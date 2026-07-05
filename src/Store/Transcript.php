<?php

declare( strict_types=1 );

namespace Djinn\Store;

use Djinn\GraphQL\Runner;
use Throwable;

/**
 * A replayable view of a conversation: wishes, Djinn's replies, the GraphQL operations it ran
 * (read-only "incantation" cards), and any mutation still awaiting a grant.
 *
 * Calls are paired with their results by ORDER, not by tool_call_id — the loop runs exactly one
 * tool per assistant turn and its result follows immediately, and some providers reuse the same id
 * for every call. A run_graphql call that never gets a result is a pending mutation.
 */
class Transcript {

	/** @return array<int,array<string,mixed>> */
	public static function of( int $chatId ): array {
		$out      = array();
		$awaiting = -1; // index in $out of a run_graphql action awaiting its result; -2 = ignore

		foreach ( Repository::getMessages( $chatId ) as $entry ) {
			$role = $entry['role'] ?? '';

			if ( $role === 'user' ) {
				$content     = (string) ( $entry['content'] ?? '' );
				$attachments = $entry['attachments'] ?? array();
				if ( $content !== '' || $attachments ) {
					$msg = array(
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
					'role'    => 'assistant',
					'content' => (string) $entry['content'],
				);
			}
			foreach ( $entry['tool_calls'] ?? array() as $call ) {
				$name = $call['name'] ?? '';
				if ( $name === 'run_graphql' ) {
					$out[]    = self::baseAction( $call );
					$awaiting = count( $out ) - 1;
				} elseif ( $name !== '' ) {
					$awaiting = -2; // e.g. rest_call — consume its result, don't surface it
				}
			}
		}

		// A run_graphql action that never received a result is a mutation still awaiting a grant.
		if ( $awaiting >= 0 ) {
			$open             = Repository::openPending( $chatId );
			$action           = $out[ $awaiting ];
			$out[ $awaiting ] = array(
				'role'       => 'pending',
				'pending_id' => $open ? (int) $open['id'] : 0,
				'summary'    => $action['summary'] ?? ( $open['summary'] ?? '' ),
				'operation'  => $action['operation'],
				'variables'  => $action['variables'],
			);
		}

		return $out;
	}

	/**
	 * A run_graphql call as a transcript entry, before its result is known. The summary comes
	 * straight from the call's arguments (the model supplies one for mutations).
	 *
	 * @param array<string,mixed> $call
	 * @return array<string,mixed>
	 */
	private static function baseAction( array $call ): array {
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
			'status'    => $kind === 'mutation' ? 'granted' : 'ok', // refined once the result arrives
			'operation' => $operation,
			'variables' => (array) ( $args['variables'] ?? array() ),
		);
		if ( ! empty( $args['summary'] ) ) {
			$entry['summary'] = (string) $args['summary'];
		}
		return $entry;
	}

	/**
	 * Fold a tool result into its action entry: refused, error (with message), or success (with the
	 * GraphQL response payload).
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
			$action['status'] = $action['kind'] === 'mutation' ? 'granted' : 'ok';
			$action['result'] = $res; // the GraphQL response the Djinn got back
		}
		return $action;
	}
}
