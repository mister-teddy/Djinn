<?php
/**
 * Plugin Name:       Djinn
 * Description:       Whisper your wish to the Djinn — a wish-granting AI assistant in your WordPress admin that fulfils requests by generating GraphQL against an in-house schema of your site.
 * Version:           0.5.0
 * Requires PHP:      8.0
 * Requires at least: 6.0
 * Author:            Djinn
 * License:           GPL-2.0-or-later
 * Text Domain:       djinn
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DJINN_VERSION', '0.5.0' );
define( 'DJINN_FILE', __FILE__ );

// Build edition: 'byo' (bring-your-own-key) by default; the dist build stamps 'org' for the free,
// proxy-only WordPress.org build. Override in wp-config.php for local testing if needed.
if ( ! defined( 'DJINN_EDITION' ) ) {
	define( 'DJINN_EDITION', 'byo' );
}
define( 'DJINN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DJINN_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader (webonyx/graphql-php + our PSR-4 classes).
$djinn_autoload = DJINN_DIR . 'vendor/autoload.php';
if ( ! is_readable( $djinn_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>Djinn:</strong> the lamp is empty. Run <code>composer install</code> in the plugin directory.</p></div>';
		}
	);
	return;
}
require $djinn_autoload;

register_activation_hook( __FILE__, [ \Djinn\Store\Repository::class, 'install' ] );

add_action(
	'plugins_loaded',
	static function () {
		( new \Djinn\Plugin() )->boot();
	}
);
