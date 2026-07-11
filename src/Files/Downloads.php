<?php

declare( strict_types=1 );

namespace Djinn\Files;

/**
 * Brokers generated files and uploads for download. Files are written to a private folder
 * under uploads (web-server access denied) and handed out only via a short-lived token resolved by
 * the gated REST /download endpoint, so sensitive files are never sitting at a public URL.
 */
class Downloads {

	private const TTL    = 3600; // tokens live one hour
	private const PREFIX = 'djinn_dl_';

	/** Private storage dir under uploads, created with access-deny guards on first use. */
	public static function dir(): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'djinn-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/** Register a written file; returns a token for the /download endpoint. */
	public static function register( string $path, string $filename, string $mime = 'application/octet-stream' ): string {
		$token = wp_generate_password( 40, false );
		set_transient(
			self::PREFIX . $token,
			array(
				'path'     => $path,
				'filename' => $filename,
				'mime'     => $mime,
			),
			self::TTL
		);
		return $token;
	}

	/** @return array{path:string,filename:string,mime:string}|null */
	public static function resolve( string $token ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $token ) ) {
			return null;
		}
		$data = get_transient( self::PREFIX . $token );
		if ( ! is_array( $data ) || empty( $data['path'] ) || ! is_file( $data['path'] ) ) {
			return null;
		}
		return $data;
	}
}
