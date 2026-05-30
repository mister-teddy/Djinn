<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Settings;

/**
 * ORG data-use disclosure: a dismissible admin notice on Djinn's pages telling the user that their
 * wishes + the relevant site content are sent to our hosted gateway and on to the LLM provider
 * (Google Gemini) to be fulfilled, with a link to the privacy policy. Required for the
 * WordPress.org listing. Dismissal is per-user (user meta).
 */
class Disclosure {

	private const META = 'djinn_disclosure_dismissed';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybeDismiss' ] );
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function maybeDismiss(): void {
		if ( isset( $_GET['djinn_dismiss_disclosure'] ) && check_admin_referer( 'djinn_disclosure' ) ) {
			update_user_meta( get_current_user_id(), self::META, 1 );
			wp_safe_redirect( remove_query_arg( [ 'djinn_dismiss_disclosure', '_wpnonce' ] ) );
			exit;
		}
	}

	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, 'djinn' ) === false ) {
			return; // only on Djinn's own admin pages
		}
		if ( get_user_meta( get_current_user_id(), self::META, true ) ) {
			return;
		}
		$privacy = esc_url( Settings::proxyUrl() . '/privacy' );
		$dismiss = esc_url( wp_nonce_url( add_query_arg( 'djinn_dismiss_disclosure', '1' ), 'djinn_disclosure' ) );
		echo '<div class="notice notice-info"><p><strong>Djinn:</strong> the wishes you make are sent to '
			. 'Djinn\'s hosted gateway and on to the AI provider (Google Gemini) to be fulfilled. See the '
			. '<a href="' . $privacy . '" target="_blank" rel="noopener">privacy policy</a>. '
			. '<a href="' . $dismiss . '">Got it</a></p></div>';
	}
}
