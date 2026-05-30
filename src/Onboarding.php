<?php

declare( strict_types=1 );

namespace Djinn;

/**
 * ORG onboarding: bind this WordPress site to a hosted-proxy trial account, automatically.
 *
 * On an admin page load (ORG edition, no token yet) we POST our site URL + REST verify URL to the
 * proxy's `/register`. The proxy calls the verify URL back with a nonce we echo (proving we control
 * a real, reachable Djinn install), then mints one trial per site domain and returns its token,
 * which we store. No email, no page to visit — sign-up happens inside wp-admin.
 *
 * Throttled with a transient so a failing call can't hammer the proxy. Localhost / non-public sites
 * can't be called back — paste a token manually under Settings (minted via /admin/provision).
 */
class Onboarding {

	private const LOCK = 'djinn_register_lock';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybeRegister' ] );
	}

	public function maybeRegister(): void {
		if ( ! Settings::isOrg() || Settings::siteToken() !== '' ) {
			return;
		}
		// Back off between attempts so a persistently-unreachable site doesn't call out every load.
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
