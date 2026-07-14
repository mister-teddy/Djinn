<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Usage\UsageRecorder;
use RuntimeException;
use Throwable;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Adapter for the WordPress AI Client introduced in WordPress 7.0.
 *
 * The adapter is optional: all references are runtime-gated so Djinn can still support older
 * WordPress versions and sites without a configured site-level AI provider.
 */
class WordPressAIClientProvider implements Provider {

	public const ID = 'wp-ai-client';

	private static ?bool $available = null;

	public function __construct( string $apiKey = '', string $chatModel = '' ) {
		unset( $apiKey, $chatModel );
	}

	public static function isAvailable(): bool {
		if ( self::$available !== null ) {
			return self::$available;
		}
		if ( ! function_exists( 'wp_supports_ai' ) || ! function_exists( 'wp_ai_client_prompt' ) ) {
			self::$available = false;
			return self::$available;
		}
		$supportsAi   = 'wp_supports_ai';
		$promptClient = 'wp_ai_client_prompt';
		if ( ! $supportsAi() || ! class_exists( FunctionDeclaration::class ) ) {
			self::$available = false;
			return self::$available;
		}

		try {
			$prompt = $promptClient( 'Confirm Djinn can use function calling.' )
				->using_system_instruction( 'Reply by calling the available function.' )
				->using_function_declarations( self::supportDeclaration() );

			self::$available = $prompt->is_supported_for_text_generation() === true;
			return self::$available;
		} catch ( Throwable $e ) {
			self::$available = false;
			return self::$available;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $tools
	 * @return array{content:?string,tool_calls:array<int,array{id:string,name:string,arguments:array}>}
	 */
	public function chat( string $system, array $messages, array $tools ): array {
		$result = $this->buildPrompt( $system, $messages, $tools )->generate_text_result();
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( esc_html( $result->get_error_message() ) );
		}
		if ( ! $result instanceof GenerativeAiResult ) {
			throw new RuntimeException( esc_html( 'WordPress AI Client did not return a text result.' ) );
		}

		return $this->normalizeResult( $result );
	}

	/**
	 * The WordPress AI Client wrapper currently exposes non-streaming text generation. Djinn's
	 * provider contract allows a non-streaming adapter to emit the complete text as one delta.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $tools
	 * @param callable(string):void          $onDelta
	 * @return array{content:?string,tool_calls:array<int,array{id:string,name:string,arguments:array}>}
	 */
	public function chatStream( string $system, array $messages, array $tools, callable $onDelta ): array {
		$result = $this->chat( $system, $messages, $tools );
		if ( $result['content'] !== null && $result['content'] !== '' ) {
			$onDelta( $result['content'] );
		}
		return $result;
	}

	/** @return FunctionDeclaration */
	private static function supportDeclaration() {
		return new FunctionDeclaration(
			'djinn_support_check',
			'Confirms the configured WordPress AI provider can call functions.',
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $tools
	 * @return \WP_AI_Client_Prompt_Builder
	 */
	private function buildPrompt( string $system, array $messages, array $tools ) {
		if ( ! self::isAvailable() ) {
			throw new RuntimeException( esc_html( 'Configure the WordPress AI Client with a text model that supports function calling, then try again.' ) );
		}

		$promptClient = 'wp_ai_client_prompt';
		$prompt       = $promptClient( $this->toAiMessages( $messages ) )
			->using_system_instruction( $system );

		$declarations = $this->toolDeclarations( $tools );
		if ( $declarations ) {
			$prompt = $prompt->using_function_declarations( ...$declarations );
		}
		return $prompt;
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @return array<int,UserMessage|ModelMessage>
	 */
	private function toAiMessages( array $messages ): array {
		$out = array();
		foreach ( $messages as $entry ) {
			$role = (string) ( $entry['role'] ?? 'user' );

			if ( $role === 'assistant' ) {
				$message = $this->assistantMessage( $entry );
			} elseif ( $role === 'tool' ) {
				$message = $this->toolResultMessage( $entry );
			} else {
				$message = new UserMessage(
					array(
						new MessagePart( (string) ( $entry['content'] ?? '' ) ),
					)
				);
			}

			if ( $message ) {
				$out[] = $message;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $entry
	 * @return ModelMessage|null
	 */
	private function assistantMessage( array $entry ): ?ModelMessage {
		$parts   = array();
		$content = (string) ( $entry['content'] ?? '' );
		if ( $content !== '' ) {
			$parts[] = new MessagePart( $content );
		}

		foreach ( (array) ( $entry['tool_calls'] ?? array() ) as $call ) {
			$id   = (string) ( $call['id'] ?? '' );
			$name = (string) ( $call['name'] ?? '' );
			if ( $id === '' && $name === '' ) {
				continue;
			}
			$parts[] = new MessagePart(
				new FunctionCall(
					$id !== '' ? $id : null,
					$name !== '' ? $name : null,
					(array) ( $call['arguments'] ?? array() )
				)
			);
		}

		return $parts ? new ModelMessage( $parts ) : null;
	}

	/** @param array<string,mixed> $entry */
	private function toolResultMessage( array $entry ): UserMessage {
		$id   = (string) ( $entry['tool_call_id'] ?? '' );
		$name = (string) ( $entry['name'] ?? '' );
		if ( $id === '' && $name === '' ) {
			$name = 'tool_result';
		}

		return new UserMessage(
			array(
				new MessagePart(
					new FunctionResponse(
						$id !== '' ? $id : null,
						$name !== '' ? $name : null,
						$this->decodeToolContent( (string) ( $entry['content'] ?? '' ) )
					)
				),
			)
		);
	}

	/** @return mixed */
	private function decodeToolContent( string $content ) {
		if ( $content === '' ) {
			return '';
		}
		$decoded = json_decode( $content, true );
		return json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
	}

	/**
	 * @param array<int,array<string,mixed>> $tools
	 * @return array<int,FunctionDeclaration>
	 */
	private function toolDeclarations( array $tools ): array {
		$out = array();
		foreach ( $tools as $tool ) {
			$name = (string) ( $tool['name'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			$out[] = new FunctionDeclaration(
				$name,
				(string) ( $tool['description'] ?? '' ),
				isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ? $tool['parameters'] : null
			);
		}
		return $out;
	}

	/**
	 * @return array{content:?string,tool_calls:array<int,array{id:string,name:string,arguments:array}>}
	 */
	private function normalizeResult( GenerativeAiResult $result ): array {
		$this->recordUsage( $result );

		$message   = $result->toMessage();
		$textParts = array();
		$calls     = array();

		foreach ( $message->getParts() as $part ) {
			$text = $part->getText();
			if ( $text !== null && $text !== '' ) {
				$textParts[] = $text;
			}

			$call = $part->getFunctionCall();
			if ( $call instanceof FunctionCall ) {
				$args = $call->getArgs();
				$calls[] = array(
					'id'        => $call->getId() ?: wp_unique_id( 'djinn_wp_ai_' ),
					'name'      => $call->getName() ?: '',
					'arguments' => is_array( $args ) ? $args : array(),
				);
			}
		}

		$content = trim( implode( '', $textParts ) );
		return array(
			'content'    => $content !== '' ? $content : null,
			'tool_calls' => $calls,
		);
	}

	private function recordUsage( GenerativeAiResult $result ): void {
		$usage = $result->getTokenUsage();
		$model = 'wordpress-ai-client';
		try {
			$model = $result->getModelMetadata()->getId();
		} catch ( Throwable $e ) {
			// Some provider implementations may omit metadata; keep usage recording best-effort.
		}

		UsageRecorder::record(
			self::ID,
			$model,
			'chat',
			$usage->getPromptTokens(),
			$usage->getCompletionTokens()
		);
	}
}
