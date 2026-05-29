<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Site identity (Customizer): the site logo and icon (favicon), plus title/tagline for context.
 * Logo is a theme mod; the icon is a site option. Gated on edit_theme_options.
 */
class CustomizerFeature implements Feature {

	public function register( Registry $r ): void {
		$identity = new ObjectType(
			[
				'name'        => 'SiteIdentity',
				'description' => 'The site logo, icon, title, and tagline.',
				'fields'      => [
					'title'       => [ 'type' => Type::string() ],
					'tagline'     => [ 'type' => Type::string() ],
					'logoMediaId' => [ 'type' => Type::id() ],
					'logoUrl'     => [ 'type' => Type::string() ],
					'iconMediaId' => [ 'type' => Type::id() ],
					'iconUrl'     => [ 'type' => Type::string() ],
				],
			]
		);
		$r->setType( 'SiteIdentity', $identity );

		$r->addQuery( 'siteIdentity', [
			'type'        => $identity,
			'description' => 'The site logo, icon, title, and tagline.',
			'resolve'     => [ $this, 'siteIdentity' ],
		] );

		$r->addMutation( 'setSiteLogo', [
			'type'        => Type::boolean(),
			'description' => 'Set the site logo to a media item.',
			'args'        => [ 'mediaId' => [ 'type' => Type::nonNull( Type::id() ) ] ],
			'resolve'     => [ $this, 'setSiteLogo' ],
		] );
		$r->addMutation( 'setSiteIcon', [
			'type'        => Type::boolean(),
			'description' => 'Set the site icon (favicon) to a media item — ideally a square image at least 512×512.',
			'args'        => [ 'mediaId' => [ 'type' => Type::nonNull( Type::id() ) ] ],
			'resolve'     => [ $this, 'setSiteIcon' ],
		] );
		$r->addMutation( 'removeSiteLogo', [
			'type'        => Type::boolean(),
			'resolve'     => [ $this, 'removeSiteLogo' ],
		] );
		$r->addMutation( 'removeSiteIcon', [
			'type'        => Type::boolean(),
			'resolve'     => [ $this, 'removeSiteIcon' ],
		] );
	}

	private function gate(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to change the site identity.' );
		}
	}

	private function mediaOrFail( int $id ): void {
		if ( get_post_type( $id ) !== 'attachment' ) {
			throw new UserError( "Media item $id was not found." );
		}
	}

	/** @return array<string,mixed> */
	public function siteIdentity(): array {
		$this->gate();
		$logo = (int) get_theme_mod( 'custom_logo' );
		$icon = (int) get_option( 'site_icon' );
		return [
			'title'       => get_option( 'blogname' ),
			'tagline'     => get_option( 'blogdescription' ),
			'logoMediaId' => $logo ? (string) $logo : null,
			'logoUrl'     => $logo ? (string) wp_get_attachment_url( $logo ) : null,
			'iconMediaId' => $icon ? (string) $icon : null,
			'iconUrl'     => $icon ? (string) wp_get_attachment_url( $icon ) : null,
		];
	}

	/** @param array<string,mixed> $args */
	public function setSiteLogo( $root, array $args ): bool {
		$this->gate();
		$id = (int) $args['mediaId'];
		$this->mediaOrFail( $id );
		set_theme_mod( 'custom_logo', $id );
		return true;
	}

	/** @param array<string,mixed> $args */
	public function setSiteIcon( $root, array $args ): bool {
		$this->gate();
		$id = (int) $args['mediaId'];
		$this->mediaOrFail( $id );
		update_option( 'site_icon', $id );
		return true;
	}

	public function removeSiteLogo(): bool {
		$this->gate();
		remove_theme_mod( 'custom_logo' );
		return true;
	}

	public function removeSiteIcon(): bool {
		$this->gate();
		delete_option( 'site_icon' );
		return true;
	}
}
