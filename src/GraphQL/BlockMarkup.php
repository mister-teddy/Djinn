<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

/**
 * Read block markup the way the front end assembles it. Theme patterns and template parts are
 * stored as bare references (`wp:pattern`, `wp:template-part`) that only resolve at render time —
 * patterns from PHP, parts from their own posts — so to search or edit what a visitor actually
 * sees we expand those references inline. Each inlined template part is wrapped in a `djinn:region`
 * marker carrying its editable id, so a reader can tell which source any block belongs to.
 */
class BlockMarkup {

	private const MAX_DEPTH = 8;

	/** Replace every self-closing `wp:pattern` reference with the registered pattern's markup, recursively. */
	public static function expandPatterns( string $content, int $depth = 0 ): string {
		if ( $depth >= self::MAX_DEPTH || ! class_exists( '\WP_Block_Patterns_Registry' ) || strpos( $content, 'wp:pattern' ) === false ) {
			return $content;
		}
		$registry = \WP_Block_Patterns_Registry::get_instance();
		return (string) preg_replace_callback(
			'#<!--\s*wp:pattern\s*(\{.*?\})\s*/-->#s',
			static function ( array $m ) use ( $registry, $depth ): string {
				$attrs = json_decode( $m[1], true );
				$slug  = is_array( $attrs ) ? (string) ( $attrs['slug'] ?? '' ) : '';
				$pat   = $slug !== '' ? $registry->get_registered( $slug ) : null;
				if ( ! $pat || empty( $pat['content'] ) ) {
					return $m[0];
				}
				return self::expandPatterns( (string) $pat['content'], $depth + 1 );
			},
			$content
		);
	}

	/**
	 * Expand patterns and template parts. Patterns inline silently (they belong to whatever source
	 * contains them); each template part is wrapped in a `djinn:region` marker, since it is a
	 * separately editable source.
	 */
	public static function expand( string $content, int $depth = 0 ): string {
		if ( $depth >= self::MAX_DEPTH ) {
			return $content;
		}
		$content = self::expandPatterns( $content, $depth );
		if ( strpos( $content, 'wp:template-part' ) === false ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'#<!--\s*wp:template-part\s*(\{.*?\})\s*/-->#s',
			static function ( array $m ) use ( $depth ): string {
				$attrs = json_decode( $m[1], true );
				$slug  = is_array( $attrs ) ? (string) ( $attrs['slug'] ?? '' ) : '';
				$part  = $slug !== '' ? self::templatePart( $slug ) : null;
				if ( ! $part ) {
					return $m[0];
				}
				$title = is_string( $part->title ) && $part->title !== '' ? $part->title : ucfirst( $slug );
				return self::region( 'template_part', (string) $part->id, $title, self::expand( (string) $part->content, $depth + 1 ) );
			},
			$content
		);
	}

	/** Wrap markup in begin/end provenance markers naming its editable source. */
	public static function region( string $kind, string $id, string $title, string $inner ): string {
		$safe = static fn( string $s ): string => str_replace( [ '"', '-->' ], [ "'", '--&gt;' ], $s );
		return sprintf(
			"<!-- djinn:region source=\"%s\" id=\"%s\" title=\"%s\" -->\n%s\n<!-- /djinn:region -->",
			$safe( $kind ),
			$safe( $id ),
			$safe( $title ),
			$inner
		);
	}

	/** The editable sources named by region markers in a resolved tree, de-duplicated, in order. */
	public static function sources( string $markup ): array {
		preg_match_all( '#<!-- djinn:region source="([^"]+)" id="([^"]+)" title="([^"]*)" -->#', $markup, $matches, PREG_SET_ORDER );
		$seen = [];
		$out  = [];
		foreach ( $matches as $m ) {
			if ( isset( $seen[ $m[2] ] ) ) {
				continue;
			}
			$seen[ $m[2] ] = true;
			$out[]         = [ 'kind' => $m[1], 'id' => $m[2], 'title' => $m[3] ];
		}
		return $out;
	}

	private static function templatePart( string $slug ): ?\WP_Block_Template {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return null;
		}
		$parts = get_block_templates( [ 'slug__in' => [ $slug ] ], 'wp_template_part' );
		return $parts[0] ?? null;
	}
}
