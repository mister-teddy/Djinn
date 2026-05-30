<?php

declare( strict_types=1 );

namespace Djinn\Provider;

/**
 * EXPERIMENTAL — drive Anthropic's Messages API with a **Claude Code / Claude Max subscription**
 * OAuth token (from `claude setup-token`) instead of a pay-per-token API key. Same wire format as
 * AnthropicProvider; only the auth changes (Bearer token + the Claude Code OAuth beta header).
 *
 * ⚠️ Subscriptions are sold for use *within Claude Code*. Using that token to power an unrelated
 * app may violate Anthropic's terms, can be rate-limited/revoked, and the OAuth handshake may
 * change without notice. Ship behind a clearly-labelled experimental setting only.
 */
class ClaudeMaxProvider extends AnthropicProvider {

	// The beta flag Claude Code's OAuth uses; update if Anthropic changes it.
	private const OAUTH_BETA = 'oauth-2025-04-20';

	protected function providerLabel(): string {
		return 'claude-max';
	}

	protected function authHeaders(): array {
		return [
			'Authorization'     => 'Bearer ' . $this->apiKey, // a `claude setup-token` OAuth token
			'anthropic-version' => '2023-06-01',
			'anthropic-beta'    => self::OAUTH_BETA,
		];
	}
}
