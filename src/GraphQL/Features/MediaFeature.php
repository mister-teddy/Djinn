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
 * Media library: list items, add one from a URL (sideload) or from a file the user attached to their
 * wish (importMedia), set a post's featured image, update fields, delete.
 */
class MediaFeature implements Feature {

	public function register( Registry $r ): void {
		$media = new ObjectType(
			array(
				'name'        => 'MediaItem',
				'description' => 'An attachment in the media library.',
				'fields'      => array(
					'id'       => array( 'type' => Type::id() ),
					'title'    => array( 'type' => Type::string() ),
					'url'      => array( 'type' => Type::string() ),
					'mimeType' => array( 'type' => Type::string() ),
					'date'     => array( 'type' => Type::string() ),
				),
			)
		);
		$r->setType( 'MediaItem', $media );

		$r->addQuery(
			'media',
			array(
				'type'        => Type::listOf( $media ),
				'description' => 'List media library items.',
				'args'        => array(
					'first'  => array(
						'type'         => Type::int(),
						'defaultValue' => 20,
					),
					'search' => array( 'type' => Type::string() ),
				),
				'resolve'     => array( $this, 'media' ),
			)
		);

		$r->addMutation(
			'sideloadMedia',
			array(
				'type'        => $media,
				'description' => 'Download a file from a URL into the media library.',
				'args'        => array(
					'url'   => array( 'type' => Type::nonNull( Type::string() ) ),
					'title' => array( 'type' => Type::string() ),
				),
				'resolve'     => array( $this, 'sideloadMedia' ),
			)
		);

		$r->addMutation(
			'importMedia',
			array(
				'type'        => $media,
				'description' => 'Import a file the user ATTACHED to their wish (identified by its "import token") into the media library. Use this — NOT sideloadMedia — for attached/uploaded files: it takes the token, not a URL. Pass postId to also set it as that post\'s featured image.',
				'args'        => array(
					'token'  => array(
						'type'        => Type::nonNull( Type::string() ),
						'description' => 'The import token shown for the attached file.',
					),
					'title'  => array( 'type' => Type::string() ),
					'postId' => array(
						'type'        => Type::id(),
						'description' => 'If set, also make the imported image this post\'s featured image.',
					),
				),
				'resolve'     => array( $this, 'importMedia' ),
			)
		);

		$r->addMutation(
			'updateMedia',
			array(
				'type'        => $media,
				'description' => 'Update a media item\'s title, alt text, caption, or description.',
				'args'        => array(
					'id'          => array( 'type' => Type::nonNull( Type::id() ) ),
					'title'       => array( 'type' => Type::string() ),
					'altText'     => array(
						'type'        => Type::string(),
						'description' => 'Alt text (accessibility) for images.',
					),
					'caption'     => array( 'type' => Type::string() ),
					'description' => array( 'type' => Type::string() ),
				),
				'resolve'     => array( $this, 'updateMedia' ),
			)
		);

		$r->addMutation(
			'setFeaturedImage',
			array(
				'type'        => Type::boolean(),
				'description' => 'Set a post\'s featured image to a media item.',
				'args'        => array(
					'postId'  => array( 'type' => Type::nonNull( Type::id() ) ),
					'mediaId' => array( 'type' => Type::nonNull( Type::id() ) ),
				),
				'resolve'     => array( $this, 'setFeaturedImage' ),
			)
		);

		$r->addMutation(
			'deleteMedia',
			array(
				'type'    => Type::boolean(),
				'args'    => array(
					'id'    => array( 'type' => Type::nonNull( Type::id() ) ),
					'force' => array(
						'type'         => Type::boolean(),
						'defaultValue' => true,
					),
				),
				'resolve' => array( $this, 'deleteMedia' ),
			)
		);
	}

	private function shape( \WP_Post $a ): array {
		return array(
			'id'       => (string) $a->ID,
			'title'    => get_the_title( $a ),
			'url'      => (string) wp_get_attachment_url( $a->ID ),
			'mimeType' => $a->post_mime_type,
			'date'     => $a->post_date_gmt,
		);
	}

	/** @param array<string,mixed> $args */
	public function media( $root, array $args ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new UserError( esc_html( 'You do not have permission to view the media library.' ) );
		}
		$items = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => $args['search'] ?? '',
				'posts_per_page' => min( max( (int) ( $args['first'] ?? 20 ), 1 ), 100 ),
			)
		);
		return array_map( array( $this, 'shape' ), $items );
	}

	/** @param array<string,mixed> $args */
	public function sideloadMedia( $root, array $args ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new UserError( esc_html( 'You do not have permission to upload files.' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_sideload_image( (string) $args['url'], 0, $args['title'] ?? null, 'id' );
		if ( is_wp_error( $id ) ) {
			throw new UserError( esc_html( $id->get_error_message() ) );
		}
		return $this->shape( get_post( (int) $id ) );
	}

	/**
	 * Import a file the user attached to their wish (by its import token) into the media library.
	 * The file already lives in our private dir; we hand it to media_handle_sideload as the tmp
	 * file, which copies it into the uploads library and builds the attachment + metadata.
	 *
	 * @param array<string,mixed> $args
	 */
	public function importMedia( $root, array $args ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new UserError( esc_html( 'You do not have permission to upload files.' ) );
		}
		$file = Downloads::resolve( (string) $args['token'] );
		if ( ! $file ) {
			throw new UserError( esc_html( 'That attachment was not found or has expired — ask the user to attach the file again.' ) );
		}
		$postId = isset( $args['postId'] ) ? (int) $args['postId'] : 0;
		if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
			throw new UserError( esc_html( 'You do not have permission to edit that post.' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_sideload(
			array(
				'name'     => $file['filename'],
				'tmp_name' => $file['path'],
			),
			$postId,
			$args['title'] ?? null,
			array( 'test_form' => false )
		);
		if ( is_wp_error( $id ) ) {
			throw new UserError( esc_html( $id->get_error_message() ) );
		}
		$id = (int) $id;
		if ( $postId > 0 ) {
			set_post_thumbnail( $postId, $id );
		}
		return $this->shape( get_post( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function updateMedia( $root, array $args ): array {
		$id = (int) $args['id'];
		if ( get_post_type( $id ) !== 'attachment' ) {
			throw new UserError( esc_html( "No media item with id $id." ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new UserError( esc_html( 'You do not have permission to edit this media item.' ) );
		}
		$post = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$post['post_title'] = (string) $args['title'];
		}
		if ( isset( $args['caption'] ) ) {
			$post['post_excerpt'] = (string) $args['caption'];
		}
		if ( isset( $args['description'] ) ) {
			$post['post_content'] = (string) $args['description'];
		}
		if ( count( $post ) > 1 ) {
			$res = wp_update_post( $post, true );
			if ( is_wp_error( $res ) ) {
				throw new UserError( esc_html( $res->get_error_message() ) );
			}
		}
		if ( isset( $args['altText'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', (string) $args['altText'] );
		}
		return $this->shape( get_post( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function setFeaturedImage( $root, array $args ): bool {
		$postId = (int) $args['postId'];
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			throw new UserError( esc_html( 'You do not have permission to edit this post.' ) );
		}
		return (bool) set_post_thumbnail( $postId, (int) $args['mediaId'] );
	}

	/** @param array<string,mixed> $args */
	public function deleteMedia( $root, array $args ): bool {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'delete_post', $id ) ) {
			throw new UserError( esc_html( 'You do not have permission to delete this media item.' ) );
		}
		return (bool) wp_delete_attachment( $id, (bool) ( $args['force'] ?? true ) );
	}
}
