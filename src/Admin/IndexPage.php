<?php

declare( strict_types=1 );

namespace Djinn\Admin;

use Djinn\Rag\Indexer;
use Throwable;

/**
 * Non-JS fallback for rebuilding the RAG index. The Cave (Capabilities tile) drives reindexing via
 * the REST `/reindex` route now; this admin-post handler stays for form-based / no-JS use.
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

}
