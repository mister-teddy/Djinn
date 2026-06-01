<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Rag\IndexStatus;
use Djinn\Rag\Indexer;
use Djinn\Settings;
use Throwable;

/**
 * The "Memory" tile of the Cave of Wonders — (re)builds the RAG schema index. It shows whether the
 * index is current, what a rebuild would cost, and a diff of what it would gain, and disables the
 * button when nothing has changed. Rendered as a tile body by AdminPage; this class owns only the
 * reindex handler.
 */
class IndexPage {

	private const CAVE   = 'djinn-cave';
	private const ACTION = 'djinn_reindex_now';

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handleReindex' ] );
	}

	public function handleReindex(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not your lamp.' );
		}
		check_admin_referer( self::ACTION );

		$args = [ 'page' => self::CAVE ];
		try {
			$count        = Indexer::reindex();
			$args['done'] = $count;
		} catch ( Throwable $e ) {
			$args['error'] = rawurlencode( $e->getMessage() );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function renderBody(): void {
		if ( ! Settings::isConfigured() ) {
			echo '<div class="notice notice-info inline"><p>Connect an account in the <strong>Account</strong> tile first — the index is built with embeddings.</p></div>';
			return;
		}

		$s = IndexStatus::summary();

		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-success inline is-dismissible"><p>Index updated — %d schema types indexed.</p></div>', (int) $_GET['done'] );
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-error inline"><p>Reindex failed: %s</p></div>', esc_html( rawurldecode( (string) wp_unslash( $_GET['error'] ) ) ) );
		}

		// Status line.
		if ( ! $s['indexed'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>No index yet.</strong> Build it so the Djinn knows your site\'s schema.</p></div>';
		} elseif ( $s['up_to_date'] ) {
			printf(
				'<div class="notice notice-success inline"><p><strong>Up to date.</strong> %d types indexed with <code>%s</code>%s.</p></div>',
				(int) $s['count_stored'],
				esc_html( (string) $s['stored_model'] ),
				$s['indexed_at'] ? ' on ' . esc_html( (string) $s['indexed_at'] ) . ' UTC' : ''
			);
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>Out of date.</strong> The schema or embedding model changed since the last build — update to apply.</p></div>';
		}

		// Action first. A flex row so the "nothing to update" note sits beside the button; on submit
		// we disable it so a slow (synchronous) rebuild can't be fired twice.
		$disabled = $s['up_to_date'] ? 'disabled' : '';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" '
			. 'style="display:flex;align-items:center;gap:10px;margin:4px 0 20px" '
			. 'onsubmit="var b=this.querySelector(\'button\');b.disabled=true;b.textContent=\'Working…\';">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		$est = $s['estimate'];
		$tip = $s['indexed']
			? sprintf( "Re-reads your site's GraphQL schema — post types, fields, taxonomies, and the capabilities your plugins add — into the searchable index the Djinn uses to pick tools. Rebuild ≈ %s tokens (%s).", number_format_i18n( (int) $est['tokens'] ), self::money( $est ) )
			: sprintf( "Reads your site's GraphQL schema — post types, fields, taxonomies, and the capabilities your plugins add — into a searchable index the Djinn uses to pick tools. Build ≈ %s tokens (%s).", number_format_i18n( (int) $est['tokens'] ), self::money( $est ) );
		printf(
			'<button type="submit" class="button button-primary" title="%s" %s>%s</button>',
			esc_attr( $tip ),
			esc_attr( $disabled ),
			$s['indexed'] ? 'Update RAG' : 'Build RAG'
		);
		if ( $s['up_to_date'] ) {
			echo '<span class="description" style="margin:0">Nothing to update — the index matches your current schema.</span>';
		}
		echo '</form>';

		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row">Embedding model</th><td><code>%s</code></td></tr>', esc_html( (string) $s['model'] ) );
		printf( '<tr><th scope="row">Schema types (live)</th><td>%d</td></tr>', (int) $s['count_live'] );
		printf(
			'<tr><th scope="row">Estimated cost</th><td>%s <span class="description">(~%s tokens across %d chunks)</span></td></tr>',
			esc_html( self::money( $est ) ),
			esc_html( number_format_i18n( (int) $est['tokens'] ) ),
			(int) $est['chunks']
		);
		echo '</tbody></table>';

		// Diff of what a rekindle would change.
		$this->renderDiff( $s['diff'], (bool) $s['indexed'] );
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
