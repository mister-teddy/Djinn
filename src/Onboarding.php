<?php

declare( strict_types=1 );

namespace Djinn;

class Onboarding {

	private const LOCK = 'djinn_register_lock';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybeRegister' ] );
	}

	public function maybeRegister(): void {
		if ( ! Settings::usesProxy() || Settings::siteToken() !== '' ) {
			return;
		}
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

		$res = wp_remote_post(
			Settings::proxyUrl() . '/register',
			[
				'timeout' => 20,
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'siteUrl'   => home_url(),
					'verifyUrl' => rest_url( 'djinn/v1/verify' ),
					'trial'     => Settings::isOrg(),
				] ),
			]
		);
		if ( is_wp_error( $res ) ) {
			return;
		}
		$json  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$token = is_array( $json ) ? (string) ( $json['token'] ?? '' ) : '';
		if ( $token !== '' ) {
			Settings::storeSiteToken( $token );
			delete_transient( self::LOCK );
		}
	}
}
