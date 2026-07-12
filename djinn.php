<?php
/**
 * Plugin Name:       Djinn
 * Plugin URI:        https://github.com/mister-teddy/Djinn
 * Description:       Whisper your wish to the Djinn — a wish-granting AI assistant in your WordPress admin that fulfils requests by generating GraphQL against an in-house schema of your site.
 * Version:           0.7.7
 * Requires PHP:      7.4
 * Requires at least: 5.9
 * Author:            mister-teddy
 * License:           GPL-2.0-or-later
 * Text Domain:       djinn
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DJINN_VERSION', '0.7.7' );
define( 'DJINN_FILE', __FILE__ );

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

register_activation_hook( __FILE__, array( \Djinn\Store\Repository::class, 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		( new \Djinn\Plugin() )->boot();
	}
);
