<?php

declare( strict_types=1 );

namespace Djinn\Provider;

/**
 * Talks to Djinn's hosted proxy, which is OpenAI-compatible — so this is just the OpenAI adapter
 * pointed at the proxy with the site token as its bearer. The proxy picks the model and meters
 * spend, so the model names we send are placeholders it overrides.
 */
class ProxyProvider extends OpenAIProvider {

	private static string $conversationId = '';

	public function __construct( string $token, string $proxyUrl ) {
		parent::__construct( $token, 'djinn', 'djinn', rtrim( $proxyUrl, '/' ) . '/v1' );
	}

	/** Tag every subsequent proxy call with the conversation (chat) it belongs to, for analytics. */
	public static function setConversation( string $id ): void {
		self::$conversationId = $id;
	}

	protected function providerLabel(): string {
		return 'proxy';
	}

	protected function extraHeaders(): array {
		$headers = array();
		if ( self::$conversationId !== '' ) {
			$headers['X-Djinn-Conversation-Id'] = self::$conversationId;
		}
		return $headers;
	}

	/**
	 * The hosted proxy buffers responses (no SSE passthrough yet), so we can't truly stream through
	 * it — run the normal request and emit the full reply as one delta.
	 */
	public function chatStream( string $system, array $messages, array $tools, callable $onDelta ): array {
		$turn = $this->chat( $system, $messages, $tools );
		if ( ! empty( $turn['content'] ) ) {
			$onDelta( (string) $turn['content'] );
		}
		return $turn;
	}
}
