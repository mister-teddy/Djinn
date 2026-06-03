<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Provider\Providers;
use Djinn\Rag\IndexStatus;
use Djinn\Settings;
use Djinn\Store\Repository;

/**
 * Registers the admin menu (the lamp + a settings sub-page) and enqueues the no-build
 * front-end, which uses WordPress's bundled React (`wp-element`) and component globals.
 */
class AdminPage {

	private const SLUG      = 'djinn';
	private const CAVE_SLUG = 'djinn-cave';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ Settings::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function menu(): void {
		add_menu_page(
			'Djinn',
			'Djinn' . IndexStatus::menuBubble(),
			'manage_options',
			self::SLUG,
			[ $this, 'renderApp' ],
			self::menuIcon(),
			58
		);
		add_submenu_page( self::SLUG, 'Djinn', 'Lamp', 'manage_options', self::SLUG, [ $this, 'renderApp' ] );
		add_submenu_page( self::SLUG, 'Cave of Wonders', 'Cave of Wonders', 'manage_options', self::CAVE_SLUG, [ $this, 'renderCave' ] );
	}

	public function renderApp(): void {
		echo '<div class="wrap djinn-wrap"><div id="djinn-root"></div></div>';
	}

	/**
	 * The lamp menu icon as a base64 SVG data URI (WordPress recolors it to the admin scheme by
	 * masking). Falls back to the lightbulb dashicon if the asset is missing.
	 */
	private static function menuIcon(): string {
		$svg = file_exists( DJINN_DIR . 'assets/menu-icon.svg' )
			? file_get_contents( DJINN_DIR . 'assets/menu-icon.svg' )
			: false;
		if ( ! is_string( $svg ) || $svg === '' ) {
			return 'dashicons-lightbulb';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function enqueue( string $hook ): void {
		if ( $hook === 'toplevel_page_' . self::SLUG ) {
			$this->enqueueApp();
		} elseif ( $hook === 'djinn_page_' . self::CAVE_SLUG ) {
			$this->enqueueCave();
		}
	}

	/**
	 * Register the shared component library (DjinnUI) + palette, depended on by both apps. Registering
	 * (not enqueuing) is enough — WordPress pulls a dependency in when a dependent script/style loads.
	 */
	private function enqueueUi(): void {
		wp_enqueue_style( 'wp-components' );
		// Cardo — the app's serif, shared by both screens.
		wp_register_style(
			'djinn-cardo',
			'https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&display=swap',
			[],
			null
		);
		wp_register_script( 'djinn-ui', DJINN_URL . 'assets/components.js', [ 'wp-element', 'wp-components', 'wp-api-fetch' ], DJINN_VERSION, true );
		wp_register_style( 'djinn-ui', DJINN_URL . 'assets/components.css', [ 'wp-components', 'djinn-cardo' ], DJINN_VERSION );
	}

	/** The Cave of Wonders — a React dashboard (Account · Capabilities · Spend) on shared components. */
	private function enqueueCave(): void {
		$this->enqueueUi();
		wp_enqueue_script( 'djinn-cave-app', DJINN_URL . 'assets/cave.js', [ 'djinn-ui' ], DJINN_VERSION, true );
		wp_enqueue_style( 'djinn-cave', DJINN_URL . 'assets/cave.css', [ 'djinn-ui' ], DJINN_VERSION );
		wp_localize_script(
			'djinn-cave-app',
			'DjinnCave',
			[
				'restUrl'       => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'edition'       => Settings::edition(),
				'isOrg'         => Settings::isOrg(),
				'configured'    => Settings::isConfigured(),
				'polarEnabled'  => Settings::usesProxy(),
				'providers'     => Providers::forClient(),
				'privacyUrl'    => esc_url_raw( Settings::proxyUrl() . '/privacy' ),
			]
		);
		if ( Settings::usesProxy() ) {
			$this->enqueueBilling();
		}
	}

	/** The no-build React app for the Lamp screen. */
	private function enqueueApp(): void {
		$this->enqueueUi();
		wp_enqueue_script( 'djinn-app', DJINN_URL . 'assets/admin.js', [ 'djinn-ui' ], DJINN_VERSION, true );
		wp_enqueue_style( 'djinn-app', DJINN_URL . 'assets/admin.css', [ 'djinn-ui' ], DJINN_VERSION );

		wp_localize_script(
			'djinn-app',
			'Djinn',
			[
				'restUrl'     => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'isOrg'       => Settings::isOrg(),
				'configured'  => Settings::isConfigured(),
				'indexed'     => Repository::chunkCount() > 0,
				'indexStale'  => IndexStatus::needsReindex(),
				'settingsUrl' => admin_url( 'admin.php?page=' . self::CAVE_SLUG ),
				'indexUrl'    => admin_url( 'admin.php?page=' . self::CAVE_SLUG ),
				'siteName'    => get_option( 'blogname' ),
				'privacyUrl'  => esc_url_raw( Settings::proxyUrl() . '/privacy' ),
			]
		);
	}

	private function enqueueBilling(): void {
		wp_enqueue_script( 'polar-embed', 'https://cdn.jsdelivr.net/npm/@polar-sh/checkout@latest/dist/embed.global.js', [], null, true );
		wp_register_script( 'djinn-billing', false, [ 'polar-embed' ], DJINN_VERSION, true );
		wp_enqueue_script( 'djinn-billing' );
		wp_localize_script(
			'djinn-billing',
			'DjinnBilling',
			[
				'restUrl' => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
		wp_add_inline_script( 'djinn-billing', self::billingScript() );
	}

	private static function billingScript(): string {
		return <<<'JS'
window.DjinnBilling = window.DjinnBilling || {};
window.DjinnBilling.checkout = async function ( kind ) {
	try {
		var r = await fetch( DjinnBilling.restUrl + '/billing-checkout', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': DjinnBilling.nonce },
			body: JSON.stringify( { kind: kind || 'credit' } )
		} );
		var d = await r.json();
		if ( ! r.ok || ! d.url ) {
			throw new Error( ( d && d.message ) || 'Billing is not available yet.' );
		}
		var checkout = await window.PolarEmbedCheckout.create( d.url, { theme: 'dark' } );
		checkout.addEventListener( 'success', function () { window.location.reload(); } );
	} catch ( e ) {
		window.alert( e.message || 'Could not start checkout.' );
	}
};
JS;
	}

	/** The Cave of Wonders — a React dashboard (Account · Capabilities · Spend) mounted by cave.js. */
	public function renderCave(): void {
		echo '<div class="wrap djinn-wrap djinn-cave-wrap"><div id="djinn-cave-root"></div></div>';
	}

}
