<?php

declare( strict_types=1 );

namespace Djinn\Rest;

use Djinn\Engine\AgentLoop;
use Djinn\Files\Downloads;
use Djinn\GraphQL\Runner;
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

		register_rest_route( self::NS, '/wish/stream', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'wishStream' ],
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

		register_rest_route( self::NS, '/download', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'download' ],
		] );

		register_rest_route( self::NS, '/upload', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'upload' ],
		] );
	}

	/**
	 * Accept a chat attachment, store it in the private dir, and return a token the model can pass
	 * to import tools (e.g. importWxr). Type-restricted; nonce + manage_options enforced.
	 */
	public function upload( WP_REST_Request $req ): WP_REST_Response {
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification — REST nonce already checked
		if ( ! is_array( $f ) || ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return new WP_REST_Response( [ 'message' => 'No file was uploaded.' ], 400 );
		}
		if ( (int) $f['size'] > wp_max_upload_size() ) {
			return new WP_REST_Response( [ 'message' => 'That file is too large.' ], 413 );
		}
		$check   = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'] );
		$ext     = strtolower( (string) ( $check['ext'] ?: pathinfo( $f['name'], PATHINFO_EXTENSION ) ) );
		$allowed = [ 'xml', 'json', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip' ];
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_REST_Response( [ 'message' => "Unsupported file type: .$ext" ], 415 );
		}

		$dir  = Downloads::dir();
		$name = wp_unique_filename( $dir, sanitize_file_name( $f['name'] ) );
		$path = trailingslashit( $dir ) . $name;
		if ( ! @move_uploaded_file( $f['tmp_name'], $path ) ) {
			return new WP_REST_Response( [ 'message' => 'Could not store the upload.' ], 500 );
		}
		$mime = $check['type'] ?: 'application/octet-stream';
		return new WP_REST_Response( [
			'token'    => Downloads::register( $path, $name, $mime ),
			'filename' => $name,
			'mime'     => $mime,
			'size'     => (int) filesize( $path ),
		] );
	}

	/**
	 * Stream a generated export/dump for download by its short-lived token, then exit (we set our
	 * own headers, so this bypasses WP_REST_Response). The token maps to a file in the private dir.
	 */
	public function download( WP_REST_Request $req ) {
		$file = Downloads::resolve( (string) $req->get_param( 'token' ) );
		if ( ! $file ) {
			return new WP_REST_Response( [ 'message' => 'That download has expired or does not exist.' ], 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( $file['mime'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $file['filename'] ) . '"' );
		header( 'Content-Length: ' . filesize( $file['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $file['path'] );
		exit;
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

	/**
	 * Like wish(), but streams the turn over Server-Sent Events: an 'open' event with the chat_id,
	 * then 'step'/'delta' events, and a terminal 'done' | 'pending' | 'error'. Bypasses
	 * WP_REST_Response (we own the headers) and exits.
	 */
	public function wishStream( WP_REST_Request $req ) {
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

		$this->openStream();
		$emit = static function ( string $event, array $data ): void {
			echo 'event: ' . $event . "\n";
			echo 'data: ' . wp_json_encode( $data ) . "\n\n";
			@ob_flush();
			@flush();
		};
		$emit( 'open', [ 'chat_id' => $chatId ] );
		( new AgentLoop() )->streamRun( $chatId, $text, $emit );
		exit;
	}

	/** Prepare the request for Server-Sent Events: kill buffering, set streaming headers. */
	private function openStream(): void {
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', '0' );
		@ini_set( 'implicit_flush', '1' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		ob_implicit_flush( true );
		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-transform' );
		header( 'X-Accel-Buffering: no' );
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

		return new WP_REST_Response( [
			'chat_id'  => $chatId,
			'messages' => $this->transcript( $chatId ),
			'usage'    => Repository::chatUsage( $chatId ),
		] );
	}

	/**
	 * A replayable view of a conversation: wishes, Djinn's replies, the GraphQL operations it ran
	 * (read-only "incantation" cards), and any mutation still awaiting a grant.
	 *
	 * Calls are paired with their results by ORDER, not by tool_call_id — the loop runs exactly
	 * one tool per assistant turn and its result follows immediately, and some providers reuse the
	 * same id for every call. A run_graphql call that never gets a result is a pending mutation.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function transcript( int $chatId ): array {
		$out      = [];
		$awaiting = -1; // index in $out of a run_graphql action awaiting its result; -2 = ignore (search_schema)

		foreach ( Repository::getMessages( $chatId ) as $entry ) {
			$role = $entry['role'] ?? '';

			if ( $role === 'user' ) {
				if ( ! empty( $entry['content'] ) ) {
					$out[] = [ 'role' => 'user', 'content' => (string) $entry['content'] ];
				}
				continue;
			}

			if ( $role === 'tool' ) {
				if ( $awaiting >= 0 ) {
					$out[ $awaiting ] = $this->applyResult( $out[ $awaiting ], $entry );
				}
				$awaiting = -1;
				continue;
			}

			if ( $role !== 'assistant' ) {
				continue;
			}

			if ( ! empty( $entry['content'] ) ) {
				$out[] = [ 'role' => 'assistant', 'content' => (string) $entry['content'] ];
			}
			foreach ( $entry['tool_calls'] ?? [] as $call ) {
				$name = $call['name'] ?? '';
				if ( $name === 'run_graphql' ) {
					$out[]    = $this->baseAction( $call );
					$awaiting = count( $out ) - 1;
				} elseif ( $name !== '' ) {
					$awaiting = -2; // e.g. search_schema — consume its result, don't surface it
				}
			}
		}

		// A run_graphql action that never received a result is a mutation still awaiting a grant.
		if ( $awaiting >= 0 ) {
			$open   = Repository::openPending( $chatId );
			$action = $out[ $awaiting ];
			$out[ $awaiting ] = [
				'role'       => 'pending',
				'pending_id' => $open ? (int) $open['id'] : 0,
				'summary'    => $action['summary'] ?? ( $open['summary'] ?? '' ),
				'operation'  => $action['operation'],
				'variables'  => $action['variables'],
			];
		}

		return $out;
	}

	/**
	 * A run_graphql call as a transcript entry, before its result is known. The summary comes
	 * straight from the call's arguments (the model supplies one for mutations).
	 *
	 * @param array<string,mixed> $call
	 * @return array<string,mixed>
	 */
	private function baseAction( array $call ): array {
		$args      = $call['arguments'] ?? [];
		$operation = (string) ( $args['operation'] ?? '' );

		try {
			$kind = Runner::operationType( $operation );
		} catch ( Throwable $e ) {
			$kind = 'query';
		}

		$entry = [
			'role'      => 'action',
			'kind'      => $kind,
			'status'    => $kind === 'mutation' ? 'granted' : 'ok', // refined once the result arrives
			'operation' => $operation,
			'variables' => (array) ( $args['variables'] ?? [] ),
		];
		if ( ! empty( $args['summary'] ) ) {
			$entry['summary'] = (string) $args['summary'];
		}
		return $entry;
	}

	/**
	 * Fold a tool result into its action entry: refused, error (with message), or success (with
	 * the GraphQL response payload).
	 *
	 * @param array<string,mixed> $action
	 * @param array<string,mixed> $toolEntry
	 * @return array<string,mixed>
	 */
	private function applyResult( array $action, array $toolEntry ): array {
		$res = json_decode( (string) ( $toolEntry['content'] ?? '' ), true );
		if ( ! is_array( $res ) ) {
			return $action;
		}
		if ( ! empty( $res['refused'] ) ) {
			$action['status'] = 'refused';
		} elseif ( isset( $res['error'] ) ) {
			$action['status']  = 'error';
			$action['message'] = (string) $res['error'];
		} else {
			$action['status'] = $action['kind'] === 'mutation' ? 'granted' : 'ok';
			$action['result'] = $res; // the GraphQL response the Djinn got back
		}
		return $action;
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
