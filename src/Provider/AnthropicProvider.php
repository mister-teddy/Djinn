<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Usage\UsageRecorder;

/**
 * Anthropic (Claude) Messages API adapter. Maps Djinn's normalized message/tool format onto
 * Anthropic's shape: `system` is a top-level field, tool calls are `tool_use` content blocks, and
 * tool results come back as a `tool_result` block inside a following user turn.
 *
 * Anthropic has no embeddings API, so embed() is a no-op — retrieval falls back to the full schema
 * (see Retriever), which is small enough to hand Claude whole.
 */
class AnthropicProvider implements Provider {

	use Http;

	private const VERSION   = '2023-06-01';
	private const MAX_TOKENS = 4096;

	public function __construct(
		protected string $apiKey,
		protected string $chatModel,
		protected string $embeddingModel = '',
		protected string $baseUrl = 'https://api.anthropic.com'
	) {}

	public function embeddingModel(): string {
		return 'none';
	}

	/** Anthropic has no embeddings endpoint; return empty so retrieval uses the full schema. */
	public function embed( array $texts ): array {
		return [];
	}

	protected function providerLabel(): string {
		return 'anthropic';
	}

	/** Auth headers; the Max-subscription subclass overrides this for OAuth. */
	protected function authHeaders(): array {
		return [ 'x-api-key' => $this->apiKey, 'anthropic-version' => self::VERSION ];
	}

	public function chat( string $system, array $messages, array $tools ): array {
		$payload = [
			'model'      => $this->chatModel,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $system,
			'messages'   => $this->mapMessages( $messages ),
		];
		if ( ! empty( $tools ) ) {
			$payload['tools'] = $this->mapTools( $tools );
		}

		$json = $this->postJson( $this->baseUrl . '/v1/messages', $this->authHeaders(), $payload );

		$usage = $json['usage'] ?? [];
		UsageRecorder::record( $this->providerLabel(), $this->chatModel, 'chat', (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ) );

		$text      = '';
		$toolCalls = [];
		foreach ( $json['content'] ?? [] as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text .= (string) $block['text'];
			} elseif ( ( $block['type'] ?? '' ) === 'tool_use' ) {
				$toolCalls[] = [ 'id' => (string) $block['id'], 'name' => (string) $block['name'], 'arguments' => (array) ( $block['input'] ?? [] ) ];
			}
		}
		return [ 'content' => $text !== '' ? $text : null, 'tool_calls' => $toolCalls ];
	}

	public function chatStream( string $system, array $messages, array $tools, callable $onDelta ): array {
		$payload = [
			'model'      => $this->chatModel,
			'max_tokens' => self::MAX_TOKENS,
			'stream'     => true,
			'system'     => $system,
			'messages'   => $this->mapMessages( $messages ),
		];
		if ( ! empty( $tools ) ) {
			$payload['tools'] = $this->mapTools( $tools );
		}

		$text   = '';
		$blocks = []; // index => ['type','id','name','json']
		$usage  = [ 'input' => 0, 'output' => 0 ];
		$buffer = '';

		$this->postStream( $this->baseUrl . '/v1/messages', $this->authHeaders(), $payload, function ( $chunk ) use ( &$buffer, &$text, &$blocks, &$usage, $onDelta ) {
			$buffer .= $chunk;
			while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
				$line   = trim( substr( $buffer, 0, $nl ) );
				$buffer = substr( $buffer, $nl + 1 );
				if ( strpos( $line, 'data:' ) !== 0 ) {
					continue;
				}
				$j = json_decode( trim( substr( $line, 5 ) ), true );
				if ( ! is_array( $j ) ) {
					continue;
				}
				switch ( $j['type'] ?? '' ) {
					case 'message_start':
						$usage['input'] = (int) ( $j['message']['usage']['input_tokens'] ?? 0 );
						break;
					case 'content_block_start':
						$cb = $j['content_block'] ?? [];
						$blocks[ (int) ( $j['index'] ?? 0 ) ] = [ 'type' => $cb['type'] ?? 'text', 'id' => $cb['id'] ?? '', 'name' => $cb['name'] ?? '', 'json' => '' ];
						break;
					case 'content_block_delta':
						$d = $j['delta'] ?? [];
						if ( ( $d['type'] ?? '' ) === 'text_delta' ) {
							$text .= (string) $d['text'];
							$onDelta( (string) $d['text'] );
						} elseif ( ( $d['type'] ?? '' ) === 'input_json_delta' ) {
							$i = (int) ( $j['index'] ?? 0 );
							$blocks[ $i ]['json'] = ( $blocks[ $i ]['json'] ?? '' ) . (string) $d['partial_json'];
						}
						break;
					case 'message_delta':
						$usage['output'] = (int) ( $j['usage']['output_tokens'] ?? $usage['output'] );
						break;
				}
			}
		} );

		UsageRecorder::record( $this->providerLabel(), $this->chatModel, 'chat', $usage['input'], $usage['output'] );

		$toolCalls = [];
		foreach ( $blocks as $b ) {
			if ( ( $b['type'] ?? '' ) === 'tool_use' ) {
				$toolCalls[] = [ 'id' => (string) $b['id'], 'name' => (string) $b['name'], 'arguments' => json_decode( $b['json'] ?: '{}', true ) ?: [] ];
			}
		}
		return [ 'content' => $text !== '' ? $text : null, 'tool_calls' => $toolCalls ];
	}

	/** @param array<int,array<string,mixed>> $messages */
	private function mapMessages( array $messages ): array {
		$out = [];
		foreach ( $messages as $m ) {
			$role = $m['role'] ?? 'user';

			if ( $role === 'tool' ) {
				$out[] = [
					'role'    => 'user',
					'content' => [ [ 'type' => 'tool_result', 'tool_use_id' => (string) ( $m['tool_call_id'] ?? '' ), 'content' => (string) ( $m['content'] ?? '' ) ] ],
				];
				continue;
			}

			if ( $role === 'assistant' && ! empty( $m['tool_calls'] ) ) {
				$content = [];
				if ( ! empty( $m['content'] ) ) {
					$content[] = [ 'type' => 'text', 'text' => (string) $m['content'] ];
				}
				foreach ( $m['tool_calls'] as $tc ) {
					$content[] = [ 'type' => 'tool_use', 'id' => (string) $tc['id'], 'name' => (string) $tc['name'], 'input' => (object) ( $tc['arguments'] ?? [] ) ];
				}
				$out[] = [ 'role' => 'assistant', 'content' => $content ];
				continue;
			}

			$out[] = [ 'role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => (string) ( $m['content'] ?? '' ) ];
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $tools
	 * @return array<int,array<string,mixed>>
	 */
	private function mapTools( array $tools ): array {
		return array_map(
			static fn( $t ) => [ 'name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['parameters'] ],
			$tools
		);
	}
}
