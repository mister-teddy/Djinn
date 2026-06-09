<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

use GraphQL\Error\UserError;

/**
 * Resolvers map GraphQL fields onto WordPress core functions. Every write — and any read of
 * sensitive data — gates on current_user_can(), which is the real security backstop: the Djinn
 * cannot exceed the logged-in user's actual capabilities, whatever GraphQL it generates.
 */
class Resolvers {

	/** @param \WP_Post $post */
	private function shapePost( $post ): array {
		return array(
			'id'       => (string) $post->ID,
			'title'    => get_the_title( $post ),
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'status'   => $post->post_status,
			'type'     => $post->post_type,
			'slug'     => $post->post_name,
			'link'     => (string) get_permalink( $post ),
			'editUrl'  => (string) get_edit_post_link( $post->ID, 'raw' ),
			'date'     => $post->post_date_gmt,
			'authorId' => (string) $post->post_author,
		);
	}

	public function siteInfo(): array {
		return array(
			'title'       => get_option( 'blogname' ),
			'description' => get_option( 'blogdescription' ),
			'url'         => home_url(),
			'adminEmail'  => current_user_can( 'manage_options' ) ? get_option( 'admin_email' ) : null,
			'language'    => get_bloginfo( 'language' ),
		);
	}

	/** @param array<string,mixed> $args */
	public function posts( $root, array $args ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => $args['postType'] ?? 'post',
				'post_status'    => $args['status'] ?? 'any',
				's'              => $args['search'] ?? '',
				'posts_per_page' => min( max( (int) ( $args['first'] ?? 10 ), 1 ), 100 ),
				'no_found_rows'  => true,
			)
		);
		return array_map( array( $this, 'shapePost' ), $query->posts );
	}

	/** @param array<string,mixed> $args */
	public function post( $root, array $args ): ?array {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return null;
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			throw new UserError( 'You do not have permission to read this post.' );
		}
		return $this->shapePost( $post );
	}

	/** @param array<string,mixed> $args */
	public function users( $root, array $args ): array {
		if ( ! current_user_can( 'list_users' ) ) {
			throw new UserError( 'You do not have permission to list users.' );
		}
		$users = get_users(
			array(
				'number' => min( max( (int) ( $args['first'] ?? 10 ), 1 ), 100 ),
				'search' => isset( $args['search'] ) ? '*' . $args['search'] . '*' : '',
			)
		);
		return array_map(
			static fn( $u ) => array(
				'id'          => (string) $u->ID,
				'displayName' => $u->display_name,
				'email'       => $u->user_email,
				'roles'       => $u->roles,
			),
			$users
		);
	}

	/** @param array<string,mixed> $args */
	public function option( $root, array $args ): ?string {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new UserError( 'You do not have permission to read options.' );
		}
		$value = get_option( $args['name'] );
		if ( $value === false ) {
			return null;
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/** @param array<string,mixed> $args */
	public function createPost( $root, array $args ): array {
		$input    = $args['input'];
		$postType = $input['postType'] ?? 'post';
		$capObj   = get_post_type_object( $postType );
		if ( ! $capObj || ! current_user_can( $capObj->cap->create_posts ) ) {
			throw new UserError( "You do not have permission to create '$postType' entries." );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => $postType,
				'post_title'   => $input['title'] ?? '',
				'post_content' => $input['content'] ?? '',
				'post_excerpt' => $input['excerpt'] ?? '',
				'post_status'  => $input['status'] ?? 'draft',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new UserError( $id->get_error_message() );
		}
		return $this->shapePost( get_post( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function updatePost( $root, array $args ): array {
		$id = (int) $args['id'];
		if ( ! get_post( $id ) ) {
			throw new UserError( "No post with id $id." );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new UserError( 'You do not have permission to edit this post.' );
		}
		$input  = $args['input'];
		$update = array( 'ID' => $id );
		foreach ( array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
		) as $in => $col ) {
			if ( isset( $input[ $in ] ) ) {
				$update[ $col ] = $input[ $in ];
			}
		}
		$res = wp_update_post( $update, true );
		if ( is_wp_error( $res ) ) {
			throw new UserError( $res->get_error_message() );
		}
		return $this->shapePost( get_post( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function deletePost( $root, array $args ): bool {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'delete_post', $id ) ) {
			throw new UserError( 'You do not have permission to delete this post.' );
		}
		return (bool) wp_delete_post( $id, (bool) ( $args['force'] ?? false ) );
	}

	/** @param array<string,mixed> $args */
	public function updateOption( $root, array $args ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new UserError( 'You do not have permission to change options.' );
		}
		return (bool) update_option( $args['name'], $args['value'] );
	}

	/** @param array<string,mixed> $args */
	public function updateSiteInfo( $root, array $args ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new UserError( 'You do not have permission to change site settings.' );
		}
		if ( isset( $args['title'] ) ) {
			update_option( 'blogname', $args['title'] );
		}
		if ( isset( $args['description'] ) ) {
			update_option( 'blogdescription', $args['description'] );
		}
		return true;
	}
}
