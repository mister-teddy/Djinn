<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Settings;
use Djinn\Store\Repository;

/**
 * Registers the admin menu (the lamp + a settings sub-page) and enqueues the no-build
 * front-end, which uses WordPress's bundled React (`wp-element`) and component globals.
 */
class AdminPage {

	private const SLUG          = 'djinn';
	private const SETTINGS_SLUG = 'djinn-settings';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_init', [ Settings::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function menu(): void {
		add_menu_page(
			'Djinn',
			'Djinn',
			'manage_options',
			self::SLUG,
			[ $this, 'renderApp' ],
			'dashicons-lightbulb',
			58
		);
		add_submenu_page( self::SLUG, 'Djinn', 'Lamp', 'manage_options', self::SLUG, [ $this, 'renderApp' ] );
		add_submenu_page( self::SLUG, 'Djinn Settings', 'Settings', 'manage_options', self::SETTINGS_SLUG, [ $this, 'renderSettings' ] );
	}

	public function renderApp(): void {
		echo '<div class="wrap djinn-wrap"><div id="djinn-root"></div></div>';
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== 'toplevel_page_' . self::SLUG ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_script(
			'djinn-app',
			DJINN_URL . 'assets/admin.js',
			[ 'wp-element', 'wp-components', 'wp-api-fetch' ],
			DJINN_VERSION,
			true
		);
		wp_enqueue_style(
			'djinn-app',
			DJINN_URL . 'assets/admin.css',
			[ 'wp-components' ],
			DJINN_VERSION
		);

		wp_localize_script(
			'djinn-app',
			'Djinn',
			[
				'restUrl'     => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'configured'  => Settings::isConfigured(),
				'indexed'     => Repository::chunkCount() > 0,
				'settingsUrl' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
				'siteName'    => get_option( 'blogname' ),
			]
		);
	}

	public function renderSettings(): void {
		$s = Settings::all();
		?>
		<div class="wrap">
			<h1>Djinn — Settings</h1>
			<p>Place your offering in the lamp.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'djinn' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="djinn-provider">LLM provider</label></th>
						<td>
							<select id="djinn-provider" name="djinn_settings[provider]">
								<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI</option>
								<option value="gemini" <?php selected( $s['provider'], 'gemini' ); ?>>Google Gemini</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-key">API key</label></th>
						<td>
							<input type="password" id="djinn-key" name="djinn_settings[api_key]" class="regular-text"
								placeholder="<?php echo $s['api_key'] ? '•••••••• (saved — leave blank to keep)' : 'Paste your key'; ?>" autocomplete="off" />
							<p class="description">Or define <code>DJINN_API_KEY</code> in <code>wp-config.php</code> to keep it out of the database.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-chat">Chat model</label></th>
						<td><input type="text" id="djinn-chat" name="djinn_settings[chat_model]" class="regular-text"
							value="<?php echo esc_attr( $s['chat_model'] ); ?>" placeholder="default per provider (e.g. gpt-4o / gemini-2.0-flash)" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-embed">Embedding model</label></th>
						<td><input type="text" id="djinn-embed" name="djinn_settings[embedding_model]" class="regular-text"
							value="<?php echo esc_attr( $s['embedding_model'] ); ?>" placeholder="default per provider" /></td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
			<p>Once the offering is placed, open <strong>Djinn → Lamp</strong> and <strong>Awaken the lamp</strong> to build the schema index.</p>
		</div>
		<?php
	}
}
