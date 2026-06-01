<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Provider\ModelCatalog;
use Djinn\Provider\ProxyAccount;
use Djinn\Rag\IndexStatus;
use Djinn\Settings;
use Djinn\Store\Repository;
use Djinn\Usage\Pricing;

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

	/** The Cave of Wonders dashboard: its tile stylesheet, plus Stripe billing for the ORG account tile. */
	private function enqueueCave(): void {
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'djinn-cave', DJINN_URL . 'assets/cave.css', [ 'wp-components' ], DJINN_VERSION );
		if ( Settings::isOrg() ) {
			$this->enqueueBilling();
		}
	}

	/** The no-build React app for the Lamp screen. */
	private function enqueueApp(): void {
		wp_enqueue_style( 'wp-components' );
		// Cardo — the app's serif. Loaded from Google Fonts for the Lamp screen only.
		wp_enqueue_style(
			'djinn-cardo',
			'https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&display=swap',
			[],
			null
		);
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
			[ 'wp-components', 'djinn-cardo' ],
			DJINN_VERSION
		);

		wp_localize_script(
			'djinn-app',
			'Djinn',
			[
				'restUrl'     => esc_url_raw( rest_url( 'djinn/v1' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'isOrg'       => Settings::isOrg(),
				'configured'  => Settings::isConfigured(),
				'indexed'     => Repository::chunkCount() > 0,
				'settingsUrl' => admin_url( 'admin.php?page=' . self::CAVE_SLUG ),
				'indexUrl'    => admin_url( 'admin.php?page=' . self::CAVE_SLUG ),
				'siteName'    => get_option( 'blogname' ),
				'privacyUrl'  => esc_url_raw( Settings::proxyUrl() . '/privacy' ),
			]
		);
	}

	/**
	 * ORG settings page only: Stripe.js (the one sanctioned remote script) + the in-admin card
	 * form's logic. The form calls our own REST relay (/billing-intent), so the site token never
	 * leaves the server and there's no cross-origin request.
	 */
	private function enqueueBilling(): void {
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );
		wp_register_script( 'djinn-billing', false, [ 'stripe-js' ], DJINN_VERSION, true );
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

	/** Vanilla-JS card form: fetch a SetupIntent via our REST relay, mount Stripe's Payment Element, confirm. */
	private static function billingScript(): string {
		return <<<'JS'
(function () {
	var btn = document.getElementById('djinn-add-card');
	if (!btn) { return; }
	var msg = document.getElementById('djinn-billing-msg');
	var mount = document.getElementById('djinn-payment-element');
	var stripe, elements, ready = false;
	btn.addEventListener('click', async function () {
		if (!ready) {
			btn.disabled = true;
			msg.textContent = 'Loading the secure payment form…';
			try {
				var r = await fetch(DjinnBilling.restUrl + '/billing-intent', {
					method: 'POST',
					headers: { 'X-WP-Nonce': DjinnBilling.nonce }
				});
				var d = await r.json();
				if (!r.ok || !d.clientSecret) {
					throw new Error((d && d.message) || 'Billing is not available yet.');
				}
				stripe = Stripe(d.publishableKey);
				elements = stripe.elements({ clientSecret: d.clientSecret });
				elements.create('payment').mount(mount);
				mount.style.display = 'block';
				btn.textContent = 'Save card';
				msg.textContent = '';
				ready = true;
			} catch (e) {
				msg.textContent = e.message || 'Could not start billing.';
			} finally {
				btn.disabled = false;
			}
			return;
		}
		btn.disabled = true;
		msg.textContent = 'Saving…';
		var result = await stripe.confirmSetup({ elements: elements, redirect: 'if_required' });
		if (result.error) {
			msg.textContent = 'Error: ' + result.error.message;
			btn.disabled = false;
		} else {
			msg.textContent = 'Card saved — automatic top-up is on. You can keep wishing past the free trial.';
		}
	});
})();
JS;
	}

	/**
	 * The Cave of Wonders — one tiled dashboard. Account + Memory share the top row; Spend spans the
	 * bottom. The page itself doesn't scroll; each tile body scrolls on its own (see cave.css).
	 */
	public function renderCave(): void {
		echo '<div class="djinn-cave">';
		$this->tileOpen( 'account', 'Account' );
		$this->settingsBody();
		echo '</div></section>';

		$this->tileOpen( 'memory', 'Memory' );
		( new IndexPage() )->renderBody();
		echo '</div></section>';

		$this->tileOpen( 'spend', 'Spend' );
		( new UsagePage() )->renderBody();
		echo '</div></section>';
		echo '</div>';
	}

	/** Opens a tile: <section> + dark header + the scrollable body wrapper. */
	private function tileOpen( string $key, string $title ): void {
		printf(
			'<section class="djinn-tile djinn-tile--%1$s"><header class="djinn-tile-head"><span class="djinn-tile-mark">✦</span><h2>%2$s</h2></header><div class="djinn-tile-body">',
			esc_attr( $key ),
			esc_html( $title )
		);
	}

	private function settingsBody(): void {
		if ( Settings::isOrg() ) {
			$this->orgSettingsBody();
			return;
		}
		$this->byoSettingsBody();
	}

	/**
	 * ORG edition: no keys, no token field. The site binds to the hosted service automatically
	 * (see Onboarding); a dev box that can't be reached back defines DJINN_SITE_TOKEN in wp-config.
	 * This tile just reports the connection + credit and offers a card.
	 */
	private function orgSettingsBody(): void {
		$account   = ProxyAccount::fetch();
		$connected = Settings::siteToken() !== '';
		?>
		<div>
			<?php if ( $account !== null ) : ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Free wishes left</th><td><?php echo (int) ( $account['wishesLeft'] ?? 0 ); ?></td></tr>
					<tr><th scope="row">Credit</th><td>$<?php echo esc_html( number_format( (float) ( $account['balanceUsd'] ?? 0 ), 4 ) ); ?></td></tr>
				</table>
				<h2>Payment method</h2>
				<?php if ( ! empty( $account['payg'] ) ) : ?>
					<p>✓ A card is on file — automatic top-up is enabled, so wishes keep working past the free trial.</p>
				<?php else : ?>
					<p>Add a card to keep wishing after your free wishes — prepaid with automatic top-up, no charge now.</p>
					<div id="djinn-payment-element" style="max-width:480px;margin:.6em 0;display:none"></div>
					<button type="button" class="button button-primary" id="djinn-add-card">Add a card</button>
					<span id="djinn-billing-msg" style="margin-left:.6em"></span>
				<?php endif; ?>
			<?php elseif ( $connected ) : ?>
				<div class="notice notice-warning inline"><p>Connected, but your Djinn account is unreachable right now. Reload in a moment.</p></div>
			<?php else : ?>
				<div class="notice notice-info inline"><p>Linking this site to Djinn's hosted service — reload in a moment, and your first three wishes are free. If the site isn't publicly reachable (e.g. localhost), it can't be verified automatically: define <code>DJINN_SITE_TOKEN</code> in <code>wp-config.php</code> with a token minted from the proxy admin.</p></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** BYO edition: provider + key (or our proxy via a token) + model dropdowns. */
	private function byoSettingsBody(): void {
		// Allow a manual refresh of the discovered model list.
		if ( isset( $_GET['djinn_refresh'] ) && check_admin_referer( 'djinn_refresh_models' ) ) {
			ModelCatalog::flush();
			echo '<div class="notice notice-success is-dismissible"><p>Model list refreshed.</p></div>';
		}

		$s       = Settings::all();
		$catalog = ModelCatalog::forProvider( $s['provider'], Settings::apiKey() );
		?>
		<div>
			<p>Place your offering in the lamp.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'djinn' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="djinn-provider">LLM provider</label></th>
						<td>
							<select id="djinn-provider" name="djinn_settings[provider]">
								<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI (your key)</option>
								<option value="gemini" <?php selected( $s['provider'], 'gemini' ); ?>>Google Gemini (your key)</option>
								<option value="anthropic" <?php selected( $s['provider'], 'anthropic' ); ?>>Anthropic Claude (your key)</option>
								<option value="claude-max" <?php selected( $s['provider'], 'claude-max' ); ?>>Claude Max subscription — experimental</option>
								<option value="proxy" <?php selected( $s['provider'], 'proxy' ); ?>>Djinn proxy (your account)</option>
							</select>
							<p class="description">Switch provider and <strong>Save</strong>. OpenAI/Gemini/Anthropic use your API key; Djinn proxy uses your account token.</p>
							<?php if ( $s['provider'] === 'claude-max' ) : ?>
								<p class="description" style="color:#b35900">⚠ <strong>Experimental.</strong> Paste a <code>claude setup-token</code> from your Claude Max subscription into the API key field. This uses your subscription's Claude Code quota — it may breach Anthropic's terms (subscriptions are sold for use <em>within</em> Claude Code), can be rate-limited or revoked, and the OAuth handshake may change. Anthropic has no embeddings, so search runs on the full schema.</p>
							<?php elseif ( $s['provider'] === 'anthropic' ) : ?>
								<p class="description">Anthropic has no embeddings API — schema search runs on the full schema (no index needed).</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-key">API key</label></th>
						<td>
							<input type="password" id="djinn-key" name="djinn_settings[api_key]" class="regular-text"
								placeholder="<?php echo $s['api_key'] ? '•••••••• (saved — leave blank to keep)' : 'Paste your key'; ?>" autocomplete="off" />
							<p class="description">For OpenAI/Gemini. Or define <code>DJINN_API_KEY</code> in <code>wp-config.php</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-token">Djinn account token</label></th>
						<td>
							<input type="password" id="djinn-token" name="djinn_settings[site_token]" class="regular-text"
								placeholder="<?php echo $s['site_token'] ? '•••••••• (saved — leave blank to keep)' : 'For the Djinn proxy provider'; ?>" autocomplete="off" />
							<p class="description">Only needed if you pick the <strong>Djinn proxy</strong> provider.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-chat">Chat model</label></th>
						<td><?php $this->modelSelect( 'chat_model', 'djinn-chat', $catalog['chat'], (string) $s['chat_model'], true ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="djinn-embed">Embedding model</label></th>
						<td><?php $this->modelSelect( 'embedding_model', 'djinn-embed', $catalog['embed'], (string) $s['embedding_model'] ); ?></td>
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
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::CAVE_SLUG, 'djinn_refresh' => '1' ], admin_url( 'admin.php' ) ), 'djinn_refresh_models' ) ); ?>">Refresh model list</a>
				</p>

				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * A model <select> annotated with estimated prices. An empty first option keeps Djinn's
	 * per-provider default. The saved value is always present, even if discovery didn't list it
	 * (e.g. a now-retired model), so saving never silently changes the selection. When $tiered,
	 * chat models are grouped by capability so weak ones are clearly set apart.
	 *
	 * @param array<int,string> $models
	 */
	private function modelSelect( string $field, string $id, array $models, string $current, bool $tiered = false ): void {
		if ( $current !== '' && ! in_array( $current, $models, true ) ) {
			array_unshift( $models, $current );
		}
		echo '<select id="' . esc_attr( $id ) . '" name="djinn_settings[' . esc_attr( $field ) . ']" class="regular-text">';
		echo '<option value="">Provider default</option>';

		if ( ! $tiered ) {
			foreach ( $models as $model ) {
				$this->modelOption( $model, $current );
			}
			echo '</select>';
			return;
		}

		$groups  = [
			'recommended' => 'Recommended',
			'standard'    => 'Other models',
			'limited'     => 'Not recommended — too small for multi-step wishes',
		];
		$buckets = [ 'recommended' => [], 'standard' => [], 'limited' => [] ];
		foreach ( $models as $model ) {
			$buckets[ ModelCatalog::chatTier( $model ) ][] = $model;
		}
		foreach ( $groups as $key => $groupLabel ) {
			if ( empty( $buckets[ $key ] ) ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( $groupLabel ) . '">';
			foreach ( $buckets[ $key ] as $model ) {
				$this->modelOption( $model, $current );
			}
			echo '</optgroup>';
		}
		echo '</select>';
	}

	/** One priced <option>, marked selected when it is the current value. */
	private function modelOption( string $model, string $current ): void {
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $model ),
			selected( $current, $model, false ),
			esc_html( $model . ' — ' . Pricing::describe( $model ) )
		);
	}
}
