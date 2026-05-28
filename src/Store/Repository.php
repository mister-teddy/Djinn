<?php

declare( strict_types=1 );

namespace Djinn\Store;

/**
 * All persistence for Djinn, backed by custom wpdb tables:
 *   - chats         : one row per conversation
 *   - messages      : the normalized turn history (replayed to the LLM)
 *   - pending       : wishes (mutations) awaiting human confirmation
 *   - schema_chunks : RAG index (SDL fragment + embedding) of the GraphQL schema
 *   - usage         : one row per provider call (tokens + estimated cost)
 */
class Repository {

	/** Bumped whenever the table layout changes; drives maybeUpgrade(). */
	private const DB_VERSION = 2;

	private const DB_VERSION_OPTION = 'djinn_db_version';

	/**
	 * Run install() once per schema version, on a normal request — so new tables appear after a
	 * plugin update without forcing a manual deactivate/reactivate. dbDelta is idempotent.
	 */
	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$chats   = $wpdb->prefix . 'djinn_chats';
		$msgs    = $wpdb->prefix . 'djinn_messages';
		$pending = $wpdb->prefix . 'djinn_pending';
		$chunks  = $wpdb->prefix . 'djinn_schema_chunks';
		$usage   = $wpdb->prefix . 'djinn_usage';

		dbDelta(
			"CREATE TABLE $chats (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				title VARCHAR(200) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $msgs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				chat_id BIGINT UNSIGNED NOT NULL,
				role VARCHAR(20) NOT NULL,
				content LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY chat_id (chat_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $pending (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				chat_id BIGINT UNSIGNED NOT NULL,
				tool_call_id VARCHAR(128) NOT NULL DEFAULT '',
				operation LONGTEXT NOT NULL,
				variables LONGTEXT NOT NULL,
				summary TEXT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY chat_id (chat_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $chunks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL,
				fragment LONGTEXT NOT NULL,
				embedding LONGTEXT NOT NULL,
				model VARCHAR(100) NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY name (name)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE $usage (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				provider VARCHAR(20) NOT NULL DEFAULT '',
				model VARCHAR(100) NOT NULL DEFAULT '',
				kind VARCHAR(20) NOT NULL DEFAULT '',
				prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				estimated TINYINT(1) NOT NULL DEFAULT 0,
				cost DECIMAL(14,8) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY created_at (created_at),
				KEY model (model)
			) $charset;"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	// ---- Chats -------------------------------------------------------------

	public static function createChat( int $userId, string $title ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'djinn_chats',
			[
				'user_id'    => $userId,
				'title'      => mb_substr( $title, 0, 200 ),
				'created_at' => current_time( 'mysql', true ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listChats( int $userId ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_chats';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, title, created_at FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT 50", $userId ),
			ARRAY_A
		);
		return $rows ?: [];
	}

	public static function chatOwner( int $chatId ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_chats';
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $table WHERE id = %d", $chatId ) );
		return $id === null ? null : (int) $id;
	}

	// ---- Messages ----------------------------------------------------------

	/** @param array<string,mixed> $entry A normalized message: role + content + optional tool fields. */
	public static function addMessage( int $chatId, array $entry ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'djinn_messages',
			[
				'chat_id'    => $chatId,
				'role'       => (string) ( $entry['role'] ?? 'assistant' ),
				'content'    => wp_json_encode( $entry ),
				'created_at' => current_time( 'mysql', true ),
			]
		);
	}

	/** @return array<int,array<string,mixed>> Normalized message entries, oldest first. */
	public static function getMessages( int $chatId ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_messages';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT content FROM $table WHERE chat_id = %d ORDER BY id ASC", $chatId ),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => json_decode( $r['content'], true ) ?: [],
			$rows ?: []
		);
	}

	// ---- Pending wishes ----------------------------------------------------

	public static function createPending( int $chatId, string $toolCallId, string $operation, array $variables, string $summary ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'djinn_pending',
			[
				'chat_id'      => $chatId,
				'tool_call_id' => $toolCallId,
				'operation'    => $operation,
				'variables'    => wp_json_encode( $variables ),
				'summary'      => $summary,
				'status'       => 'pending',
				'created_at'   => current_time( 'mysql', true ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null */
	public static function getPending( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_pending';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['variables'] = json_decode( $row['variables'], true ) ?: [];
		return $row;
	}

	public static function setPendingStatus( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'djinn_pending', [ 'status' => $status ], [ 'id' => $id ] );
	}

	// ---- Schema chunks (RAG index) ----------------------------------------

	/** @param array<int,array{name:string,fragment:string,embedding:array<int,float>}> $chunks */
	public static function replaceChunks( array $chunks, string $model ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_schema_chunks';
		$wpdb->query( "TRUNCATE TABLE $table" );
		$now = current_time( 'mysql', true );
		foreach ( $chunks as $chunk ) {
			$wpdb->insert(
				$table,
				[
					'name'       => mb_substr( $chunk['name'], 0, 190 ),
					'fragment'   => $chunk['fragment'],
					'embedding'  => wp_json_encode( $chunk['embedding'] ),
					'model'      => $model,
					'updated_at' => $now,
				]
			);
		}
	}

	/** @return array<int,array{name:string,fragment:string,embedding:array<int,float>}> */
	public static function getChunks(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_schema_chunks';
		$rows  = $wpdb->get_results( "SELECT name, fragment, embedding FROM $table", ARRAY_A );
		return array_map(
			static fn( $r ) => [
				'name'      => $r['name'],
				'fragment'  => $r['fragment'],
				'embedding' => json_decode( $r['embedding'], true ) ?: [],
			],
			$rows ?: []
		);
	}

	public static function chunkCount(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_schema_chunks';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	// ---- Usage (token + cost telemetry) -----------------------------------

	/**
	 * @param array{user_id:int,provider:string,model:string,kind:string,prompt_tokens:int,
	 *              completion_tokens:int,estimated:int,cost:float} $row
	 */
	public static function recordUsage( array $row ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'djinn_usage',
			[
				'user_id'           => (int) $row['user_id'],
				'provider'          => (string) $row['provider'],
				'model'             => (string) $row['model'],
				'kind'              => (string) $row['kind'],
				'prompt_tokens'     => (int) $row['prompt_tokens'],
				'completion_tokens' => (int) $row['completion_tokens'],
				'estimated'         => (int) $row['estimated'],
				'cost'              => (float) $row['cost'],
				'created_at'        => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s' ]
		);
	}

	/**
	 * Aggregates for the dashboard: grand totals, a per-model breakdown, a daily series for the
	 * last $days days, and the most recent calls.
	 *
	 * @return array<string,mixed>
	 */
	public static function usageSummary( int $days = 14, int $recent = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_usage';

		$totals = $wpdb->get_row(
			"SELECT COUNT(*) AS calls,
			        COALESCE(SUM(prompt_tokens),0) AS prompt,
			        COALESCE(SUM(completion_tokens),0) AS completion,
			        COALESCE(SUM(cost),0) AS cost,
			        COALESCE(MAX(estimated),0) AS has_estimates
			   FROM $table",
			ARRAY_A
		) ?: [];

		$byModel = $wpdb->get_results(
			"SELECT provider, model, kind,
			        COUNT(*) AS calls,
			        SUM(prompt_tokens) AS prompt,
			        SUM(completion_tokens) AS completion,
			        SUM(cost) AS cost,
			        MAX(estimated) AS estimated
			   FROM $table
			  GROUP BY provider, model, kind
			  ORDER BY cost DESC, calls DESC",
			ARRAY_A
		) ?: [];

		$byDay = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day,
				        COUNT(*) AS calls,
				        SUM(cost) AS cost
				   FROM $table
				  WHERE created_at >= DATE_SUB(UTC_DATE(), INTERVAL %d DAY)
				  GROUP BY DATE(created_at)
				  ORDER BY day ASC",
				$days - 1
			),
			ARRAY_A
		) ?: [];

		$recentRows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT created_at, provider, model, kind, prompt_tokens, completion_tokens, estimated, cost
				   FROM $table
				  ORDER BY id DESC
				  LIMIT %d",
				$recent
			),
			ARRAY_A
		) ?: [];

		return [
			'totals' => [
				'calls'         => (int) ( $totals['calls'] ?? 0 ),
				'prompt'        => (int) ( $totals['prompt'] ?? 0 ),
				'completion'    => (int) ( $totals['completion'] ?? 0 ),
				'cost'          => (float) ( $totals['cost'] ?? 0 ),
				'has_estimates' => (bool) ( $totals['has_estimates'] ?? 0 ),
			],
			'by_model' => $byModel,
			'by_day'   => $byDay,
			'recent'   => $recentRows,
		];
	}

	public static function clearUsage(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'djinn_usage';
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
}
