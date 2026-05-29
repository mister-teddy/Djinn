<?php

declare( strict_types=1 );

namespace Djinn\Provider;

/**
 * Talks to Djinn's hosted proxy, which is OpenAI-compatible — so this is just the OpenAI adapter
 * pointed at the proxy with the site token as its bearer. The proxy picks the model and meters
 * spend, so the model names we send are placeholders it overrides.
 *
 * On the first call of each wish we send `X-Djinn-New-Wish` so the proxy can count free wishes;
 * the agent loop arms it via markNewWish() at the start of a new wish (not on grant/resume).
 */
class ProxyProvider extends OpenAIProvider {

	private static bool $pendingNewWish = false;

	private bool $sendNewWish = false;

	public function __construct( string $token, string $proxyUrl ) {
		parent::__construct( $token, 'djinn', 'djinn', rtrim( $proxyUrl, '/' ) . '/v1' );
	}

	/** Arm the new-wish marker for the next chat() call. */
	public static function markNewWish(): void {
		self::$pendingNewWish = true;
	}

	protected function providerLabel(): string {
		return 'proxy';
	}

	public function chat( string $system, array $messages, array $tools ): array {
		$this->sendNewWish     = self::$pendingNewWish;
		self::$pendingNewWish  = false;
		try {
			return parent::chat( $system, $messages, $tools );
		} finally {
			$this->sendNewWish = false;
		}
	}

	protected function extraHeaders(): array {
		return $this->sendNewWish ? [ 'X-Djinn-New-Wish' => '1' ] : [];
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
