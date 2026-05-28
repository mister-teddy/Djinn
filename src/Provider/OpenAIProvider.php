<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Usage\UsageRecorder;

/**
 * OpenAI Chat Completions adapter. The normalized format mirrors OpenAI's, so the mapping is
 * mostly 1:1 (tool_calls / role:tool messages, function-call arguments as JSON).
 */
class OpenAIProvider implements Provider {

	use Http;

	private const CHAT_URL  = 'https://api.openai.com/v1/chat/completions';
	private const EMBED_URL = 'https://api.openai.com/v1/embeddings';

	public function __construct(
		private string $apiKey,
		private string $chatModel,
		private string $embeddingModel
	) {}

	public function embeddingModel(): string {
		return $this->embeddingModel;
	}

	public function chat( string $system, array $messages, array $tools ): array {
		$payload = [
			'model'    => $this->chatModel,
			'messages' => array_merge( [ [ 'role' => 'system', 'content' => $system ] ], array_map( [ $this, 'mapMessage' ], $messages ) ),
		];
		if ( ! empty( $tools ) ) {
			$payload['tools']       = array_map( [ $this, 'mapTool' ], $tools );
			$payload['tool_choice'] = 'auto';
		}

		$json    = $this->postJson( self::CHAT_URL, [ 'Authorization' => 'Bearer ' . $this->apiKey ], $payload );
		$message = $json['choices'][0]['message'] ?? [];

		$usage = $json['usage'] ?? [];
		UsageRecorder::record(
			'openai',
			$this->chatModel,
			'chat',
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 )
		);

		$toolCalls = [];
		foreach ( $message['tool_calls'] ?? [] as $call ) {
			$toolCalls[] = [
				'id'        => (string) ( $call['id'] ?? '' ),
				'name'      => (string) ( $call['function']['name'] ?? '' ),
				'arguments' => json_decode( $call['function']['arguments'] ?? '{}', true ) ?: [],
			];
		}

		return [
			'content'    => $message['content'] ?? null,
			'tool_calls' => $toolCalls,
		];
	}

	public function embed( array $texts ): array {
		if ( empty( $texts ) ) {
			return [];
		}
		$json = $this->postJson(
			self::EMBED_URL,
			[ 'Authorization' => 'Bearer ' . $this->apiKey ],
			[ 'model' => $this->embeddingModel, 'input' => array_values( $texts ) ]
		);

		$usage = $json['usage'] ?? [];
		UsageRecorder::record( 'openai', $this->embeddingModel, 'embed', (int) ( $usage['prompt_tokens'] ?? 0 ), 0 );

		return array_map( static fn( $row ) => $row['embedding'], $json['data'] ?? [] );
	}

	/** @param array<string,mixed> $entry */
	private function mapMessage( array $entry ): array {
		$role = $entry['role'] ?? 'user';

		if ( $role === 'tool' ) {
			return [
				'role'         => 'tool',
				'tool_call_id' => $entry['tool_call_id'] ?? '',
				'content'      => (string) ( $entry['content'] ?? '' ),
			];
		}

		if ( $role === 'assistant' && ! empty( $entry['tool_calls'] ) ) {
			return [
				'role'       => 'assistant',
				'content'    => $entry['content'] ?? null,
				'tool_calls' => array_map(
					static fn( $tc ) => [
						'id'       => $tc['id'],
						'type'     => 'function',
						'function' => [
							'name'      => $tc['name'],
							'arguments' => wp_json_encode( $tc['arguments'] ?? [] ),
						],
					],
					$entry['tool_calls']
				),
			];
		}

		return [ 'role' => $role, 'content' => (string) ( $entry['content'] ?? '' ) ];
	}

	/** @param array<string,mixed> $tool */
	private function mapTool( array $tool ): array {
		return [
			'type'     => 'function',
			'function' => [
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $tool['parameters'],
			],
		];
	}
}
