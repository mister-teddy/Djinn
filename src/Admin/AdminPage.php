<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Provider\Providers;
use Djinn\Settings;

/**
 * Registers the admin menu (Lamp + Cave of Wonders) and enqueues the compiled React/Tailwind admin
 * app from build/, reading dependencies and cache-busting version from the *.asset.php that
 * @wordpress/scripts emits.
 */
class AdminPage {

	private const SLUG      = 'djinn';
	private const CAVE_SLUG = 'djinn-cave';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( Settings::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function menu(): void {
		add_menu_page(
			'Djinn Admin AI Assistant',
			'Djinn Admin AI Assistant',
			'manage_options',
			self::SLUG,
			array( $this, 'renderApp' ),
			self::menuIcon(),
			58
		);
		add_submenu_page( self::SLUG, 'Djinn Admin AI Assistant', 'Lamp', 'manage_options', self::SLUG, array( $this, 'renderApp' ) );
		add_submenu_page( self::SLUG, 'Cave of Wonders', 'Cave of Wonders', 'manage_options', self::CAVE_SLUG, array( $this, 'renderCave' ) );
	}

	public function renderApp(): void {
		$this->renderAdminRoot();
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
			$this->enqueueAdmin( 'lamp' );
		} elseif ( $hook === 'djinn_page_' . self::CAVE_SLUG ) {
			$this->enqueueAdmin( 'cave' );
		}
	}

	/** Whether the front-end has been compiled. If not, surface a notice instead of a blank screen. */
	private function buildReady(): bool {
		if ( is_readable( DJINN_DIR . 'build/admin.asset.php' ) ) {
			return true;
		}
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p><strong>Djinn:</strong> the front-end is not built. Run <code>npm install &amp;&amp; npm run build</code> in the plugin directory.</p></div>';
			}
		);
		return false;
	}

	/** Cardo — the serif both screens share. Bundled locally (@font-face in assets/cardo.css); each
	 * app's style depends on this handle. */
	private function registerFont(): void {
		wp_register_style(
			'djinn-cardo',
			DJINN_URL . 'assets/cardo.css',
			array(),
			DJINN_VERSION
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
		wp_enqueue_style( $handle, DJINN_URL . "build/{$entry}.css", array( 'djinn-cardo' ), $asset['version'] );
		wp_style_add_data( $handle, 'rtl', 'replace' );
		return $handle;
	}

	private function enqueueAdmin( string $page ): void {
		if ( ! $this->buildReady() ) {
			return;
		}
		$this->registerFont();
		$handle    = $this->enqueueEntry( 'admin' );
		$settings  = Settings::all();
		$provider  = (string) ( $settings['provider'] ?? Settings::provider() );
		$providers = Providers::all();
		wp_localize_script(
			$handle,
			'DjinnAdmin',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'gqlUrl'        => esc_url_raw( rest_url( 'djinn/v1/graphql' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'page'          => $page,
				'usesProxy'     => Settings::usesProxy(),
				'configured'    => Settings::isConfigured(),
				'settingsUrl'   => admin_url( 'admin.php?page=' . self::CAVE_SLUG ),
				'lampUrl'       => admin_url( 'admin.php?page=' . self::SLUG ),
				'caveUrl'       => admin_url( 'admin.php?page=' . self::CAVE_SLUG ),
				'siteName'      => get_option( 'blogname' ),
				'privacyUrl'    => esc_url_raw( Settings::proxyUrl() . '/privacy' ),
				'edition'       => Settings::edition(),
				'isPro'         => Settings::isPro(),
				'polarEnabled'  => Settings::usesProxy(),
				'providers'     => Providers::forClient(),
				'proUrl'        => esc_url_raw( Settings::proUrl() ),
				'provider'      => $provider,
				'providerLabel' => $providers[ $provider ]['label'] ?? $provider,
				'chatModel'     => (string) ( $settings['chat_model'] ?? '' ),
			)
		);
	}

	/** The Cave of Wonders — Account · Capabilities · Spend. */
	public function renderCave(): void {
		$this->renderAdminRoot();
	}

	private function renderAdminRoot(): void {
		echo '<div class="wrap djinn-wrap djinn-app"><div id="djinn-admin-root"></div></div>';
	}
}
