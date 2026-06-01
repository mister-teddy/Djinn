<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Provider\ProxyAccount;
use Djinn\Settings;
use Djinn\Store\Repository;
use Djinn\Usage\Pricing;

/**
 * The "Spend" tile of the Cave of Wonders — token usage and estimated spend, read straight from the
 * usage table and drawn as plain HTML/CSS. Rendered as a tile body by AdminPage; this class owns
 * only the reset handler.
 */
class UsagePage {

	private const CAVE   = 'djinn-cave';
	private const ACTION = 'djinn_reset_usage';

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handleReset' ] );
	}

	public function handleReset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not your lamp.' );
		}
		check_admin_referer( self::ACTION );
		Repository::clearUsage();
		wp_safe_redirect( add_query_arg( [ 'page' => self::CAVE, 'reset' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function renderBody(): void {
		$summary = Repository::usageSummary();
		$totals  = $summary['totals'];
		$byModel = $summary['by_model'];
		$byDay   = $summary['by_day'];
		$recent  = $summary['recent'];

		echo '<div class="djinn-usage">';
		echo '<p class="description">Every wish spends a little of the lamp\'s oil. Costs are estimates from public list prices (USD); edit them with the <code>djinn_model_pricing</code> filter.</p>';

		if ( isset( $_GET['reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>The tally has been wiped clean.</p></div>';
		}

		// In proxy mode, the authoritative balance lives on the proxy — show it up top.
		if ( Settings::usesProxy() ) {
			$account = ProxyAccount::fetch();
			if ( $account === null ) {
				echo '<div class="notice notice-warning inline"><p>Couldn\'t reach your Djinn account. Check your token in the <strong>Account</strong> tile.</p></div>';
			} else {
				echo '<div class="djinn-cards">';
				printf(
					'<div class="djinn-card"><div class="djinn-card-value">%d</div><div class="djinn-card-label">Free wishes left</div><div class="djinn-card-sub">of three</div></div>',
					(int) ( $account['wishesLeft'] ?? 0 )
				);
				printf(
					'<div class="djinn-card"><div class="djinn-card-value">$%s</div><div class="djinn-card-label">Account credit</div><div class="djinn-card-sub">%s</div></div>',
					esc_html( number_format( (float) ( $account['balanceUsd'] ?? 0 ), 4 ) ),
					! empty( $account['payg'] ) ? 'pay-as-you-go active' : 'top up to continue'
				);
				echo '</div>';
			}
			echo '<h2>Local token tally</h2>';
		}

		if ( (int) $totals['calls'] === 0 ) {
			echo '<div class="notice notice-info"><p>No wishes granted yet — the tally is empty. Make a wish on <strong>Djinn → Lamp</strong> and it will appear here.</p></div>';
			echo '</div>';
			return;
		}

		// --- Summary cards ---------------------------------------------------
		$cards = [
			[ 'label' => 'Estimated spend', 'value' => self::money( (float) $totals['cost'] ), 'sub' => $totals['has_estimates'] ? 'includes estimated tokens' : 'all metered' ],
			[ 'label' => 'Provider calls', 'value' => number_format_i18n( $totals['calls'] ), 'sub' => 'chat + embed' ],
			[ 'label' => 'Input tokens', 'value' => number_format_i18n( $totals['prompt'] ), 'sub' => 'prompt' ],
			[ 'label' => 'Output tokens', 'value' => number_format_i18n( $totals['completion'] ), 'sub' => 'completion' ],
		];
		echo '<div class="djinn-cards">';
		foreach ( $cards as $card ) {
			printf(
				'<div class="djinn-card"><div class="djinn-card-value">%s</div><div class="djinn-card-label">%s</div><div class="djinn-card-sub">%s</div></div>',
				esc_html( $card['value'] ),
				esc_html( $card['label'] ),
				esc_html( $card['sub'] )
			);
		}
		echo '</div>';

		// --- Daily spend bars ------------------------------------------------
		echo '<h2>Last 14 days</h2>';
		$this->renderDailyBars( $byDay );

		// --- Per-model breakdown --------------------------------------------
		echo '<h2>By model</h2>';
		echo '<table class="widefat striped djinn-table"><thead><tr>';
		foreach ( [ 'Provider', 'Model', 'Kind', 'Calls', 'Input', 'Output', 'Est. cost' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $byModel as $row ) {
			$unpriced = ! Pricing::isKnown( (string) $row['model'] );
			echo '<tr>';
			echo '<td>' . esc_html( ucfirst( (string) $row['provider'] ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['model'] ) . '</code>'
				. ( $unpriced ? ' <span class="djinn-flag" title="No price in the table — tokens tracked, cost shown as $0">no price</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) $row['kind'] )
				. ( (int) $row['estimated'] === 1 ? ' <span class="djinn-flag" title="Token counts estimated">~est</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['calls'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['prompt'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['completion'] ) ) . '</td>';
			echo '<td>' . esc_html( self::money( (float) $row['cost'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// --- Recent activity -------------------------------------------------
		echo '<h2>Recent calls</h2>';
		echo '<table class="widefat striped djinn-table"><thead><tr>';
		foreach ( [ 'When (UTC)', 'Provider', 'Model', 'Kind', 'In', 'Out', 'Est. cost' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $recent as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( ucfirst( (string) $row['provider'] ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['model'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['kind'] )
				. ( (int) $row['estimated'] === 1 ? ' <span class="djinn-flag">~est</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['prompt_tokens'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $row['completion_tokens'] ) ) . '</td>';
			echo '<td>' . esc_html( self::money( (float) $row['cost'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// --- Reset -----------------------------------------------------------
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="djinn-reset" onsubmit="return confirm(\'Wipe the entire usage tally? This cannot be undone.\');">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		submit_button( 'Reset the tally', 'delete', 'submit', false );
		echo '</form>';

		echo '</div>';

		$this->styles();
	}

	/** @param array<int,array<string,mixed>> $byDay */
	private function renderDailyBars( array $byDay ): void {
		// Index the rows we have by day, then fill a continuous 14-day window so gaps show as 0.
		$byKey = [];
		foreach ( $byDay as $row ) {
			$byKey[ (string) $row['day'] ] = (float) $row['cost'];
		}
		$days = [];
		for ( $i = 13; $i >= 0; $i-- ) {
			$key            = gmdate( 'Y-m-d', strtotime( "-$i day", strtotime( gmdate( 'Y-m-d' ) ) ) );
			$days[ $key ]   = $byKey[ $key ] ?? 0.0;
		}
		$max = max( $days ) ?: 0.0;

		echo '<div class="djinn-bars">';
		foreach ( $days as $day => $cost ) {
			$pct   = $max > 0 ? max( 3, (int) round( ( $cost / $max ) * 100 ) ) : 3;
			$label = esc_attr( $day . ' — ' . self::money( $cost ) );
			printf(
				'<div class="djinn-bar-col" title="%s"><div class="djinn-bar" style="height:%d%%"></div><div class="djinn-bar-x">%s</div></div>',
				$label,
				$pct,
				esc_html( gmdate( 'j M', strtotime( $day ) ) )
			);
		}
		echo '</div>';
	}

	/** Adaptive currency: small amounts keep their tiny digits instead of rounding to $0.00. */
	private static function money( float $usd ): string {
		if ( $usd === 0.0 ) {
			return '$0.00';
		}
		if ( $usd < 0.01 ) {
			return '$' . rtrim( rtrim( number_format( $usd, 6 ), '0' ), '.' );
		}
		return '$' . number_format( $usd, 2 );
	}

	private function styles(): void {
		echo '<style>
			.djinn-usage .djinn-cards{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0 10px}
			.djinn-usage .djinn-card{flex:1 1 180px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px}
			.djinn-usage .djinn-card-value{font-size:26px;font-weight:600;line-height:1.1}
			.djinn-usage .djinn-card-label{margin-top:6px;font-weight:600;color:#1d2327}
			.djinn-usage .djinn-card-sub{color:#787c82;font-size:12px;margin-top:2px}
			.djinn-usage .djinn-bars{display:flex;align-items:flex-end;gap:8px;height:160px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-bottom:8px}
			.djinn-usage .djinn-bar-col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%}
			.djinn-usage .djinn-bar{width:60%;min-height:3px;background:linear-gradient(180deg,#c89b3c,#8a6d1f);border-radius:4px 4px 0 0}
			.djinn-usage .djinn-bar-x{font-size:10px;color:#787c82;margin-top:6px;white-space:nowrap}
			.djinn-usage .djinn-table{margin-top:8px;max-width:960px}
			.djinn-usage .djinn-flag{display:inline-block;font-size:10px;background:#f0e6cf;color:#7a5c12;border-radius:4px;padding:1px 5px;vertical-align:middle}
			.djinn-usage .djinn-reset{margin-top:24px}
		</style>';
	}
}
