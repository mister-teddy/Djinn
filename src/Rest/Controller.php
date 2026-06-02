<?php

declare( strict_types=1 );

namespace Djinn\Rest;

use Djinn\Engine\AgentLoop;
use Djinn\Files\Downloads;
use Djinn\GraphQL\Runner;
use Djinn\GraphQL\SchemaFactory;
use Djinn\Provider\ModelCatalog;
use Djinn\Provider\Providers;
use Djinn\Provider\ProxyAccount;
use Djinn\Rag\Indexer;
use Djinn\Rag\IndexStatus;
use Djinn\Settings;
use Djinn\Usage\Pricing;
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
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => [ $this, 'chat' ],
			],
			[
				'methods'             => 'DELETE',
				'permission_callback' => $auth,
				'callback'            => [ $this, 'deleteChat' ],
			],
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

		// PUBLIC: the hosted proxy calls this back during /register to prove this site is a real,
		// reachable Djinn install. It only echoes the proxy's nonce — no data, no auth (the proxy
		// isn't a WP user). Safe: knowing the nonce just confirms the site is up and runs Djinn.
		register_rest_route( self::NS, '/verify', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'verify' ],
		] );

		// Relay a Stripe SetupIntent from the proxy (server-side, so the browser never sees the site
		// token and there's no cross-origin call). The in-admin card form calls this.
		register_rest_route( self::NS, '/billing-intent', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'billingIntent' ],
		] );

		// --- Cave dashboard data (read by cave.js) ---------------------------------------------
		register_rest_route( self::NS, '/index-status', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'indexStatus' ],
		] );

		register_rest_route( self::NS, '/operations', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'operations' ],
		] );

		register_rest_route( self::NS, '/account', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'account' ],
		] );

		register_rest_route( self::NS, '/settings', [
			[
				'methods'             => 'GET',
				'permission_callback' => $auth,
				'callback'            => [ $this, 'getSettings' ],
			],
			[
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => [ $this, 'saveSettings' ],
			],
		] );

		register_rest_route( self::NS, '/models', [
			'methods'             => 'GET',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'models' ],
		] );

		register_rest_route( self::NS, '/reset-usage', [
			'methods'             => 'POST',
			'permission_callback' => $auth,
			'callback'            => [ $this, 'resetUsage' ],
		] );
	}

	/** Echo the proxy's nonce, confirming the Djinn plugin is live on this site (site-binding). */
	public function verify( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( [ 'nonce' => (string) $req->get_param( 'nonce' ) ] );
	}

	/** Ask the proxy for a Stripe SetupIntent client secret + publishable key for this site's account. */
	public function billingIntent(): WP_REST_Response {
		$token = Settings::siteToken();
		if ( $token === '' ) {
			return new WP_REST_Response( [ 'message' => 'Connect a Djinn account first.' ], 400 );
		}
		$res = wp_remote_post(
			Settings::proxyUrl() . '/billing/setup-intent',
			[
				'timeout' => 20,
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'token' => $token ] ),
			]
		);
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( [ 'message' => 'Could not reach billing.' ], 502 );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 || ! is_array( $json ) || empty( $json['clientSecret'] ) ) {
			$msg = is_array( $json ) && isset( $json['error']['message'] )
				? (string) $json['error']['message']
				: 'Billing is not available yet.';
			return new WP_REST_Response( [ 'message' => $msg ], $code ?: 502 );
		}
		return new WP_REST_Response( [
			'clientSecret'   => (string) $json['clientSecret'],
			'publishableKey' => (string) ( $json['publishableKey'] ?? '' ),
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

	/**
	 * Parsed, sanitized chat attachments from the request body: an array of {filename, token, size}.
	 * The token came from /upload; entries without one are dropped.
	 *
	 * @return array<int,array{filename:string,token:string,size:int}>
	 */
	private function attachmentsParam( WP_REST_Request $req ): array {
		$raw = $req->get_param( 'attachments' );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $a ) {
			$token = is_array( $a ) ? sanitize_text_field( (string) ( $a['token'] ?? '' ) ) : '';
			if ( $token === '' ) {
				continue;
			}
			$out[] = [
				'filename' => sanitize_file_name( (string) ( $a['filename'] ?? 'file' ) ),
				'token'    => $token,
				'size'     => isset( $a['size'] ) ? max( 0, (int) $a['size'] ) : 0,
			];
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

	public function wish( WP_REST_Request $req ): WP_REST_Response {
		$text        = trim( (string) $req->get_param( 'message' ) );
		$attachments = $this->attachmentsParam( $req );
		if ( $text === '' && ! $attachments ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Whisper something.' ], 400 );
		}

		$chatId = (int) $req->get_param( 'chat_id' );
		if ( $chatId <= 0 ) {
			$chatId = Repository::createChat( get_current_user_id(), $this->chatTitle( $text, $attachments ) );
		} elseif ( ! $this->ownsChat( $chatId ) ) {
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Not your lamp.' ], 403 );
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
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Whisper something.' ], 400 );
		}
		$chatId = (int) $req->get_param( 'chat_id' );
		if ( $chatId <= 0 ) {
			$chatId = Repository::createChat( get_current_user_id(), $this->chatTitle( $text, $attachments ) );
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
			return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Not your lamp.' ], 403 );
		}

		return new WP_REST_Response( ( new AgentLoop() )->resume( $chatId, $pendingId, $confirmed ) );
	}

	public function chats(): WP_REST_Response {
		return new WP_REST_Response( Repository::listChats( get_current_user_id() ) );
	}

	public function deleteChat( WP_REST_Request $req ): WP_REST_Response {
		$chatId = (int) $req['id'];
		if ( ! $this->ownsChat( $chatId ) ) {
			return new WP_REST_Response( [ 'message' => 'Not your lamp.' ], 403 );
		}
		Repository::deleteChat( $chatId );
		return new WP_REST_Response( [ 'deleted' => $chatId ] );
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
				$content     = (string) ( $entry['content'] ?? '' );
				$attachments = $entry['attachments'] ?? [];
				if ( $content !== '' || $attachments ) {
					$msg = [ 'role' => 'user', 'content' => $content ];
					if ( $attachments ) {
						$msg['attachments'] = $attachments;
					}
					$out[] = $msg;
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
		$out = Repository::usageSummary();
		// Fold in the proxy balance so the Spend tile can show it without a second request.
		$out['account'] = Settings::usesProxy() ? ProxyAccount::fetch() : null;
		return new WP_REST_Response( $out );
	}

	/** Index health for the Capabilities tile + the Lamp's Build/Update popover. */
	public function indexStatus(): WP_REST_Response {
		// Providers without an embeddings API search the full schema — no index.
		$embeds = Providers::hasEmbeddings( Settings::provider() );
		if ( ! Settings::isConfigured() || ! $embeds ) {
			return new WP_REST_Response( [ 'configured' => Settings::isConfigured(), 'embeds' => $embeds ] );
		}
		$s               = IndexStatus::summary();
		$s['configured'] = true;
		$s['embeds']     = true;
		return new WP_REST_Response( $s );
	}

	/** Every supported operation (queries + mutations) by capability domain, + schema types not yet indexed. */
	public function operations(): WP_REST_Response {
		$diff = [ 'added' => [], 'changed' => [] ];
		if ( Settings::isConfigured() ) {
			$summary = IndexStatus::summary();
			$diff    = $summary['diff'];
		}
		return new WP_REST_Response( [
			'operations' => SchemaFactory::operations(),
			'unindexed'  => $diff['added'],   // live but never embedded
			'outdated'   => $diff['changed'], // embedded but the schema changed
		] );
	}

	/** The hosted-proxy account (credit, free wishes, payment status), or its connection state. */
	public function account(): WP_REST_Response {
		if ( ! Settings::usesProxy() ) {
			return new WP_REST_Response( [ 'usesProxy' => false ] );
		}
		$acct = ProxyAccount::fetch();
		if ( $acct === null ) {
			return new WP_REST_Response( [ 'usesProxy' => true, 'connected' => $this->siteHasToken() ] );
		}
		$acct['usesProxy'] = true;
		$acct['connected'] = true;
		return new WP_REST_Response( $acct );
	}

	private function siteHasToken(): bool {
		return Settings::siteToken() !== '';
	}

	/** Non-secret settings for the Account form. Secrets are surfaced only as "saved" booleans. */
	public function getSettings(): WP_REST_Response {
		$s = Settings::all();
		return new WP_REST_Response( [
			'edition'         => Settings::edition(),
			'isOrg'           => Settings::isOrg(),
			'provider'        => Settings::provider(),
			'chat_model'      => $s['chat_model'],
			'embedding_model' => $s['embedding_model'],
			'hasApiKey'       => Settings::apiKey() !== '',
			'hasSiteToken'    => Settings::siteToken() !== '',
			'usesProxy'       => Settings::usesProxy(),
			'configured'      => Settings::isConfigured(),
		] );
	}

	/** Save settings via the same sanitizer the options.php form uses (blank secret = keep existing). */
	public function saveSettings( WP_REST_Request $req ): WP_REST_Response {
		if ( Settings::isOrg() ) {
			return new WP_REST_Response( [ 'message' => 'This edition is managed — settings are fixed.' ], 403 );
		}
		Settings::update( (array) $req->get_json_params() );
		return $this->getSettings();
	}

	/** Available chat/embedding models for a provider (tiered), discovered live from the key. */
	public function models( WP_REST_Request $req ): WP_REST_Response {
		$provider = (string) $req->get_param( 'provider' );
		if ( $provider === '' ) {
			$provider = Settings::provider();
		}
		if ( $req->get_param( 'refresh' ) ) {
			ModelCatalog::flush();
		}
		$catalog = ModelCatalog::forProvider( $provider, Settings::apiKey() );
		return new WP_REST_Response( [
			'chat'  => array_map( static fn( $m ) => [ 'id' => $m, 'tier' => ModelCatalog::chatTier( $m ), 'price' => Pricing::describe( $m ) ], $catalog['chat'] ),
			'embed' => array_map( static fn( $m ) => [ 'id' => $m, 'price' => Pricing::describe( $m ) ], $catalog['embed'] ),
			'live'  => $catalog['live'],
			'error' => $catalog['error'],
		] );
	}

	public function resetUsage(): WP_REST_Response {
		Repository::clearUsage();
		return new WP_REST_Response( [ 'status' => 'ok' ] );
	}

	private function ownsChat( int $chatId ): bool {
		return Repository::chatOwner( $chatId ) === get_current_user_id();
	}
}
