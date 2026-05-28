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
	 * Embed a batch of texts.
	 *
	 * @param array<int,string> $texts
	 * @return array<int,array<int,float>>
	 */
	public function embed( array $texts ): array;

	public function embeddingModel(): string;
}
