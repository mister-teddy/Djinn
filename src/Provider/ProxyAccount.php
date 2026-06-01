<?php

declare( strict_types=1 );

namespace Djinn\Provider;

use Djinn\Settings;

/**
 * Reads the site's account state (credit balance, free wishes left) from the hosted proxy, for
 * the Account and Spend tiles of the Cave of Wonders in proxy mode.
 */
class ProxyAccount {

	/** @return array{balanceUsd?:float,spentUsd?:float,wishesLeft?:int,payg?:bool}|null */
	public static function fetch(): ?array {
		$token = Settings::siteToken();
		if ( $token === '' ) {
			return null;
		}
		$res = wp_remote_get(
			Settings::proxyUrl() . '/v1/account',
			[ 'timeout' => 15, 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ]
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? $json : null;
	}
}
