<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Store\Repository;

/**
 * Non-JS fallback for clearing the usage tally. The Cave (Spend tile) drives this via the REST
 * `/reset-usage` route now; this admin-post handler stays for form-based / no-JS use.
 */
class UsagePage {

	private const CAVE   = 'djinn-cave';
	private const ACTION = 'djinn_reset_usage';

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleReset' ) );
	}

	public function handleReset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not your lamp.' );
		}
		check_admin_referer( self::ACTION );
		Repository::clearUsage();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::CAVE,
					'reset' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
