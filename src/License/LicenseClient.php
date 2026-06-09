<?php

declare( strict_types=1 );

namespace Djinn\License;

/**
 * Pro entitlement via a Polar license key. The Pro build gates its full schema scope on a valid,
 * activated key; the free build never reaches this code because Settings::isPro() short-circuits on
 * the edition constant before calling active().
 *
 * Speaks Polar's customer-portal license-keys API, which is safe to call from a public client (no
 * server secret). The organization id is public and baked into the build; the key is the buyer's
 * secret. A daily transient caches the verdict so wishes never block on Polar, and an unreachable
 * Polar grants a short grace window rather than locking out a paying user mid-outage.
 */
class LicenseClient {

	private const OPTION = 'djinn_license';        // [ 'key' => string, 'activation_id' => string ]
	private const CACHE  = 'djinn_license_active'; // transient: '1' | '0'

	/** Polar API base. Override with DJINN_POLAR_BASE for sandbox testing. */
	private static function base(): string {
		if ( defined( 'DJINN_POLAR_BASE' ) && DJINN_POLAR_BASE ) {
			return rtrim( (string) DJINN_POLAR_BASE, '/' );
		}
		return 'https://api.polar.sh';
	}

	/** The public Polar organization id, baked into the Pro build. */
	private static function orgId(): string {
		return defined( 'DJINN_POLAR_ORG_ID' ) ? (string) DJINN_POLAR_ORG_ID : '';
	}

	/** @return array{key:string,activation_id:string} */
	private static function stored(): array {
		$o = get_option( self::OPTION, [] );
		$o = is_array( $o ) ? $o : [];
		return [
			'key'           => (string) ( $o['key'] ?? '' ),
			'activation_id' => (string) ( $o['activation_id'] ?? '' ),
		];
	}

	public static function key(): string {
		return self::stored()['key'];
	}

	/** Cached entitlement check — what Settings::isPro() reads. Revalidates against Polar ~daily. */
	public static function active(): bool {
		$s = self::stored();
		if ( $s['key'] === '' || $s['activation_id'] === '' ) {
			return false;
		}
		$cached = get_transient( self::CACHE );
		if ( $cached !== false ) {
			return $cached === '1';
		}
		$res = self::post( '/v1/customer-portal/license-keys/validate', [
			'key'             => $s['key'],
			'organization_id' => self::orgId(),
			'activation_id'   => $s['activation_id'],
		] );
		if ( $res === null ) {
			// Polar unreachable: grant a short grace so an outage doesn't downgrade a paying site.
			set_transient( self::CACHE, '1', HOUR_IN_SECONDS );
			return true;
		}
		$ok = ( ( $res['status'] ?? '' ) === 'granted' );
		set_transient( self::CACHE, $ok ? '1' : '0', DAY_IN_SECONDS );
		return $ok;
	}

	/** Bind this site to a license key by reserving an activation. True once one is held. */
	public static function activate( string $key ): bool {
		$key = trim( $key );
		if ( $key === '' ) {
			return false;
		}
		$res = self::post( '/v1/customer-portal/license-keys/activate', [
			'key'             => $key,
			'organization_id' => self::orgId(),
			'label'           => home_url(),
		] );
		$activationId = is_array( $res ) ? (string) ( $res['id'] ?? '' ) : '';
		if ( $activationId === '' ) {
			return false;
		}
		update_option( self::OPTION, [ 'key' => $key, 'activation_id' => $activationId ] );
		set_transient( self::CACHE, '1', DAY_IN_SECONDS );
		return true;
	}

	/** Release the activation slot and forget the key (on key removal / uninstall). */
	public static function deactivate(): void {
		$s = self::stored();
		if ( $s['key'] !== '' && $s['activation_id'] !== '' ) {
			self::post( '/v1/customer-portal/license-keys/deactivate', [
				'key'             => $s['key'],
				'organization_id' => self::orgId(),
				'activation_id'   => $s['activation_id'],
			] );
		}
		delete_option( self::OPTION );
		delete_transient( self::CACHE );
	}

	/**
	 * POST JSON to Polar and decode. Returns null on a transport error or a non-2xx response (treated
	 * as "couldn't verify"), so callers can choose a lenient fallback instead of throwing mid-wish.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|null
	 */
	private static function post( string $path, array $body ): ?array {
		$res = wp_remote_post( self::base() . $path, [
			'timeout' => 15,
			'headers' => [ 'content-type' => 'application/json' ],
			'body'    => wp_json_encode( $body ),
		] );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 300 ) {
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? $json : null;
	}
}
