<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Rag\IndexStatus;
use Djinn\Rag\Indexer;
use Djinn\Settings;
use Throwable;

/**
 * "The Lamp's Memory" — the one place to (re)build the RAG schema index. It shows whether the
 * index is current, what a rebuild would cost, and a diff of what it would gain, and disables the
 * button when nothing has changed. Reindexing no longer lives on the chat screen.
 */
class IndexPage {

	private const PARENT = 'djinn';
	private const SLUG   = 'djinn-index';
	private const ACTION = 'djinn_reindex_now';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 11 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handleReindex' ] );
	}

	public function menu(): void {
		add_submenu_page( self::PARENT, 'Djinn — Memory', 'Memory' . IndexStatus::menuBubble(), 'manage_options', self::SLUG, [ $this, 'render' ] );
	}

	public function handleReindex(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not your lamp.' );
		}
		check_admin_referer( self::ACTION );

		$args = [ 'page' => self::SLUG ];
		try {
			$count        = Indexer::reindex();
			$args['done'] = $count;
		} catch ( Throwable $e ) {
			$args['error'] = rawurlencode( $e->getMessage() );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! Settings::isConfigured() ) {
			echo '<div class="wrap"><h1>Djinn — Memory</h1><div class="notice notice-info"><p>Add an API key under <strong>Djinn → Settings</strong> first — the index is built with embeddings.</p></div></div>';
			return;
		}

		$s = IndexStatus::summary();
		echo '<div class="wrap djinn-index">';
		echo '<h1>Djinn — The Lamp\'s Memory</h1>';
		echo '<p class="description">The Djinn searches this index to find the right GraphQL for a wish. Rebuild it whenever the schema changes (new capabilities, plugins). It re-embeds the whole schema each time.</p>';

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-success is-dismissible"><p>The lamp is awake — indexed %d schema types.</p></div>', (int) $_GET['done'] );
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-error"><p>Reindex failed: %s</p></div>', esc_html( rawurldecode( (string) wp_unslash( $_GET['error'] ) ) ) );
		}

		// Status line.
		if ( ! $s['indexed'] ) {
			echo '<div class="notice notice-warning"><p><strong>The lamp slumbers.</strong> Build the index to sharpen the Djinn\'s memory of your site.</p></div>';
		} elseif ( $s['up_to_date'] ) {
			printf(
				'<div class="notice notice-success"><p><strong>Up to date.</strong> %d types indexed with <code>%s</code>%s.</p></div>',
				(int) $s['count_stored'],
				esc_html( (string) $s['stored_model'] ),
				$s['indexed_at'] ? ' on ' . esc_html( (string) $s['indexed_at'] ) . ' UTC' : ''
			);
		} else {
			echo '<div class="notice notice-warning"><p><strong>Out of date.</strong> The schema or embedding model has changed since the last build — rebuild to apply.</p></div>';
		}

		$est = $s['estimate'];
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row">Embedding model</th><td><code>%s</code></td></tr>', esc_html( (string) $s['model'] ) );
		printf( '<tr><th scope="row">Schema types (live)</th><td>%d</td></tr>', (int) $s['count_live'] );
		printf(
			'<tr><th scope="row">Estimated rebuild cost</th><td>%s <span class="description">(~%s tokens across %d chunks)</span></td></tr>',
			esc_html( self::money( $est ) ),
			esc_html( number_format_i18n( (int) $est['tokens'] ) ),
			(int) $est['chunks']
		);
		echo '</tbody></table>';

		// Diff of what a rebuild would change.
		$this->renderDiff( $s['diff'], (bool) $s['indexed'] );

		// Action.
		$disabled = $s['up_to_date'] ? 'disabled' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:18px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		printf(
			'<button type="submit" class="button button-primary" %s>%s</button>',
			esc_attr( $disabled ),
			$s['indexed'] ? 'Rebuild the index' : 'Awaken the lamp'
		);
		if ( $s['up_to_date'] ) {
			echo ' <span class="description">Nothing to rebuild — the index matches the current schema.</span>';
		}
		echo '</form>';
		echo '</div>';
	}

	/**
	 * @param array{added:array<int,string>,removed:array<int,string>,changed:array<int,string>} $diff
	 */
	private function renderDiff( array $diff, bool $indexed ): void {
		if ( ! $indexed ) {
			return; // first build — everything is "added"; the type count above already conveys it
		}
		if ( ! $diff['added'] && ! $diff['removed'] && ! $diff['changed'] ) {
			return;
		}
		echo '<h2>What a rebuild would change</h2><ul class="djinn-diff">';
		foreach ( [ 'added' => '＋ New', 'changed' => '～ Updated', 'removed' => '－ Removed' ] as $key => $label ) {
			foreach ( $diff[ $key ] as $name ) {
				printf( '<li class="djinn-diff-%s"><strong>%s</strong> <code>%s</code></li>', esc_attr( $key ), esc_html( $label ), esc_html( $name ) );
			}
		}
		echo '</ul>';
		echo '<style>
			.djinn-diff{margin:8px 0 0;max-width:680px}
			.djinn-diff li{padding:6px 10px;border-radius:6px;margin:3px 0;font-size:13px}
			.djinn-diff-added{background:rgba(110,231,183,0.14)}
			.djinn-diff-changed{background:rgba(251,191,36,0.14)}
			.djinn-diff-removed{background:rgba(248,113,113,0.14)}
		</style>';
	}

	/** @param array{cost:float,free:bool,unpriced:bool} $est */
	private static function money( array $est ): string {
		if ( $est['unpriced'] ) {
			return 'unknown (no price for this model)';
		}
		if ( $est['free'] || $est['cost'] === 0.0 ) {
			return 'free';
		}
		if ( $est['cost'] < 0.01 ) {
			return '$' . rtrim( rtrim( number_format( $est['cost'], 6 ), '0' ), '.' );
		}
		return '$' . number_format( $est['cost'], 2 );
	}
}
