<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Usage\UsageRecorder;

/**
 * Google Gemini adapter (generateContent REST endpoint). Gemini has no tool-call IDs, which is
 * fine: the agent loop handles one tool call per turn, so function responses are matched by name.
 */
class GeminiProvider implements Provider {

	use Http;

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	public function __construct(
		private string $apiKey,
		private string $chatModel
	) {}

	public function chat( string $system, array $messages, array $tools ): array {
		$payload = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array_map( array( $this, 'mapMessage' ), $messages ),
		);
		if ( ! empty( $tools ) ) {
			$payload['tools']      = array( array( 'functionDeclarations' => array_map( array( $this, 'mapTool' ), $tools ) ) );
			$payload['toolConfig'] = array( 'functionCallingConfig' => array( 'mode' => 'AUTO' ) );
		}

		$url  = self::BASE . rawurlencode( $this->chatModel ) . ':generateContent?key=' . rawurlencode( $this->apiKey );
		$json = $this->postJson( $url, array(), $payload );

		$usage = $json['usageMetadata'] ?? array();
		UsageRecorder::record(
			'gemini',
			$this->chatModel,
			'chat',
			(int) ( $usage['promptTokenCount'] ?? 0 ),
			(int) ( $usage['candidatesTokenCount'] ?? 0 )
		);

		$parts     = $json['candidates'][0]['content']['parts'] ?? array();
		$text      = null;
		$toolCalls = array();
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$text = ( $text ?? '' ) . $part['text'];
			}
			if ( isset( $part['functionCall'] ) ) {
				$toolCalls[] = array(
					'id'        => 'gemini-' . count( $toolCalls ),
					'name'      => (string) ( $part['functionCall']['name'] ?? '' ),
					'arguments' => (array) ( $part['functionCall']['args'] ?? array() ),
				);
			}
		}

		return array(
			'content'    => $text,
			'tool_calls' => $toolCalls,
		);
	}

	public function chatStream( string $system, array $messages, array $tools, callable $onDelta ): array {
		$payload = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array_map( array( $this, 'mapMessage' ), $messages ),
		);
		if ( ! empty( $tools ) ) {
			$payload['tools']      = array( array( 'functionDeclarations' => array_map( array( $this, 'mapTool' ), $tools ) ) );
			$payload['toolConfig'] = array( 'functionCallingConfig' => array( 'mode' => 'AUTO' ) );
		}

		$url = self::BASE . rawurlencode( $this->chatModel ) . ':streamGenerateContent?alt=sse&key=' . rawurlencode( $this->apiKey );

		$text      = '';
		$toolCalls = array();
		$usage     = array();
		$buffer    = '';

		$this->postStream(
			$url,
			array(),
			$payload,
			function ( $chunk ) use ( &$buffer, &$text, &$toolCalls, &$usage, $onDelta ) {
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
					foreach ( $json['candidates'][0]['content']['parts'] ?? array() as $part ) {
						if ( isset( $part['text'] ) && $part['text'] !== '' ) {
							$text .= $part['text'];
							$onDelta( (string) $part['text'] );
						}
						if ( isset( $part['functionCall'] ) ) {
							$toolCalls[] = array(
								'id'        => 'gemini-' . count( $toolCalls ),
								'name'      => (string) ( $part['functionCall']['name'] ?? '' ),
								'arguments' => (array) ( $part['functionCall']['args'] ?? array() ),
							);
						}
					}
				}
			}
		);

		UsageRecorder::record(
			'gemini',
			$this->chatModel,
			'chat',
			(int) ( $usage['promptTokenCount'] ?? 0 ),
			(int) ( $usage['candidatesTokenCount'] ?? 0 )
		);

		return array(
			'content'    => $text !== '' ? $text : null,
			'tool_calls' => $toolCalls,
		);
	}

	/** @param array<string,mixed> $entry */
	private function mapMessage( array $entry ): array {
		$role = $entry['role'] ?? 'user';

		if ( $role === 'tool' ) {
			$response = json_decode( (string) ( $entry['content'] ?? '' ), true );
			if ( ! is_array( $response ) ) {
				$response = array( 'result' => (string) ( $entry['content'] ?? '' ) );
			}
			return array(
				'role'  => 'user',
				'parts' => array(
					array(
						'functionResponse' => array(
							'name'     => $entry['name'] ?? '',
							'response' => $response,
						),
					),
				),
			);
		}

		if ( $role === 'assistant' && ! empty( $entry['tool_calls'] ) ) {
			$parts = array();
			if ( ! empty( $entry['content'] ) ) {
				$parts[] = array( 'text' => $entry['content'] );
			}
			foreach ( $entry['tool_calls'] as $tc ) {
				$parts[] = array(
					'functionCall' => array(
						'name' => $tc['name'],
						'args' => (object) ( $tc['arguments'] ?? array() ),
					),
				);
			}
			return array(
				'role'  => 'model',
				'parts' => $parts,
			);
		}

		return array(
			'role'  => $role === 'assistant' ? 'model' : 'user',
			'parts' => array( array( 'text' => (string) ( $entry['content'] ?? '' ) ) ),
		);
	}

	/** @param array<string,mixed> $tool */
	private function mapTool( array $tool ): array {
		return array(
			'name'        => $tool['name'],
			'description' => $tool['description'],
			'parameters'  => self::toGeminiSchema( $tool['parameters'] ),
		);
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
