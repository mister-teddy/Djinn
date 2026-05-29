<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Usage\UsageRecorder;

/**
 * Google Gemini adapter (generateContent / batchEmbedContents REST endpoints). Gemini has no
 * tool-call IDs, which is fine: the agent loop handles one tool call per turn, so function
 * responses are matched by name.
 */
class GeminiProvider implements Provider {

	use Http;

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

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
			'systemInstruction' => [ 'parts' => [ [ 'text' => $system ] ] ],
			'contents'          => array_map( [ $this, 'mapMessage' ], $messages ),
		];
		if ( ! empty( $tools ) ) {
			$payload['tools']      = [ [ 'functionDeclarations' => array_map( [ $this, 'mapTool' ], $tools ) ] ];
			$payload['toolConfig'] = [ 'functionCallingConfig' => [ 'mode' => 'AUTO' ] ];
		}

		$url  = self::BASE . rawurlencode( $this->chatModel ) . ':generateContent?key=' . rawurlencode( $this->apiKey );
		$json = $this->postJson( $url, [], $payload );

		$usage = $json['usageMetadata'] ?? [];
		UsageRecorder::record(
			'gemini',
			$this->chatModel,
			'chat',
			(int) ( $usage['promptTokenCount'] ?? 0 ),
			(int) ( $usage['candidatesTokenCount'] ?? 0 )
		);

		$parts     = $json['candidates'][0]['content']['parts'] ?? [];
		$text      = null;
		$toolCalls = [];
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$text = ( $text ?? '' ) . $part['text'];
			}
			if ( isset( $part['functionCall'] ) ) {
				$toolCalls[] = [
					'id'        => 'gemini-' . count( $toolCalls ),
					'name'      => (string) ( $part['functionCall']['name'] ?? '' ),
					'arguments' => (array) ( $part['functionCall']['args'] ?? [] ),
				];
			}
		}

		return [ 'content' => $text, 'tool_calls' => $toolCalls ];
	}

	public function chatStream( string $system, array $messages, array $tools, callable $onDelta ): array {
		$payload = [
			'systemInstruction' => [ 'parts' => [ [ 'text' => $system ] ] ],
			'contents'          => array_map( [ $this, 'mapMessage' ], $messages ),
		];
		if ( ! empty( $tools ) ) {
			$payload['tools']      = [ [ 'functionDeclarations' => array_map( [ $this, 'mapTool' ], $tools ) ] ];
			$payload['toolConfig'] = [ 'functionCallingConfig' => [ 'mode' => 'AUTO' ] ];
		}

		$url = self::BASE . rawurlencode( $this->chatModel ) . ':streamGenerateContent?alt=sse&key=' . rawurlencode( $this->apiKey );

		$text      = '';
		$toolCalls = [];
		$usage     = [];
		$buffer    = '';

		$this->postStream( $url, [], $payload, function ( $chunk ) use ( &$buffer, &$text, &$toolCalls, &$usage, $onDelta ) {
			$buffer .= $chunk;
			while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
				$line   = trim( substr( $buffer, 0, $nl ) );
				$buffer = substr( $buffer, $nl + 1 );
				if ( $line === '' || strpos( $line, 'data:' ) !== 0 ) {
					continue;
				}
				$json = json_decode( trim( substr( $line, 5 ) ), true );
				if ( ! is_array( $json ) ) {
					continue;
				}
				if ( isset( $json['usageMetadata'] ) ) {
					$usage = $json['usageMetadata'];
				}
				foreach ( $json['candidates'][0]['content']['parts'] ?? [] as $part ) {
					if ( isset( $part['text'] ) && $part['text'] !== '' ) {
						$text .= $part['text'];
						$onDelta( (string) $part['text'] );
					}
					if ( isset( $part['functionCall'] ) ) {
						$toolCalls[] = [
							'id'        => 'gemini-' . count( $toolCalls ),
							'name'      => (string) ( $part['functionCall']['name'] ?? '' ),
							'arguments' => (array) ( $part['functionCall']['args'] ?? [] ),
						];
					}
				}
			}
		} );

		UsageRecorder::record(
			'gemini',
			$this->chatModel,
			'chat',
			(int) ( $usage['promptTokenCount'] ?? 0 ),
			(int) ( $usage['candidatesTokenCount'] ?? 0 )
		);

		return [ 'content' => $text !== '' ? $text : null, 'tool_calls' => $toolCalls ];
	}

	public function embed( array $texts ): array {
		if ( empty( $texts ) ) {
			return [];
		}

		// Current Gemini embedding models (gemini-embedding-001, …) support embedContent but not
		// the older synchronous batchEmbedContents, so embed one text per call. Djinn embeds in
		// small batches (a handful of schema chunks, one query per wish), so this stays cheap.
		$url     = self::BASE . rawurlencode( $this->embeddingModel ) . ':embedContent?key=' . rawurlencode( $this->apiKey );
		$vectors = [];
		foreach ( array_values( $texts ) as $text ) {
			$json      = $this->postJson( $url, [], [ 'content' => [ 'parts' => [ [ 'text' => $text ] ] ] ] );
			$vectors[] = $json['embedding']['values'] ?? [];
		}

		// embedContent returns no usage metadata, so approximate tokens (~4 chars/token).
		$chars = array_sum( array_map( 'strlen', array_values( $texts ) ) );
		UsageRecorder::record( 'gemini', $this->embeddingModel, 'embed', (int) ceil( $chars / 4 ), 0, true );

		return $vectors;
	}

	/** @param array<string,mixed> $entry */
	private function mapMessage( array $entry ): array {
		$role = $entry['role'] ?? 'user';

		if ( $role === 'tool' ) {
			$response = json_decode( (string) ( $entry['content'] ?? '' ), true );
			if ( ! is_array( $response ) ) {
				$response = [ 'result' => (string) ( $entry['content'] ?? '' ) ];
			}
			return [
				'role'  => 'user',
				'parts' => [ [ 'functionResponse' => [ 'name' => $entry['name'] ?? '', 'response' => $response ] ] ],
			];
		}

		if ( $role === 'assistant' && ! empty( $entry['tool_calls'] ) ) {
			$parts = [];
			if ( ! empty( $entry['content'] ) ) {
				$parts[] = [ 'text' => $entry['content'] ];
			}
			foreach ( $entry['tool_calls'] as $tc ) {
				$parts[] = [ 'functionCall' => [ 'name' => $tc['name'], 'args' => (object) ( $tc['arguments'] ?? [] ) ] ];
			}
			return [ 'role' => 'model', 'parts' => $parts ];
		}

		return [
			'role'  => $role === 'assistant' ? 'model' : 'user',
			'parts' => [ [ 'text' => (string) ( $entry['content'] ?? '' ) ] ],
		];
	}

	/** @param array<string,mixed> $tool */
	private function mapTool( array $tool ): array {
		return [
			'name'        => $tool['name'],
			'description' => $tool['description'],
			'parameters'  => self::toGeminiSchema( $tool['parameters'] ),
		];
	}

	/**
	 * Gemini's function schema uses uppercase OpenAPI type enums (STRING, OBJECT, ...).
	 *
	 * @param array<string,mixed> $schema
	 * @return array<string,mixed>
	 */
	private static function toGeminiSchema( array $schema ): array {
		if ( isset( $schema['type'] ) && is_string( $schema['type'] ) ) {
			$schema['type'] = strtoupper( $schema['type'] );
		}
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $key => $prop ) {
				$schema['properties'][ $key ] = is_array( $prop ) ? self::toGeminiSchema( $prop ) : $prop;
			}
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = self::toGeminiSchema( $schema['items'] );
		}
		return $schema;
	}
}
