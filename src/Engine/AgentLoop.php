<?php

declare( strict_types=1 );

namespace Djinn\Engine;

use Djinn\GraphQL\Runner;
use Djinn\Provider\ProviderFactory;
use Djinn\Provider\ProxyProvider;
use Djinn\Rag\Retriever;
use Djinn\Security\Guard;
use Djinn\Store\Repository;
use Djinn\Usage\UsageRecorder;
use Throwable;

/**
 * The synchronous multi-turn agent loop. It runs the full LLM↔tool cycle within a single
 * request and returns either a final assistant message or a wish awaiting confirmation.
 *
 * To keep the conversation always-consistent, we handle exactly one tool call per assistant
 * turn (the system prompt asks the model to do the same).
 */
class AgentLoop {

	private const MAX_ROUNDS = 16;

	/**
	 * A turn with neither a tool call nor any text is a provider stall, not an answer — Gemini does
	 * this on MALFORMED_FUNCTION_CALL (it chokes serialising a long GraphQL string) or an empty STOP.
	 * Resampling usually recovers, so we retry a stalled turn this many times before giving up.
	 */
	private const MAX_STALLS = 1;

	/**
	 * Start a turn from a new user wish.
	 *
	 * @return array<string,mixed>
	 */
	public function run( int $chatId, string $userText, array $attachments = [] ): array {
		Repository::addMessage( $chatId, $this->userEntry( $userText, $attachments ) );
		// A fresh user wish — count it against the proxy's free-wish allowance (no-op off-proxy).
		ProxyProvider::markNewWish();
		return $this->attachUsage( $chatId, $this->loop( $chatId ) );
	}

	/**
	 * Streaming variant of run(): drives the loop with an emitter that pushes 'step'/'delta'
	 * events, then emits a terminal 'done' | 'pending' | 'error' event. The transcript is persisted
	 * identically to run(), so a later reload shows the same canonical history.
	 *
	 * @param callable(string,array):void $emit
	 */
	public function streamRun( int $chatId, string $userText, callable $emit, array $attachments = [] ): void {
		Repository::addMessage( $chatId, $this->userEntry( $userText, $attachments ) );
		ProxyProvider::markNewWish();

		try {
			$result = $this->loop( $chatId, $emit );
		} catch ( Throwable $e ) {
			$emit( 'error', [ 'message' => $e->getMessage(), 'chat_id' => $chatId ] );
			return;
		}
		$result = $this->attachUsage( $chatId, $result );

		$status = $result['status'] ?? 'complete';
		if ( $status === 'awaiting_confirmation' ) {
			$emit( 'pending', $result );
		} elseif ( $status === 'error' ) {
			$emit( 'error', $result );
		} else {
			$emit( 'done', $result );
		}
	}

	private function stepLabel( string $tool ): string {
		switch ( $tool ) {
			case 'search_schema':
				return 'Consulting the schema…';
			case 'run_graphql':
				return 'Composing the incantation…';
			case 'rest_call':
				return 'Reaching into WordPress…';
			default:
				return 'Working…';
		}
	}

	/**
	 * Resume after the user granted or refused a pending wish. We append the tool result for
	 * the paused run_graphql call, then continue the loop.
	 *
	 * @return array<string,mixed>
	 */
	public function resume( int $chatId, int $pendingId, bool $confirmed ): array {
		$pending = Repository::getPending( $pendingId );
		if ( ! $pending || (int) $pending['chat_id'] !== $chatId ) {
			return [ 'status' => 'error', 'message' => 'That wish is no longer pending.' ];
		}
		if ( $pending['status'] !== 'pending' ) {
			return [ 'status' => 'error', 'message' => 'That wish was already resolved.' ];
		}

		$isRest   = ( $pending['kind'] ?? 'graphql' ) === 'rest';
		$toolName = $isRest ? 'rest_call' : 'run_graphql';

		if ( $confirmed ) {
			if ( $isRest ) {
				$v      = (array) $pending['variables'];
				$result = RestRunner::execute(
					(string) $pending['operation'],
					(string) ( $v['method'] ?? 'GET' ),
					(array) ( $v['body'] ?? [] ),
					(array) ( $v['params'] ?? [] )
				);
			} else {
				$result = $this->executeGraphql( (string) $pending['operation'], (array) $pending['variables'] );
			}
			Repository::setPendingStatus( $pendingId, 'confirmed' );
		} else {
			$result = [ 'refused' => true, 'message' => 'The user refused this wish; it was not granted.' ];
			Repository::setPendingStatus( $pendingId, 'cancelled' );
		}

		Repository::addMessage(
			$chatId,
			[
				'role'         => 'tool',
				'tool_call_id' => (string) $pending['tool_call_id'],
				'name'         => $toolName,
				'content'      => (string) wp_json_encode( $result ),
			]
		);

		return $this->attachUsage( $chatId, $this->loop( $chatId ) );
	}

	/**
	 * Attach the conversation's running token + cost totals so the in-chat meter can update.
	 *
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function attachUsage( int $chatId, array $result ): array {
		$result['usage'] = Repository::chatUsage( $chatId );
		return $result;
	}

	/**
	 * @param callable(string,array):void|null $emit When set, the turn streams: text deltas are
	 *        sent as 'delta' events and each tool step as a 'step' event.
	 * @return array<string,mixed>
	 */
	/**
	 * The stored user turn. Attachments ride as metadata so the chat UI can show a chip and the
	 * typed text stays verbatim; expandAttachments() folds the import token into the content only
	 * when the turn is replayed to the provider.
	 *
	 * @param array<int,array{filename:string,token:string,size:int}> $attachments
	 * @return array<string,mixed>
	 */
	private function userEntry( string $userText, array $attachments ): array {
		$entry = [ 'role' => 'user', 'content' => Guard::sanitize( $userText ) ];
		if ( $attachments ) {
			$entry['attachments'] = $attachments;
		}
		return $entry;
	}

	/**
	 * Fold attachment metadata into the user content sent to the provider. The model needs the
	 * import token; the system prompt already explains importMedia/importWxr, so a terse note is
	 * enough. The persisted transcript is left untouched (the UI renders chips from the metadata).
	 *
	 * @param array<int,array<string,mixed>> $history
	 * @return array<int,array<string,mixed>>
	 */
	private function expandAttachments( array $history ): array {
		foreach ( $history as &$entry ) {
			if ( ( $entry['role'] ?? '' ) !== 'user' || empty( $entry['attachments'] ) ) {
				continue;
			}
			$notes = [];
			foreach ( $entry['attachments'] as $a ) {
				$token = (string) ( $a['token'] ?? '' );
				if ( $token !== '' ) {
					$notes[] = sprintf( 'Attached file: %s (import token: %s)', (string) ( $a['filename'] ?? 'file' ), $token );
				}
			}
			if ( $notes ) {
				$entry['content'] = trim( (string) ( $entry['content'] ?? '' ) . "\n\n" . implode( "\n", $notes ) );
			}
		}
		unset( $entry );
		return $history;
	}

	private function loop( int $chatId, ?callable $emit = null ): array {
		// Attribute every provider call in this run (chat + schema-search embeddings) to the chat.
		UsageRecorder::forChat( $chatId );

		$provider = ProviderFactory::make();
		ProxyProvider::setConversation( (string) $chatId );
		$override = SystemPrompt::orgOverride();
		$system   = $override !== '' ? $override . "\n\n" . SystemPrompt::context() : SystemPrompt::build();
		$tools    = Tools::specs();
		$stalls   = 0;

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			// Each round is one provider call (up to a 60s HTTP timeout) plus a local tool exec. Reset
			// the per-request execution clock each round so a long multi-round wish isn't killed
			// mid-turn by max_execution_time. Hosts that disable set_time_limit simply skip this.
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 );
			}
			$history = $this->expandAttachments( Repository::getMessages( $chatId ) );

			try {
				$turn = $emit
					? $provider->chatStream( $system, $history, $tools, static fn( $d ) => $emit( 'delta', [ 'token' => $d ] ) )
					: $provider->chat( $system, $history, $tools );
			} catch ( Throwable $e ) {
				return [ 'status' => 'error', 'message' => $e->getMessage(), 'chat_id' => $chatId ];
			}

			$calls = $turn['tool_calls'] ?? [];

			// No tool call → either the final reply, or a stalled empty turn to retry.
			if ( empty( $calls ) ) {
				$text = trim( (string) ( $turn['content'] ?? '' ) );
				if ( $text === '' ) {
					// Nothing was persisted (an empty turn has no parts), so a retry re-runs against
					// identical history and the provider resamples a valid call or answer.
					if ( ++$stalls <= self::MAX_STALLS ) {
						continue;
					}
					$text = 'The lamp flickered and the wish slipped away. Could you put it to me again?';
				}
				Repository::addMessage( $chatId, [ 'role' => 'assistant', 'content' => $text ] );
				return [ 'status' => 'complete', 'message' => $text, 'chat_id' => $chatId ];
			}

			// Handle exactly one tool call to keep the history consistent.
			$call = $calls[0];
			if ( $emit ) {
				$emit( 'step', [ 'label' => $this->stepLabel( (string) $call['name'] ) ] );
			}
			Repository::addMessage(
				$chatId,
				[
					'role'       => 'assistant',
					'content'    => $turn['content'] ?? null,
					'tool_calls' => [ $call ],
				]
			);

			if ( $call['name'] === 'search_schema' ) {
				$fragments = Retriever::search( (string) ( $call['arguments']['query'] ?? '' ) );
				$this->addToolResult( $chatId, $call, [ 'schema' => $fragments ] );
				continue;
			}

			if ( $call['name'] === 'run_graphql' ) {
				$operation = (string) ( $call['arguments']['operation'] ?? '' );
				$variables = (array) ( $call['arguments']['variables'] ?? [] );

				try {
					$type = Runner::operationType( $operation );
				} catch ( Throwable $e ) {
					$this->addToolResult( $chatId, $call, [ 'error' => 'Could not parse GraphQL: ' . $e->getMessage() ] );
					continue;
				}

				if ( $type === 'mutation' ) {
					$summary    = (string) ( $call['arguments']['summary'] ?? 'Grant a GraphQL mutation.' );
					$pendingId  = Repository::createPending( $chatId, (string) $call['id'], 'graphql', $operation, $variables, $summary );
					return [
						'status'  => 'awaiting_confirmation',
						'chat_id' => $chatId,
						'pending' => [
							'id'        => $pendingId,
							'summary'   => $summary,
							'operation' => $operation,
							'variables' => $variables,
						],
					];
				}

				$result = $this->executeGraphql( $operation, $variables );
				$this->addToolResult( $chatId, $call, $result );
				continue;
			}

			if ( $call['name'] === 'rest_call' ) {
				$method = strtoupper( (string) ( $call['arguments']['method'] ?? 'GET' ) );
				$path   = (string) ( $call['arguments']['path'] ?? '' );
				$body   = (array) ( $call['arguments']['body'] ?? [] );
				$params = (array) ( $call['arguments']['params'] ?? [] );

				if ( $path === '' || $path[0] !== '/' ) {
					$this->addToolResult( $chatId, $call, [ 'error' => 'path must be a REST route beginning with "/".' ] );
					continue;
				}

				// Writes (POST/PUT/PATCH/DELETE) are gated like mutations; the method — not a GraphQL
				// operation type — is the trustworthy read/write signal for REST.
				if ( in_array( $method, RestRunner::WRITE_METHODS, true ) ) {
					$summary   = (string) ( $call['arguments']['summary'] ?? "$method $path" );
					$pendingId = Repository::createPending(
						$chatId,
						(string) $call['id'],
						'rest',
						$path,
						[ 'method' => $method, 'body' => $body, 'params' => $params ],
						$summary
					);
					return [
						'status'  => 'awaiting_confirmation',
						'chat_id' => $chatId,
						'pending' => [
							'id'        => $pendingId,
							'summary'   => $summary,
							'operation' => "$method $path",
							'variables' => $body,
						],
					];
				}

				$result = RestRunner::execute( $path, $method, $body, $params );
				$this->addToolResult( $chatId, $call, $result );
				continue;
			}

			// Unknown tool — tell the model so it can recover.
			$this->addToolResult( $chatId, $call, [ 'error' => 'Unknown tool: ' . $call['name'] ] );
		}

		$msg = 'The lamp grew dim before I could finish. Could you narrow the wish?';
		Repository::addMessage( $chatId, [ 'role' => 'assistant', 'content' => $msg ] );
		return [ 'status' => 'complete', 'message' => $msg, 'chat_id' => $chatId ];
	}

	/**
	 * @param array<string,mixed> $call
	 * @param array<string,mixed> $result
	 */
	private function addToolResult( int $chatId, array $call, array $result ): void {
		Repository::addMessage(
			$chatId,
			[
				'role'         => 'tool',
				'tool_call_id' => (string) $call['id'],
				'name'         => (string) $call['name'],
				'content'      => (string) wp_json_encode( $result ),
			]
		);
	}

	/**
	 * @param array<string,mixed> $variables
	 * @return array<string,mixed>
	 */
	private function executeGraphql( string $operation, array $variables ): array {
		try {
			return Runner::execute( $operation, $variables );
		} catch ( Throwable $e ) {
			return [ 'error' => $e->getMessage() ];
		}
	}
}
