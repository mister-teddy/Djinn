<?php

declare( strict_types=1 );

namespace Djinn\Engine;

use Djinn\GraphQL\Runner;
use Djinn\Provider\ProviderFactory;
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

	private const MAX_ROUNDS = 8;

	/**
	 * Start a turn from a new user wish.
	 *
	 * @return array<string,mixed>
	 */
	public function run( int $chatId, string $userText ): array {
		Repository::addMessage(
			$chatId,
			[ 'role' => 'user', 'content' => Guard::sanitize( $userText ) ]
		);
		return $this->attachUsage( $chatId, $this->loop( $chatId ) );
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

		if ( $confirmed ) {
			$result = $this->executeGraphql( (string) $pending['operation'], (array) $pending['variables'] );
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
				'name'         => 'run_graphql',
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

	/** @return array<string,mixed> */
	private function loop( int $chatId ): array {
		// Attribute every provider call in this run (chat + schema-search embeddings) to the chat.
		UsageRecorder::forChat( $chatId );

		$provider = ProviderFactory::make();
		$system   = SystemPrompt::build();
		$tools    = Tools::specs();

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			$history = Repository::getMessages( $chatId );

			try {
				$turn = $provider->chat( $system, $history, $tools );
			} catch ( Throwable $e ) {
				return [ 'status' => 'error', 'message' => $e->getMessage(), 'chat_id' => $chatId ];
			}

			$calls = $turn['tool_calls'] ?? [];

			// No tool call → this is the final reply.
			if ( empty( $calls ) ) {
				$text = (string) ( $turn['content'] ?? '' );
				Repository::addMessage( $chatId, [ 'role' => 'assistant', 'content' => $text ] );
				return [ 'status' => 'complete', 'message' => $text, 'chat_id' => $chatId ];
			}

			// Handle exactly one tool call to keep the history consistent.
			$call = $calls[0];
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
					$pendingId  = Repository::createPending( $chatId, (string) $call['id'], $operation, $variables, $summary );
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
