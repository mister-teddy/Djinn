<?php

declare( strict_types=1 );

namespace Djinn\Rest;

use Djinn\Engine\AgentLoop;
use Djinn\Rag\Indexer;
use Djinn\Store\Repository;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST surface for the admin SPA. Everything requires manage_options and a valid nonce.
 */
class Controller {

	private const NS = 'djinn/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$auth = [ $this, 'canManage' ];

		register_rest_route( self::NS, '/wish', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'wish' ],
		] );

		register_rest_route( self::NS, '/grant', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'grant' ],
		] );

		register_rest_route( self::NS, '/chats', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'chats' ],
		] );

		register_rest_route( self::NS, '/chats/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'chat' ],
		] );

		register_rest_route( self::NS, '/reindex', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'reindex' ],
		] );

		register_rest_route( self::NS, '/usage', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'usage' ],
		] );
	}

	public function canManage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function wish( WP_REST_Request $req ): WP_REST_Response {
		$text = trim( (string) $req->get_param( 'message' ) );
		if ( $text === '' ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Whisper something.' ], 400 );
		}

		$chatId = (int) $req->get_param( 'chat_id' );
		if ( $chatId <= 0 ) {
			$chatId = Repository::createChat( get_current_user_id(), $text );
		} elseif ( ! $this->ownsChat( $chatId ) ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Not your lamp.' ], 403 );
		}

		return new WP_REST_Response( ( new AgentLoop() )->run( $chatId, $text ) );
	}

	public function grant( WP_REST_Request $req ): WP_REST_Response {
		$chatId    = (int) $req->get_param( 'chat_id' );
		$pendingId = (int) $req->get_param( 'pending_id' );
		$confirmed = (bool) $req->get_param( 'confirmed' );

		if ( ! $this->ownsChat( $chatId ) ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Not your lamp.' ], 403 );
		}

		return new WP_REST_Response( ( new AgentLoop() )->resume( $chatId, $pendingId, $confirmed ) );
	}

	public function chats(): WP_REST_Response {
		return new WP_REST_Response( Repository::listChats( get_current_user_id() ) );
	}

	public function chat( WP_REST_Request $req ): WP_REST_Response {
		$chatId = (int) $req['id'];
		if ( ! $this->ownsChat( $chatId ) ) {
			return new WP_REST_Response( [ 'message' => 'Not your lamp.' ], 403 );
		}

		// Return only the human-visible turns (wishes + Djinn replies).
		$messages = [];
		foreach ( Repository::getMessages( $chatId ) as $entry ) {
			$role = $entry['role'] ?? '';
			if ( in_array( $role, [ 'user', 'assistant' ], true ) && ! empty( $entry['content'] ) ) {
				$messages[] = [ 'role' => $role, 'content' => $entry['content'] ];
			}
		}
		return new WP_REST_Response( [ 'chat_id' => $chatId, 'messages' => $messages ] );
	}

	public function reindex(): WP_REST_Response {
		try {
			$count = Indexer::reindex();
			return new WP_REST_Response( [ 'status' => 'ok', 'chunks' => $count ] );
		} catch ( Throwable $e ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => $e->getMessage() ], 500 );
		}
	}

	public function usage(): WP_REST_Response {
		return new WP_REST_Response( Repository::usageSummary() );
	}

	private function ownsChat( int $chatId ): bool {
		return Repository::chatOwner( $chatId ) === get_current_user_id();
	}
}
