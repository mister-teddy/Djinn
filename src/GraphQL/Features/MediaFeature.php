<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Media library: list items, add one from a URL (sideload), set a post's featured image, delete.
 * (Binary uploads can't come through chat, but importing from a URL covers most wishes.)
 */
class MediaFeature implements Feature {

	public function register( Registry $r ): void {
		$media = new ObjectType(
			[
				'name'        => 'MediaItem',
				'description' => 'An attachment in the media library.',
				'fields'      => [
					'id'       => [ 'type' => Type::id() ],
					'title'    => [ 'type' => Type::string() ],
					'url'      => [ 'type' => Type::string() ],
					'mimeType' => [ 'type' => Type::string() ],
					'date'     => [ 'type' => Type::string() ],
				],
			]
		);
		$r->setType( 'MediaItem', $media );

		$r->addQuery( 'media', [
			'type'        => Type::listOf( $media ),
			'description' => 'List media library items.',
			'args'        => [
				'first'  => [ 'type' => Type::int(), 'defaultValue' => 20 ],
				'search' => [ 'type' => Type::string() ],
			],
			'resolve'     => [ $this, 'media' ],
		] );

		$r->addMutation( 'sideloadMedia', [
			'type'        => $media,
			'description' => 'Download a file from a URL into the media library.',
			'args'        => [
				'url'   => [ 'type' => Type::nonNull( Type::string() ) ],
				'title' => [ 'type' => Type::string() ],
			],
			'resolve'     => [ $this, 'sideloadMedia' ],
		] );

		$r->addMutation( 'setFeaturedImage', [
			'type'        => Type::boolean(),
			'description' => 'Set a post\'s featured image to a media item.',
			'args'        => [
				'postId'  => [ 'type' => Type::nonNull( Type::id() ) ],
				'mediaId' => [ 'type' => Type::nonNull( Type::id() ) ],
			],
			'resolve'     => [ $this, 'setFeaturedImage' ],
		] );

		$r->addMutation( 'deleteMedia', [
			'type'        => Type::boolean(),
			'args'        => [
				'id'    => [ 'type' => Type::nonNull( Type::id() ) ],
				'force' => [ 'type' => Type::boolean(), 'defaultValue' => true ],
			],
			'resolve'     => [ $this, 'deleteMedia' ],
		] );
	}

	private function shape( \WP_Post $a ): array {
		return [
			'id'       => (string) $a->ID,
			'title'    => get_the_title( $a ),
			'url'      => (string) wp_get_attachment_url( $a->ID ),
			'mimeType' => $a->post_mime_type,
			'date'     => $a->post_date_gmt,
		];
	}

	/** @param array<string,mixed> $args */
	public function media( $root, array $args ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new UserError( 'You do not have permission to view the media library.' );
		}
		$items = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => $args['search'] ?? '',
				'posts_per_page' => min( max( (int) ( $args['first'] ?? 20 ), 1 ), 100 ),
			]
		);
		return array_map( [ $this, 'shape' ], $items );
	}

	/** @param array<string,mixed> $args */
	public function sideloadMedia( $root, array $args ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new UserError( 'You do not have permission to upload files.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_sideload_image( (string) $args['url'], 0, $args['title'] ?? null, 'id' );
		if ( is_wp_error( $id ) ) {
			throw new UserError( $id->get_error_message() );
		}
		return $this->shape( get_post( (int) $id ) );
	}

	/** @param array<string,mixed> $args */
	public function setFeaturedImage( $root, array $args ): bool {
		$postId = (int) $args['postId'];
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			throw new UserError( 'You do not have permission to edit this post.' );
		}
		return (bool) set_post_thumbnail( $postId, (int) $args['mediaId'] );
	}

	/** @param array<string,mixed> $args */
	public function deleteMedia( $root, array $args ): bool {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'delete_post', $id ) ) {
			throw new UserError( 'You do not have permission to delete this media item.' );
		}
		return (bool) wp_delete_attachment( $id, (bool) ( $args['force'] ?? true ) );
	}
}
