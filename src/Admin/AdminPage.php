<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Provider\Providers;
use Djinn\Rag\IndexStatus;
use Djinn\Settings;
use Djinn\Store\Repository;

/**
 * Registers the admin menu (Lamp + Cave of Wonders) and enqueues the compiled React/Tailwind SPA
 * from build/, reading each entry's dependencies and cache-busting version from the *.asset.php
 * that @wordpress/scripts emits.
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
		echo '<div class="wrap djinn-wrap djinn-app"><div id="djinn-root"></div></div>';
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

	/** Whether the front-end has been compiled. If not, surface a notice instead of a blank screen. */
	private function buildReady(): bool {
		if ( is_readable( DJINN_DIR . 'build/lamp.asset.php' ) ) {
			return true;
		}
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p><strong>Djinn:</strong> the front-end is not built. Run <code>npm install &amp;&amp; npm run build</code> in the plugin directory.</p></div>';
		} );
		return false;
	}

	/** Cardo — the serif both screens share. Registering is enough; each app's style depends on it. */
	private function registerFont(): void {
		wp_register_style(
			'djinn-cardo',
			'https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&display=swap',
			[],
			null
		);
	}

	/**
	 * Enqueue one built entry's JS + CSS, taking its dependency list and cache-busting version from
	 * the *.asset.php webpack emits. Returns the script handle so the caller can localize onto it.
	 */
	private function enqueueEntry( string $entry ): string {
		$asset  = require DJINN_DIR . "build/{$entry}.asset.php";
		$handle = "djinn-{$entry}";
		wp_enqueue_script( $handle, DJINN_URL . "build/{$entry}.js", $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( $handle, DJINN_URL . "build/{$entry}.css", [ 'djinn-cardo' ], $asset['version'] );
		wp_style_add_data( $handle, 'rtl', 'replace' );
		return $handle;
	}

	/** The Cave of Wonders — Account · Capabilities · Spend. */
	private function enqueueCave(): void {
		if ( ! $this->buildReady() ) {
			return;
		}
		$this->registerFont();
		$handle = $this->enqueueEntry( 'cave' );
		wp_localize_script(
			$handle,
			'DjinnCave',
			[
				'restUrl'      => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'gqlUrl'       => esc_url_raw( rest_url( 'djinn/v1/graphql' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'edition'      => Settings::edition(),
				'isOrg'        => Settings::isOrg(),
				'configured'   => Settings::isConfigured(),
				'polarEnabled' => Settings::usesProxy(),
				'providers'    => Providers::forClient(),
				'privacyUrl'   => esc_url_raw( Settings::proxyUrl() . '/privacy' ),
			]
		);
		if ( Settings::usesProxy() ) {
			$this->enqueueBilling();
		}
	}

	/** The Lamp — the wish-granting chat. */
	private function enqueueApp(): void {
		if ( ! $this->buildReady() ) {
			return;
		}
		$this->registerFont();
		$handle = $this->enqueueEntry( 'lamp' );
		wp_localize_script(
			$handle,
			'Djinn',
			[
				'restUrl'     => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'gqlUrl'      => esc_url_raw( rest_url( 'djinn/v1/graphql' ) ),
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

	/** Load Polar's embed SDK so the Cave's payment block can open checkout (it drives it directly). */
	private function enqueueBilling(): void {
		wp_enqueue_script( 'polar-embed', 'https://cdn.jsdelivr.net/npm/@polar-sh/checkout@latest/dist/embed.global.js', [], null, true );
	}

	/** The Cave of Wonders — Account · Capabilities · Spend, mounted by the cave bundle. */
	public function renderCave(): void {
		echo '<div class="wrap djinn-wrap djinn-cave-wrap djinn-app"><div id="djinn-cave-root"></div></div>';
	}

}
