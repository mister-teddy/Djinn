<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Settings;

/**
 * Reads the site's account state (credit balance, spend, payment status) from the hosted proxy, for
 * the Account and Spend tiles of the Cave of Wonders in proxy mode.
 */
class ProxyAccount {

	/** @return array{balanceUsd?:float,spentUsd?:float,paid?:bool,subscribed?:bool}|null */
	public static function fetch(): ?array {
		$token = Settings::siteToken();
		if ( $token === '' ) {
			return null;
		}
		try {
			$data = ProxyClient::call(
				'query { account { balanceUsd spentUsd paid subscribed } }',
				[],
				$token,
				15
			);
		} catch ( ProxyException $e ) {
			return null;
		}
		$account = $data['account'] ?? null;
		return is_array( $account ) ? $account : null;
	}
}
