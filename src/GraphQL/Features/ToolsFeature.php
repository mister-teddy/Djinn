<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\Files\Downloads;
use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Tools: a site-health/system summary, a content export (WXR), and a full database dump. Exports
 * write a file and return a download token (returning multi-MB data inline would be useless in
 * chat); the file is served only by the gated /download endpoint. Gated on manage_options.
 */
class ToolsFeature implements Feature {

	public function register( Registry $r ): void {
		$download = new ObjectType(
			array(
				'name'        => 'DownloadFile',
				'description' => 'A generated file. Download it via GET djinn/v1/download?token=…&_wpnonce=… (the UI builds this link).',
				'fields'      => array(
					'token'    => array(
						'type'        => Type::id(),
						'description' => 'Short-lived download token.',
					),
					'filename' => array( 'type' => Type::string() ),
					'bytes'    => array( 'type' => Type::int() ),
				),
			)
		);
		$r->setType( 'DownloadFile', $download );
		$health = new ObjectType(
			array(
				'name'        => 'SiteHealth',
				'description' => 'A snapshot of the site\'s environment and pending maintenance.',
				'fields'      => array(
					'phpVersion'     => array( 'type' => Type::string() ),
					'wpVersion'      => array( 'type' => Type::string() ),
					'dbVersion'      => array( 'type' => Type::string() ),
					'httpsEnabled'   => array( 'type' => Type::boolean() ),
					'debugMode'      => array( 'type' => Type::boolean() ),
					'memoryLimit'    => array( 'type' => Type::string() ),
					'maxUploadSize'  => array( 'type' => Type::string() ),
					'activeTheme'    => array( 'type' => Type::string() ),
					'isBlockTheme'   => array( 'type' => Type::boolean() ),
					'pendingUpdates' => array(
						'type'        => Type::int(),
						'description' => 'Plugins + themes with available updates (from cached data).',
					),
					'multisite'      => array( 'type' => Type::boolean() ),
				),
			)
		);
		$r->setType( 'SiteHealth', $health );

		$r->addQuery(
			'siteHealth',
			array(
				'type'        => $health,
				'description' => 'Site environment and maintenance snapshot (PHP/WP/DB versions, HTTPS, debug, pending updates).',
				'resolve'     => array( $this, 'siteHealth' ),
			)
		);
		$r->addQuery(
			'exportContent',
			array(
				'type'        => $download,
				'description' => 'Generate a WordPress content export (WXR) file and return a download token. Optionally limit to one post type.',
				'args'        => array(
					'postType' => array(
						'type'        => Type::string(),
						'description' => 'A post type, or "all" (default).',
					),
				),
				'resolve'     => array( $this, 'exportContent' ),
			)
		);
		$r->addQuery(
			'exportDatabase',
			array(
				'type'        => $download,
				'description' => 'Dump the full WordPress database to a .sql file and return a download token (for backup). Download-only — there is no restore.',
				'resolve'     => array( $this, 'exportDatabase' ),
			)
		);
		$r->addMutation(
			'importWxr',
			array(
				'type'        => Type::string(),
				'description' => 'Import a WordPress content (WXR/XML) export the user attached in chat — posts, pages, custom types with their categories/tags. Pass its upload token. (Attachments are skipped.)',
				'args'        => array(
					'token' => array(
						'type'        => Type::nonNull( Type::id() ),
						'description' => 'The upload token from an attached file.',
					),
				),
				'resolve'     => array( $this, 'importWxr' ),
			)
		);
	}

	private function gate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new UserError( 'You do not have permission to use site tools.' );
		}
	}

	/** @return array<string,mixed> */
	public function siteHealth(): array {
		$this->gate();
		global $wpdb;

		$pluginUpdates = get_site_transient( 'update_plugins' );
		$themeUpdates  = get_site_transient( 'update_themes' );
		$pending       = ( isset( $pluginUpdates->response ) ? count( (array) $pluginUpdates->response ) : 0 )
			+ ( isset( $themeUpdates->response ) ? count( (array) $themeUpdates->response ) : 0 );

		return array(
			'phpVersion'     => PHP_VERSION,
			'wpVersion'      => get_bloginfo( 'version' ),
			'dbVersion'      => $wpdb->db_version(),
			'httpsEnabled'   => function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : ( strpos( (string) home_url(), 'https://' ) === 0 ),
			'debugMode'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'memoryLimit'    => (string) WP_MEMORY_LIMIT,
			'maxUploadSize'  => size_format( wp_max_upload_size() ),
			'activeTheme'    => wp_get_theme()->get( 'Name' ),
			'isBlockTheme'   => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'pendingUpdates' => $pending,
			'multisite'      => is_multisite(),
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array{token:string,filename:string,bytes:int}
	 */
	public function exportContent( $root, array $args ): array {
		$this->gate();
		require_once ABSPATH . 'wp-admin/includes/export.php';

		$content = isset( $args['postType'] ) && $args['postType'] !== '' ? (string) $args['postType'] : 'all';

		ob_start();
		export_wp( array( 'content' => $content ) );
		$xml = (string) ob_get_clean();
		if ( $xml === '' ) {
			throw new UserError( 'The export produced no data.' );
		}

		$path = $this->file( 'djinn-content-' . gmdate( 'Ymd-His' ) . '.xml' );
		if ( file_put_contents( $path, $xml ) === false ) {
			throw new UserError( 'Could not write the export file.' );
		}
		return $this->result( $path, 'text/xml' );
	}

	/**
	 * Full database dump, streamed to disk in batches so memory stays flat on large sites.
	 * Download-only (per design) — there is no restore.
	 *
	 * @return array{token:string,filename:string,bytes:int}
	 */
	public function exportDatabase(): array {
		$this->gate();
		global $wpdb;

		$path = $this->file( 'djinn-db-' . gmdate( 'Ymd-His' ) . '.sql' );
		$fh   = fopen( $path, 'w' );
		if ( ! $fh ) {
			throw new UserError( 'Could not open the dump file for writing.' );
		}
		fwrite( $fh, '-- Djinn database export — ' . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" );

		foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
			fwrite( $fh, "\n-- Table $table\nDROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n" );

			$offset = 0;
			$batch  = 500;
			do {
				$rows = $wpdb->get_results( "SELECT * FROM `$table` LIMIT $batch OFFSET $offset", ARRAY_A );
				foreach ( $rows as $row ) {
					$cols = implode( ',', array_map( static fn( $c ) => "`$c`", array_keys( $row ) ) );
					$vals = implode(
						',',
						array_map(
							static fn( $v ) => $v === null ? 'NULL' : $wpdb->prepare( '%s', $v ),
							array_values( $row )
						)
					);
					fwrite( $fh, "INSERT INTO `$table` ($cols) VALUES ($vals);\n" );
				}
				$offset += $batch;
			} while ( count( $rows ) === $batch );
		}
		fwrite( $fh, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $fh );

		return $this->result( $path, 'application/sql' );
	}

	/**
	 * Import an uploaded WXR (WordPress eXtended RSS) file. Self-contained parser (SimpleXML →
	 * wp_insert_post) so it needs no external importer plugin; covers posts, pages, and custom post
	 * types with their categories/tags. Attachments are skipped (binaries can't be fetched here).
	 *
	 * @param array<string,mixed> $args
	 */
	public function importWxr( $root, array $args ): string {
		$this->gate();
		if ( ! current_user_can( 'import' ) ) {
			throw new UserError( 'You do not have permission to import content.' );
		}
		$file = Downloads::resolve( (string) $args['token'] );
		if ( ! $file ) {
			throw new UserError( 'That uploaded file has expired or is gone — attach it again.' );
		}

		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_file( $file['path'] );
		libxml_use_internal_errors( $prev );
		if ( ! $xml || ! isset( $xml->channel ) ) {
			throw new UserError( 'That does not look like a valid WordPress export (WXR) file.' );
		}

		$created = 0;
		$skipped = 0;
		foreach ( $xml->channel->item as $item ) {
			$wp   = $item->children( 'wp', true );
			$type = (string) $wp->post_type ?: 'post';
			if ( $type === 'attachment' ) {
				++$skipped;
				continue;
			}
			$status  = (string) $wp->status;
			$date    = (string) $wp->post_date;
			$content = $item->children( 'content', true )->encoded;
			$excerpt = $item->children( 'excerpt', true )->encoded;

			$id = wp_insert_post(
				array(
					'post_title'   => (string) $item->title,
					'post_content' => (string) $content,
					'post_excerpt' => (string) $excerpt,
					'post_name'    => (string) $wp->post_name,
					'post_status'  => in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $status : 'draft',
					'post_type'    => post_type_exists( $type ) ? $type : 'post',
					'post_date'    => ( $date && $date !== '0000-00-00 00:00:00' ) ? $date : '',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				++$skipped;
				continue;
			}

			$cats = array();
			$tags = array();
			foreach ( $item->category as $c ) {
				$name = trim( (string) $c );
				if ( $name === '' ) {
					continue;
				}
				if ( (string) $c['domain'] === 'post_tag' ) {
					$tags[] = $name;
				} else {
					$cats[] = $name;
				}
			}
			if ( $cats ) {
				wp_set_object_terms( $id, $cats, 'category' );
			}
			if ( $tags ) {
				wp_set_object_terms( $id, $tags, 'post_tag' );
			}
			++$created;
		}

		return "Imported {$created} item(s)" . ( $skipped ? ", skipped {$skipped} (attachments or invalid)" : '' ) . '.';
	}

	/** Allocate a unique path in the private downloads dir. */
	private function file( string $filename ): string {
		$dir = Downloads::dir();
		return trailingslashit( $dir ) . wp_unique_filename( $dir, $filename );
	}

	/**
	 * @return array{token:string,filename:string,bytes:int}
	 */
	private function result( string $path, string $mime ): array {
		$filename = basename( $path );
		return array(
			'token'    => Downloads::register( $path, $filename, $mime ),
			'filename' => $filename,
			'bytes'    => (int) filesize( $path ),
		);
	}
}
