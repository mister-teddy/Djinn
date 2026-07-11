<?php

declare( strict_types=1 );

namespace Djinn\Engine;

use Djinn\GraphQL\Runner;
use Djinn\Provider\ProviderFactory;
use Djinn\Provider\ProxyProvider;
use Djinn\Security\Guard;
use Djinn\Store\PendingWish;
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
	public function run( int $chatId, string $userText, array $attachments = array() ): array {
		try {
			$result = $this->startRun( $chatId, $userText, $attachments );
		} catch ( Throwable $e ) {
			$result = $this->errorResult( $chatId, $e->getMessage() );
		}
		return $this->attachUsage( $chatId, $result );
	}

	/**
	 * Streaming variant of run(): drives the loop with an emitter that pushes 'step'/'delta'
	 * events, then emits a terminal 'done' | 'pending' | 'error' event. The transcript is persisted
	 * identically to run(), so a later reload shows the same canonical history.
	 *
	 * @param callable(string,array):void $emit
	 */
	public function streamRun( int $chatId, string $userText, callable $emit, array $attachments = array() ): void {
		try {
			$result = $this->startRun( $chatId, $userText, $attachments, $emit );
		} catch ( Throwable $e ) {
			$emit(
				'error',
				$this->errorResult( $chatId, $e->getMessage() )
			);
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
	 * the paused write tool call, then continue the loop.
	 *
	 * @return array<string,mixed>
	 */
	public function resume( int $chatId, int $pendingId, bool $confirmed ): array {
		$status  = $confirmed ? PendingWish::STATUS_CONFIRMED : PendingWish::STATUS_CANCELLED;
		$pending = Repository::claimPending( $pendingId, $chatId );
		if ( ! $pending ) {
			return array(
				'status'  => 'error',
				'message' => 'That wish is no longer pending.',
			);
		}

		if ( $confirmed ) {
			$result = $this->executePending( $pending );
		} else {
			$result = array(
				'refused' => true,
				'message' => 'The user refused this wish; it was not granted.',
			);
		}

		$this->addPendingToolResult( $chatId, $pending, $result );
		Repository::finishPending( $pendingId, $status );

		try {
			$result = $this->loop( $chatId );
		} catch ( Throwable $e ) {
			$result = $this->errorResult( $chatId, $e->getMessage() );
		}
		return $this->attachUsage( $chatId, $result );
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

	/** @return array<string,mixed> */
	private function errorResult( int $chatId, string $message ): array {
		return array(
			'status'  => 'error',
			'message' => $message,
			'chat_id' => $chatId,
		);
	}

	/**
	 * Start or stream a user turn only when the previous write has been resolved. This keeps the
	 * provider transcript valid: every assistant tool call must be followed by its tool result
	 * before another user message is appended.
	 *
	 * @param callable(string,array):void|null $emit
	 * @return array<string,mixed>
	 */
	private function startRun( int $chatId, string $userText, array $attachments = array(), ?callable $emit = null ): array {
		$pending = Repository::openPending( $chatId );
		if ( $pending ) {
			return PendingWish::response( $chatId, $pending );
		}

		Repository::addMessage( $chatId, $this->userEntry( $userText, $attachments ) );
		return $this->loop( $chatId, $emit );
	}

	/**
	 * The stored user turn. Attachments ride as metadata so the chat UI can show a chip and the
	 * typed text stays verbatim; expandAttachments() folds the import token into the content only
	 * when the turn is replayed to the provider.
	 *
	 * @param array<int,array{filename:string,token:string,size:int,mime:string}> $attachments
	 * @return array<string,mixed>
	 */
	private function userEntry( string $userText, array $attachments ): array {
		$entry = array(
			'role'    => 'user',
			'content' => Guard::sanitize( $userText ),
		);
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
			$notes = array();
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
		// Attribute every provider call in this run to the chat.
		UsageRecorder::forChat( $chatId );

		$provider = ProviderFactory::make();
		ProxyProvider::setConversation( (string) $chatId );
		$override = SystemPrompt::proxyOverride();
		$system   = $override !== ''
			? $override . "\n\n" . SystemPrompt::context() . "\n\n" . SystemPrompt::schema()
			: SystemPrompt::build();
		$tools    = Tools::specs();
		$stalls   = 0;

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			// Each round is one provider call (up to a 60s HTTP timeout) plus a local tool exec. Reset
			// the per-request execution clock each round so a long multi-round wish isn't killed
			// mid-turn by max_execution_time. Hosts that disable set_time_limit simply skip this.
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- reset the execution clock each round so a long multi-round wish isn't killed mid-turn.
				set_time_limit( 120 );
			}
			$history = $this->expandAttachments( Repository::getMessages( $chatId ) );

			try {
				$turn = $emit
					? $provider->chatStream( $system, $history, $tools, static fn( $d ) => $emit( 'delta', array( 'token' => $d ) ) )
					: $provider->chat( $system, $history, $tools );
			} catch ( Throwable $e ) {
				return $this->errorResult( $chatId, $e->getMessage() );
			}

			$calls = $turn['tool_calls'] ?? array();

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
				Repository::addMessage(
					$chatId,
					array(
						'role'    => 'assistant',
						'content' => $text,
					)
				);
				return array(
					'status'  => 'complete',
					'message' => $text,
					'chat_id' => $chatId,
				);
			}

			// Handle exactly one tool call to keep the history consistent.
			$call = $calls[0];
			if ( $emit ) {
				$emit( 'step', array( 'label' => $this->stepLabel( (string) $call['name'] ) ) );
			}
			Repository::addMessage(
				$chatId,
				array(
					'role'       => 'assistant',
					'content'    => $turn['content'] ?? null,
					'tool_calls' => array( $call ),
				)
			);

			if ( $call['name'] === 'run_graphql' ) {
				$operation = (string) ( $call['arguments']['operation'] ?? '' );
				$variables = (array) ( $call['arguments']['variables'] ?? array() );

				try {
					$type = Runner::operationType( $operation );
				} catch ( Throwable $e ) {
					$this->addToolResult( $chatId, $call, array( 'error' => 'Could not parse GraphQL: ' . $e->getMessage() ) );
					continue;
				}

				if ( $type === 'mutation' ) {
					$summary = (string) ( $call['arguments']['summary'] ?? 'Grant a GraphQL mutation.' );
					return $this->awaitConfirmation( $chatId, $call, PendingWish::KIND_GRAPHQL, $operation, $variables, $summary );
				}

				$result = $this->executeGraphql( $operation, $variables );
				$this->addToolResult( $chatId, $call, $result );
				continue;
			}

			if ( $call['name'] === 'rest_call' ) {
				$method = strtoupper( (string) ( $call['arguments']['method'] ?? 'GET' ) );
				$path   = (string) ( $call['arguments']['path'] ?? '' );
				$body   = (array) ( $call['arguments']['body'] ?? array() );
				$params = (array) ( $call['arguments']['params'] ?? array() );

				if ( $path === '' || $path[0] !== '/' ) {
					$this->addToolResult( $chatId, $call, array( 'error' => 'path must be a REST route beginning with "/".' ) );
					continue;
				}

				// Writes (POST/PUT/PATCH/DELETE) are gated like mutations; the method — not a GraphQL
				// operation type — is the trustworthy read/write signal for REST.
				if ( PendingWish::isRestWrite( $method ) ) {
					$summary = (string) ( $call['arguments']['summary'] ?? "$method $path" );
					return $this->awaitConfirmation(
						$chatId,
						$call,
						PendingWish::KIND_REST,
						$path,
						PendingWish::restVariables( $method, $body, $params ),
						$summary
					);
				}

				$result = $this->executeRestCall( $path, $method, $body, $params );
				$this->addToolResult( $chatId, $call, $result );
				continue;
			}

			// Unknown tool — tell the model so it can recover.
			$this->addToolResult( $chatId, $call, array( 'error' => 'Unknown tool: ' . $call['name'] ) );
		}

		$msg = 'The lamp grew dim before I could finish. Could you narrow the wish?';
		Repository::addMessage(
			$chatId,
			array(
				'role'    => 'assistant',
				'content' => $msg,
			)
		);
		return array(
			'status'  => 'complete',
			'message' => $msg,
			'chat_id' => $chatId,
		);
	}

	/**
	 * @param array<string,mixed> $call
	 * @param array<string,mixed> $variables
	 * @return array<string,mixed>
	 */
	private function awaitConfirmation( int $chatId, array $call, string $kind, string $operation, array $variables, string $summary ): array {
		$pendingId = Repository::createPending( $chatId, (string) $call['id'], $kind, $operation, $variables, $summary );
		return PendingWish::response(
			$chatId,
			array(
				'id'           => $pendingId,
				'chat_id'      => $chatId,
				'tool_call_id' => (string) $call['id'],
				'kind'         => $kind,
				'operation'    => $operation,
				'variables'    => $variables,
				'summary'      => $summary,
				'status'       => PendingWish::STATUS_PENDING,
			)
		);
	}

	/**
	 * @param array<string,mixed> $pending
	 * @param array<string,mixed> $result
	 */
	private function addPendingToolResult( int $chatId, array $pending, array $result ): void {
		Repository::addMessage(
			$chatId,
			array(
				'role'         => 'tool',
				'tool_call_id' => (string) $pending['tool_call_id'],
				'name'         => PendingWish::toolName( $pending ),
				'content'      => (string) wp_json_encode( $result ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $pending
	 * @return array<string,mixed>
	 */
	private function executePending( array $pending ): array {
		try {
			if ( PendingWish::toolName( $pending ) === 'rest_call' ) {
				$v = (array) $pending['variables'];
				return $this->executeRestCall(
					(string) $pending['operation'],
					(string) ( $v['method'] ?? 'GET' ),
					(array) ( $v['body'] ?? array() ),
					(array) ( $v['params'] ?? array() )
				);
			}

			return Runner::execute( (string) $pending['operation'], (array) $pending['variables'] );
		} catch ( Throwable $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * @param array<string,mixed> $call
	 * @param array<string,mixed> $result
	 */
	private function addToolResult( int $chatId, array $call, array $result ): void {
		Repository::addMessage(
			$chatId,
			array(
				'role'         => 'tool',
				'tool_call_id' => (string) $call['id'],
				'name'         => (string) $call['name'],
				'content'      => (string) wp_json_encode( $result ),
			)
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
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * @param array<string,mixed> $body
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function executeRestCall( string $path, string $method, array $body, array $params ): array {
		$result = apply_filters( 'djinn_execute_rest_call', null, $path, strtoupper( $method ), $body, $params );
		return is_array( $result ) ? $result : array( 'error' => 'The REST tool is not available on this site.' );
	}
}
