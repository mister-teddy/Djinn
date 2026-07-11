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
			array(
				'name'        => 'Theme',
				'description' => 'An installed theme.',
				'fields'      => array(
					'slug'    => array(
						'type'        => Type::id(),
						'description' => 'The stylesheet directory (use this to activate).',
					),
					'name'    => array( 'type' => Type::string() ),
					'version' => array( 'type' => Type::string() ),
					'active'  => array(
						'type'        => Type::boolean(),
						'description' => 'Whether this is the active theme.',
					),
					'parent'  => array(
						'type'        => Type::string(),
						'description' => 'Parent theme slug, if a child theme.',
					),
				),
			)
		);

		$r->addQuery(
			'themes',
			array(
				'type'        => Type::listOf( $theme ),
				'description' => 'List installed themes.',
				'resolve'     => array( $this, 'themes' ),
			)
		);
		$r->addQuery(
			'additionalCss',
			array(
				'type'        => Type::string(),
				'description' => "The active theme's Additional CSS (the Customizer's extra CSS).",
				'resolve'     => array( $this, 'additionalCss' ),
			)
		);

		$r->addMutation(
			'activateTheme',
			array(
				'type'        => Type::boolean(),
				'description' => 'Switch the active theme by slug.',
				'args'        => array( 'slug' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve'     => array( $this, 'activateTheme' ),
			)
		);
		$r->addMutation(
			'setAdditionalCss',
			array(
				'type'        => Type::boolean(),
				'description' => "Replaces ALL of the active theme's Additional CSS — first read it with the additionalCss query and include any existing CSS you want to keep.",
				'args'        => array( 'css' => array( 'type' => Type::nonNull( Type::string() ) ) ),
				'resolve'     => array( $this, 'setAdditionalCss' ),
			)
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function themes(): array {
		if ( ! current_user_can( 'switch_themes' ) && ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( esc_html( 'You do not have permission to view themes.' ) );
		}
		$active = get_stylesheet();
		$out    = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$parent = $theme->parent();
			$out[]  = array(
				'slug'    => (string) $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => $slug === $active,
				'parent'  => $parent ? $parent->get_stylesheet() : null,
			);
		}
		return $out;
	}

	public function additionalCss(): ?string {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( esc_html( 'You do not have permission to read theme options.' ) );
		}
		return wp_get_custom_css();
	}

	/** @param array<string,mixed> $args */
	public function activateTheme( $root, array $args ): bool {
		if ( ! current_user_can( 'switch_themes' ) ) {
			throw new UserError( esc_html( 'You do not have permission to switch themes.' ) );
		}
		$slug  = (string) $args['slug'];
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			throw new UserError( esc_html( "No theme with slug '$slug' is installed." ) );
		}
		if ( ! $theme->is_allowed() ) {
			throw new UserError( esc_html( "Theme '$slug' is not allowed on this site." ) );
		}
		switch_theme( $slug );
		return get_stylesheet() === $slug;
	}

	/** @param array<string,mixed> $args */
	public function setAdditionalCss( $root, array $args ): bool {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( esc_html( 'You do not have permission to edit theme options.' ) );
		}
		$result = wp_update_custom_css_post( (string) $args['css'] );
		if ( is_wp_error( $result ) ) {
			throw new UserError( esc_html( $result->get_error_message() ) );
		}
		return true;
	}
}
