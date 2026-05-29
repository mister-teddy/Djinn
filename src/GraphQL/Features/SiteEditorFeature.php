<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Block-theme Site Editor (FSE): list/read templates and template parts, read the generated global
 * styles CSS, and replace a template's block markup. Reads use the block-template API; writes go
 * through core's own REST template controller via rest_do_request — reusing its permission and
 * storage logic rather than hand-rolling the wp_template post + theme-term plumbing. Gated on
 * edit_theme_options; on classic (non-block) themes the template lists are simply empty.
 */
class SiteEditorFeature implements Feature {

	public function register( Registry $r ): void {
		$template = new ObjectType(
			[
				'name'        => 'SiteTemplate',
				'description' => 'A block-theme template or template part.',
				'fields'      => [
					'id'          => [ 'type' => Type::id(), 'description' => 'e.g. "twentytwentyfour//home" — pass to update.' ],
					'slug'        => [ 'type' => Type::string() ],
					'title'       => [ 'type' => Type::string() ],
					'description' => [ 'type' => Type::string() ],
					'type'        => [ 'type' => Type::string(), 'description' => 'wp_template or wp_template_part.' ],
					'source'      => [ 'type' => Type::string(), 'description' => 'theme (from files) or custom (edited).' ],
					'content'     => [ 'type' => Type::string(), 'description' => 'Block markup.' ],
				],
			]
		);
		$r->setType( 'SiteTemplate', $template );

		$r->addQuery( 'siteTemplates', [
			'type'        => Type::listOf( $template ),
			'description' => 'List block-theme templates (home, single, archive, 404, …).',
			'resolve'     => fn() => $this->templates( 'wp_template' ),
		] );
		$r->addQuery( 'siteTemplateParts', [
			'type'        => Type::listOf( $template ),
			'description' => 'List block-theme template parts (header, footer, sidebar, …).',
			'resolve'     => fn() => $this->templates( 'wp_template_part' ),
		] );
		$r->addQuery( 'globalStylesCss', [
			'type'        => Type::string(),
			'description' => "The active block theme's generated global styles CSS (theme.json output).",
			'resolve'     => [ $this, 'globalStylesCss' ],
		] );

		$r->addMutation( 'updateSiteTemplate', [
			'type'        => Type::boolean(),
			'description' => 'Replace a template\'s block markup. Pass the id from siteTemplates and the full new content.',
			'args'        => [
				'id'      => [ 'type' => Type::nonNull( Type::id() ) ],
				'content' => [ 'type' => Type::nonNull( Type::string() ) ],
			],
			'resolve'     => fn( $root, array $args ) => $this->updateTemplate( 'wp_template', (string) $args['id'], (string) $args['content'] ),
		] );
		$r->addMutation( 'updateSiteTemplatePart', [
			'type'        => Type::boolean(),
			'description' => 'Replace a template part\'s block markup (e.g. the header or footer).',
			'args'        => [
				'id'      => [ 'type' => Type::nonNull( Type::id() ) ],
				'content' => [ 'type' => Type::nonNull( Type::string() ) ],
			],
			'resolve'     => fn( $root, array $args ) => $this->updateTemplate( 'wp_template_part', (string) $args['id'], (string) $args['content'] ),
		] );
	}

	private function gate(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to edit templates.' );
		}
	}

	/** @return array<int,array<string,mixed>> */
	private function templates( string $type ): array {
		$this->gate();
		if ( ! function_exists( 'get_block_templates' ) ) {
			return [];
		}
		$out = [];
		foreach ( get_block_templates( [], $type ) as $t ) {
			$out[] = [
				'id'          => $t->id,
				'slug'        => $t->slug,
				'title'       => is_string( $t->title ) ? $t->title : ( $t->slug ),
				'description' => $t->description,
				'type'        => $t->type,
				'source'      => $t->source,
				'content'     => $t->content,
			];
		}
		return $out;
	}

	public function globalStylesCss(): ?string {
		$this->gate();
		return function_exists( 'wp_get_global_stylesheet' ) ? wp_get_global_stylesheet() : null;
	}

	private function updateTemplate( string $type, string $id, string $content ): bool {
		$this->gate();
		$endpoint = $type === 'wp_template_part' ? '/wp/v2/template-parts/' : '/wp/v2/templates/';
		$request  = new \WP_REST_Request( 'POST', $endpoint . $id );
		$request->set_body_params( [ 'content' => $content ] );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			throw new UserError( $response->as_error()->get_error_message() );
		}
		return true;
	}
}
