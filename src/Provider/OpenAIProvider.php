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

	private string $apiKey;
	private string $chatModel;
	private string $baseUrl;

	public function __construct( string $apiKey, string $chatModel, string $baseUrl = 'https://api.openai.com/v1' ) {
		$this->apiKey    = $apiKey;
		$this->chatModel = $chatModel;
		$this->baseUrl   = $baseUrl;
	}

	protected function chatUrl(): string {
		return rtrim( $this->baseUrl, '/' ) . '/chat/completions';
	}

	/** Label recorded in usage telemetry (overridden by the proxy adapter). */
	protected function providerLabel(): string {
		return 'openai';
	}

	/** Per-request extra headers; subclasses (e.g. the proxy) add their own. */
	protected function extraHeaders(): array {
		return array();
	}

	private function headers(): array {
		return array_merge( array( 'Authorization' => 'Bearer ' . $this->apiKey ), $this->extraHeaders() );
	}

	public function chat( string $system, array $messages, array $tools ): array {
		$payload = array(
			'model'    => $this->chatModel,
			'messages' => array_merge(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
				),
				array_map( array( $this, 'mapMessage' ), $messages )
			),
		);
		if ( ! empty( $tools ) ) {
			$payload['tools']       = array_map( array( $this, 'mapTool' ), $tools );
			$payload['tool_choice'] = 'auto';
		}

		$json    = $this->postJson( $this->chatUrl(), $this->headers(), $payload );
		$message = $json['choices'][0]['message'] ?? array();

		$usage = $json['usage'] ?? array();
		UsageRecorder::record(
			$this->providerLabel(),
			$this->chatModel,
			'chat',
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 ),
			false,
			$this->reportedCost( $usage )
		);

		$toolCalls = array();
		foreach ( $message['tool_calls'] ?? array() as $call ) {
			$toolCalls[] = array(
				'id'        => (string) ( $call['id'] ?? '' ),
				'name'      => (string) ( $call['function']['name'] ?? '' ),
				'arguments' => json_decode( $call['function']['arguments'] ?? '{}', true ) ?: array(),
			);
		}

		return array(
			'content'    => $message['content'] ?? null,
			'tool_calls' => $toolCalls,
		);
	}

	public function chatStream( string $system, array $messages, array $tools, callable $onDelta ): array {
		$payload = array(
			'model'          => $this->chatModel,
			'stream'         => true,
			'stream_options' => array( 'include_usage' => true ),
			'messages'       => array_merge(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
				),
				array_map( array( $this, 'mapMessage' ), $messages )
			),
		);
		if ( ! empty( $tools ) ) {
			$payload['tools']       = array_map( array( $this, 'mapTool' ), $tools );
			$payload['tool_choice'] = 'auto';
		}

		$content = '';
		$calls   = array(); // index => ['id','name','arguments'(string)]
		$usage   = array();
		$buffer  = '';

		$this->postStream(
			$this->chatUrl(),
			$this->headers(),
			$payload,
			function ( $chunk ) use ( &$buffer, &$content, &$calls, &$usage, $onDelta ) {
				$buffer .= $chunk;
				while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
					$line   = trim( substr( $buffer, 0, $nl ) );
					$buffer = substr( $buffer, $nl + 1 );
					if ( $line === '' || strpos( $line, 'data:' ) !== 0 ) {
						continue;
					}
					$data = trim( substr( $line, 5 ) );
					if ( $data === '[DONE]' ) {
						return;
					}
					$json = json_decode( $data, true );
					if ( ! is_array( $json ) ) {
						continue;
					}
					if ( isset( $json['usage'] ) ) {
						$usage = $json['usage'];
					}
					$delta = $json['choices'][0]['delta'] ?? array();
					if ( isset( $delta['content'] ) && $delta['content'] !== null && $delta['content'] !== '' ) {
						$content .= $delta['content'];
						$onDelta( (string) $delta['content'] );
					}
					foreach ( $delta['tool_calls'] ?? array() as $tc ) {
						$idx = (int) ( $tc['index'] ?? 0 );
						if ( ! isset( $calls[ $idx ] ) ) {
							$calls[ $idx ] = array(
								'id'        => '',
								'name'      => '',
								'arguments' => '',
							);
						}
						if ( isset( $tc['id'] ) ) {
							$calls[ $idx ]['id'] = (string) $tc['id'];
						}
						if ( isset( $tc['function']['name'] ) ) {
							$calls[ $idx ]['name'] .= (string) $tc['function']['name'];
						}
						if ( isset( $tc['function']['arguments'] ) ) {
							$calls[ $idx ]['arguments'] .= (string) $tc['function']['arguments'];
						}
					}
				}
			}
		);

		UsageRecorder::record(
			$this->providerLabel(),
			$this->chatModel,
			'chat',
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 ),
			false,
			$this->reportedCost( $usage )
		);

		ksort( $calls );
		$toolCalls = array_map(
			static fn( $c ) => array(
				'id'        => $c['id'],
				'name'      => $c['name'],
				'arguments' => json_decode( $c['arguments'] ?: '{}', true ) ?: array(),
			),
			array_values( $calls )
		);

		return array(
			'content'    => $content !== '' ? $content : null,
			'tool_calls' => $toolCalls,
		);
	}

	/**
	 * The authoritative charge the source reported for a call, if any. The hosted proxy meters and
	 * debits the real (post-markup) charge and echoes it as `usage.djinn_cost_usd`; storing that
	 * verbatim freezes the row at the rate charged. Direct providers omit it ⇒ null ⇒ the recorder
	 * falls back to a local list-price estimate.
	 *
	 * @param array<string,mixed> $usage
	 */
	private function reportedCost( array $usage ): ?float {
		return isset( $usage['djinn_cost_usd'] ) ? (float) $usage['djinn_cost_usd'] : null;
	}

	/** @param array<string,mixed> $entry */
	private function mapMessage( array $entry ): array {
		$role = $entry['role'] ?? 'user';

		if ( $role === 'tool' ) {
			return array(
				'role'         => 'tool',
				'tool_call_id' => $entry['tool_call_id'] ?? '',
				'content'      => (string) ( $entry['content'] ?? '' ),
			);
		}

		if ( $role === 'assistant' && ! empty( $entry['tool_calls'] ) ) {
			return array(
				'role'       => 'assistant',
				'content'    => $entry['content'] ?? null,
				'tool_calls' => array_map(
					static fn( $tc ) => array(
						'id'       => $tc['id'],
						'type'     => 'function',
						'function' => array(
							'name'      => $tc['name'],
							'arguments' => wp_json_encode( $tc['arguments'] ?? array() ),
						),
					),
					$entry['tool_calls']
				),
			);
		}

		return array(
			'role'    => $role,
			'content' => (string) ( $entry['content'] ?? '' ),
		);
	}

	/** @param array<string,mixed> $tool */
	private function mapTool( array $tool ): array {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $tool['parameters'],
			),
		);
	}
}
