<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Appearance: list & switch themes, and read/write the site's "Additional CSS" — the supported
 * way to change the site's styles without editing theme files.
 */
class AppearanceFeature implements Feature {

	public function register( Registry $r ): void {
		$theme = new ObjectType(
			[
				'name'        => 'Theme',
				'description' => 'An installed theme.',
				'fields'      => [
					'slug'    => [ 'type' => Type::id(), 'description' => 'The stylesheet directory (use this to activate).' ],
					'name'    => [ 'type' => Type::string() ],
					'version' => [ 'type' => Type::string() ],
					'active'  => [ 'type' => Type::boolean(), 'description' => 'Whether this is the active theme.' ],
					'parent'  => [ 'type' => Type::string(), 'description' => 'Parent theme slug, if a child theme.' ],
				],
			]
		);

		$r->addQuery( 'themes', [
			'type'        => Type::listOf( $theme ),
			'description' => 'List installed themes.',
			'resolve'     => [ $this, 'themes' ],
		] );
		$r->addQuery( 'additionalCss', [
			'type'        => Type::string(),
			'description' => "The active theme's Additional CSS (the Customizer's extra CSS).",
			'resolve'     => [ $this, 'additionalCss' ],
		] );

		$r->addMutation( 'activateTheme', [
			'type'        => Type::boolean(),
			'description' => 'Switch the active theme by slug.',
			'args'        => [ 'slug' => [ 'type' => Type::nonNull( Type::id() ) ] ],
			'resolve'     => [ $this, 'activateTheme' ],
		] );
		$r->addMutation( 'setAdditionalCss', [
			'type'        => Type::boolean(),
			'description' => "Replace the active theme's Additional CSS. Pass the full CSS to apply.",
			'args'        => [ 'css' => [ 'type' => Type::nonNull( Type::string() ) ] ],
			'resolve'     => [ $this, 'setAdditionalCss' ],
		] );
	}

	/** @return array<int,array<string,mixed>> */
	public function themes(): array {
		if ( ! current_user_can( 'switch_themes' ) && ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to view themes.' );
		}
		$active = get_stylesheet();
		$out    = [];
		foreach ( wp_get_themes() as $slug => $theme ) {
			$parent = $theme->parent();
			$out[]  = [
				'slug'    => (string) $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => $slug === $active,
				'parent'  => $parent ? $parent->get_stylesheet() : null,
			];
		}
		return $out;
	}

	public function additionalCss(): ?string {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to read theme options.' );
		}
		return wp_get_custom_css();
	}

	/** @param array<string,mixed> $args */
	public function activateTheme( $root, array $args ): bool {
		if ( ! current_user_can( 'switch_themes' ) ) {
			throw new UserError( 'You do not have permission to switch themes.' );
		}
		$slug  = (string) $args['slug'];
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			throw new UserError( "No theme with slug '$slug' is installed." );
		}
		if ( ! $theme->is_allowed() ) {
			throw new UserError( "Theme '$slug' is not allowed on this site." );
		}
		switch_theme( $slug );
		return get_stylesheet() === $slug;
	}

	/** @param array<string,mixed> $args */
	public function setAdditionalCss( $root, array $args ): bool {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to edit theme options.' );
		}
		$result = wp_update_custom_css_post( (string) $args['css'] );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return true;
	}
}
