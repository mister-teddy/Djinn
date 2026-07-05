<?php

declare( strict_types=1 );

namespace Djinn\Provider;

/**
 * A provider-agnostic LLM interface. Each adapter maps Djinn's normalized message/tool format
 * onto a vendor API.
 *
 * Normalized message entries (as stored and replayed):
 *   ['role' => 'user'|'assistant'|'tool', 'content' => string|null,
 *    'tool_calls' => [['id'=>string,'name'=>string,'arguments'=>array]],  // assistant only
 *    'tool_call_id' => string, 'name' => string]                          // tool only
 *
 * Tool specs:
 *   [['name'=>string,'description'=>string,'parameters'=>array(JSON Schema)]]
 */
interface Provider {

	/**
	 * One assistant turn.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $tools
	 * @return array{content:?string,tool_calls:array<int,array{id:string,name:string,arguments:array}>}
	 */
	public function chat( string $system, array $messages, array $tools ): array;

	/**
	 * Streaming variant of chat(): identical return shape, but text deltas are passed to $onDelta
	 * as they arrive. Adapters that can't stream may emit the whole content as a single delta.
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @param array<int,array<string,mixed>> $tools
	 * @param callable(string):void          $onDelta
	 * @return array{content:?string,tool_calls:array<int,array{id:string,name:string,arguments:array}>}
	 */
	public function chatStream( string $system, array $messages, array $tools, callable $onDelta ): array;
}
