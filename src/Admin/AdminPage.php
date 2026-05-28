<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Provider\ModelCatalog;
use Djinn\Settings;
use Djinn\Store\Repository;
use Djinn\Usage\Pricing;

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
		// Allow a manual refresh of the discovered model list.
		if ( isset( $_GET['djinn_refresh'] ) && check_admin_referer( 'djinn_refresh_models' ) ) {
			ModelCatalog::flush();
			echo '<div class="notice notice-success is-dismissible"><p>Model list refreshed.</p></div>';
		}

		$s       = Settings::all();
		$catalog = ModelCatalog::forProvider( $s['provider'], Settings::apiKey() );
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
							<p class="description">Switch provider and <strong>Save</strong> to load that provider's models below.</p>
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
						<td>
							<?php $this->modelSelect( 'chat_model', 'djinn-chat', $catalog['chat'], (string) $s['chat_model'] ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-embed">Embedding model</label></th>
						<td>
							<?php $this->modelSelect( 'embedding_model', 'djinn-embed', $catalog['embed'], (string) $s['embedding_model'] ); ?>
						</td>
					</tr>
				</table>

				<p class="description">
					<?php if ( $catalog['error'] ) : ?>
						<span style="color:#b32d2e">⚠ <?php echo esc_html( $catalog['error'] ); ?></span>
						Showing known models as a fallback.
					<?php elseif ( $catalog['live'] ) : ?>
						Models discovered from your key.
					<?php endif; ?>
					Prices are <strong>estimates</strong> from public list prices (USD) — providers don't expose pricing via their API — and are editable with the <code>djinn_model_pricing</code> filter.
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::SETTINGS_SLUG, 'djinn_refresh' => '1' ], admin_url( 'admin.php' ) ), 'djinn_refresh_models' ) ); ?>">Refresh model list</a>
				</p>

				<?php submit_button( 'Save settings' ); ?>
			</form>
			<p>Once the offering is placed, open <strong>Djinn → Lamp</strong> and <strong>Awaken the lamp</strong> to build the schema index.</p>
		</div>
		<?php
	}

	/**
	 * A model <select> annotated with estimated prices. An empty first option keeps Djinn's
	 * per-provider default. The saved value is always present, even if discovery didn't list it
	 * (e.g. a now-retired model), so saving never silently changes the selection.
	 *
	 * @param array<int,string> $models
	 */
	private function modelSelect( string $field, string $id, array $models, string $current ): void {
		if ( $current !== '' && ! in_array( $current, $models, true ) ) {
			array_unshift( $models, $current );
		}
		echo '<select id="' . esc_attr( $id ) . '" name="djinn_settings[' . esc_attr( $field ) . ']" class="regular-text">';
		echo '<option value="">Provider default</option>';
		foreach ( $models as $model ) {
			$label = $model . ' — ' . Pricing::describe( $model );
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $model ),
				selected( $current, $model, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}
}
