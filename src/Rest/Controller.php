<?php

declare( strict_types=1 );

namespace Djinn\Rest;

use Djinn\Engine\AgentLoop;
use Djinn\Files\Downloads;
use Djinn\GraphQL\Admin\AdminSchema;
use Djinn\GraphQL\PairingSchema;
use Djinn\Store\Repository;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST surface for the admin SPA. The control plane (settings, account, billing, index, usage, chat
 * CRUD) is served by the GraphQL endpoint; the REST routes here are the parts GraphQL can't carry —
 * the streaming wish turn, binary upload/download, and the public proxy site-binding callback.
 * Everything except /claim requires manage_options and a valid nonce.
 */
class Controller {

	private const NS = 'djinn/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$auth = array( $this, 'canManage' );

		register_rest_route(
			self::NS,
			'/wish',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( $this, 'wish' ),
			)
		);

		register_rest_route(
			self::NS,
			'/wish/stream',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( $this, 'wishStream' ),
			)
		);

		register_rest_route(
			self::NS,
			'/grant',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( $this, 'grant' ),
			)
		);

		register_rest_route(
			self::NS,
			'/upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( $this, 'upload' ),
			)
		);

		register_rest_route(
			self::NS,
			'/download',
			array(
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => array( $this, 'download' ),
			)
		);

		// PUBLIC: the hosted proxy calls this back during register() to push this site's token. The
		// PairingSchema stores it only while connect() has a pairing window open, and the proxy can't
		// be a WP user — hence no auth here. Knowing the URL grants nothing without an open window.
		register_rest_route(
			self::NS,
			'/claim',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'claim' ),
			)
		);

		// The admin control-plane GraphQL endpoint the Cave + Lamp SPAs query. Same manage_options +
		// nonce gate as the REST routes above.
		register_rest_route(
			self::NS,
			'/graphql',
			array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( $this, 'graphql' ),
			)
		);
	}

	/** Execute one admin GraphQL operation. Always 200; failures live in the body's `errors`. */
	public function graphql( WP_REST_Request $req ): WP_REST_Response {
		$body   = (array) $req->get_json_params();
		$query  = (string) ( $body['query'] ?? '' );
		$vars   = isset( $body['variables'] ) && is_array( $body['variables'] ) ? $body['variables'] : null;
		$debug  = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
			: DebugFlag::NONE;
		$result = GraphQL::executeQuery(
			AdminSchema::build(),
			$query,
			null,
			array( 'currentUserId' => get_current_user_id() ),
			$vars
		)->toArray( $debug );
		return new WP_REST_Response( $result );
	}

	/** Public pairing endpoint: the proxy pushes this site's token here during connect(). */
	public function claim( WP_REST_Request $req ): WP_REST_Response {
		$body   = (array) $req->get_json_params();
		$query  = (string) ( $body['query'] ?? '' );
		$vars   = isset( $body['variables'] ) && is_array( $body['variables'] ) ? $body['variables'] : null;
		$result = GraphQL::executeQuery( PairingSchema::build(), $query, null, null, $vars )->toArray( DebugFlag::NONE );
		return new WP_REST_Response( $result );
	}

	public function wish( WP_REST_Request $req ): WP_REST_Response {
		$text        = trim( (string) $req->get_param( 'message' ) );
		$attachments = $this->attachmentsParam( $req );
		if ( $text === '' && ! $attachments ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Whisper something.',
				),
				400
			);
		}

		$chatId = (int) $req->get_param( 'chat_id' );
		if ( $chatId <= 0 ) {
			$chatId = Repository::createChat( get_current_user_id(), $this->chatTitle( $text, $attachments ) );
		} elseif ( ! $this->ownsChat( $chatId ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Not your lamp.',
				),
				403
			);
		}

		return new WP_REST_Response( ( new AgentLoop() )->run( $chatId, $text, $attachments ) );
	}

	/**
	 * Like wish(), but streams the turn over Server-Sent Events: an 'open' event with the chat_id,
	 * then 'step'/'delta' events, and a terminal 'done' | 'pending' | 'error'. Bypasses
	 * WP_REST_Response (we own the headers) and exits.
	 */
	public function wishStream( WP_REST_Request $req ) {
		$text        = trim( (string) $req->get_param( 'message' ) );
		$attachments = $this->attachmentsParam( $req );
		if ( $text === '' && ! $attachments ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Whisper something.',
				),
				400
			);
		}
		$chatId = (int) $req->get_param( 'chat_id' );
		if ( $chatId <= 0 ) {
			$chatId = Repository::createChat( get_current_user_id(), $this->chatTitle( $text, $attachments ) );
		} elseif ( ! $this->ownsChat( $chatId ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Not your lamp.',
				),
				403
			);
		}

		$this->openStream();
		$emit = static function ( string $event, array $data ): void {
			echo 'event: ' . $event . "\n";
			echo 'data: ' . wp_json_encode( $data ) . "\n\n";
			@ob_flush();
			@flush();
		};
		$emit( 'open', array( 'chat_id' => $chatId ) );
		( new AgentLoop() )->streamRun( $chatId, $text, $emit, $attachments );
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
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Not your lamp.',
				),
				403
			);
		}

		return new WP_REST_Response( ( new AgentLoop() )->resume( $chatId, $pendingId, $confirmed ) );
	}

	/**
	 * Accept a chat attachment, store it in the private dir, and return a token the model can pass
	 * to import tools (e.g. importWxr). Type-restricted; nonce + manage_options enforced.
	 */
	public function upload( WP_REST_Request $req ): WP_REST_Response {
		$f = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification — REST nonce already checked
		if ( ! is_array( $f ) || ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return new WP_REST_Response( array( 'message' => 'No file was uploaded.' ), 400 );
		}
		if ( (int) $f['size'] > wp_max_upload_size() ) {
			return new WP_REST_Response( array( 'message' => 'That file is too large.' ), 413 );
		}
		$check   = wp_check_filetype_and_ext( $f['tmp_name'], $f['name'] );
		$ext     = strtolower( (string) ( $check['ext'] ?: pathinfo( $f['name'], PATHINFO_EXTENSION ) ) );
		$allowed = array( 'xml', 'json', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_REST_Response( array( 'message' => "Unsupported file type: .$ext" ), 415 );
		}

		$dir  = Downloads::dir();
		$name = wp_unique_filename( $dir, sanitize_file_name( $f['name'] ) );
		$path = trailingslashit( $dir ) . $name;
		if ( ! @move_uploaded_file( $f['tmp_name'], $path ) ) {
			return new WP_REST_Response( array( 'message' => 'Could not store the upload.' ), 500 );
		}
		$mime = $check['type'] ?: 'application/octet-stream';
		return new WP_REST_Response(
			array(
				'token'    => Downloads::register( $path, $name, $mime ),
				'filename' => $name,
				'mime'     => $mime,
				'size'     => (int) filesize( $path ),
			)
		);
	}

	/**
	 * Stream a generated export/dump for download by its short-lived token, then exit (we set our
	 * own headers, so this bypasses WP_REST_Response). The token maps to a file in the private dir.
	 */
	public function download( WP_REST_Request $req ) {
		$file = Downloads::resolve( (string) $req->get_param( 'token' ) );
		if ( ! $file ) {
			return new WP_REST_Response( array( 'message' => 'That download has expired or does not exist.' ), 404 );
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

	/**
	 * Parsed, sanitized chat attachments from the request body: an array of {filename, token, size}.
	 * The token came from /upload; entries without one are dropped.
	 *
	 * @return array<int,array{filename:string,token:string,size:int}>
	 */
	private function attachmentsParam( WP_REST_Request $req ): array {
		$raw = $req->get_param( 'attachments' );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $a ) {
			$token = is_array( $a ) ? sanitize_text_field( (string) ( $a['token'] ?? '' ) ) : '';
			if ( $token === '' ) {
				continue;
			}
			$out[] = array(
				'filename' => sanitize_file_name( (string) ( $a['filename'] ?? 'file' ) ),
				'token'    => $token,
				'size'     => isset( $a['size'] ) ? max( 0, (int) $a['size'] ) : 0,
			);
		}
		return $out;
	}

	/** A new chat's title: the typed text, or the first attachment's name when the wish is file-only. */
	private function chatTitle( string $text, array $attachments ): string {
		if ( $text !== '' ) {
			return $text;
		}
		return $attachments ? (string) $attachments[0]['filename'] : '';
	}

	private function ownsChat( int $chatId ): bool {
		return Repository::chatOwner( $chatId ) === get_current_user_id();
	}
}
