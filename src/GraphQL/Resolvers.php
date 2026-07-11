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
			throw new UserError( esc_html( 'You do not have permission to read this post.' ) );
		}
		return $this->shapePost( $post );
	}

	/** @param array<string,mixed> $args */
	public function users( $root, array $args ): array {
		if ( ! current_user_can( 'list_users' ) ) {
			throw new UserError( esc_html( 'You do not have permission to list users.' ) );
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
			throw new UserError( esc_html( 'You do not have permission to read options.' ) );
		}
		$value = get_option( $args['name'] );
		if ( $value === false ) {
			return null;
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Fetch an external web page and return its readable content, so the model can import it into a
	 * post. Extraction is a lightweight heuristic (strip chrome, keep the main article/body); the
	 * model refines the result into blocks.
	 *
	 * ponytail: heuristic reader, not full Readability — swap in a scoring extractor if pages import poorly.
	 *
	 * @param array<string,mixed> $args
	 */
	public function fetchUrl( $root, array $args ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			throw new UserError( esc_html( 'You do not have permission to import content.' ) );
		}
		$url = esc_url_raw( (string) $args['url'] );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			throw new UserError( esc_html( 'That does not look like a fetchable URL.' ) );
		}
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'user-agent'  => 'Djinn/1.0 (+WordPress content import)',
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new UserError( esc_html( 'Could not fetch that URL: ' . $resp->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			throw new UserError( esc_html( "That URL returned HTTP $code." ) );
		}
		$ctype = (string) wp_remote_retrieve_header( $resp, 'content-type' );
		if ( $ctype && ! preg_match( '#(html|text|xml)#i', $ctype ) ) {
			throw new UserError( esc_html( "That URL is not a web page (content-type: $ctype)." ) );
		}
		$html = (string) wp_remote_retrieve_body( $resp );

		$title   = '';
		$content = $html;
		if ( trim( $html ) !== '' && class_exists( 'DOMDocument' ) ) {
			$prev = libxml_use_internal_errors( true );
			$doc  = new \DOMDocument();
			$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );

			$titles = $doc->getElementsByTagName( 'title' );
			if ( $titles->length ) {
				$title = trim( $titles->item( 0 )->textContent );
			}

			foreach ( array( 'script', 'style', 'noscript', 'svg', 'nav', 'header', 'footer', 'aside', 'form', 'iframe' ) as $tag ) {
				$nodes = $doc->getElementsByTagName( $tag );
				for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
					$node = $nodes->item( $i );
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}

			$container = null;
			foreach ( array( 'article', 'main', 'body' ) as $tag ) {
				$els = $doc->getElementsByTagName( $tag );
				if ( $els->length ) {
					$container = $els->item( 0 );
					break;
				}
			}
			if ( $container ) {
				$content = '';
				foreach ( $container->childNodes as $child ) {
					$content .= (string) $doc->saveHTML( $child );
				}
			}
		}

		$content = wp_kses_post( trim( $content ) );
		// ponytail: hard cap so a huge page can't blow the wish's token budget.
		$content = mb_substr( $content, 0, 200000 );
		$text    = trim( (string) preg_replace( "/\n{3,}/", "\n\n", wp_strip_all_tags( $content ) ) );

		return array(
			'url'     => $url,
			'title'   => $title,
			'content' => $content,
			'text'    => $text,
		);
	}

	/** @param array<string,mixed> $args */
	public function createPost( $root, array $args ): array {
		$input    = $args['input'];
		$postType = $input['postType'] ?? 'post';
		$capObj   = get_post_type_object( $postType );
		if ( ! $capObj || ! current_user_can( $capObj->cap->create_posts ) ) {
			throw new UserError( esc_html( "You do not have permission to create '$postType' entries." ) );
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
			throw new UserError( esc_html( $id->get_error_message() ) );
		}
		return $this->shapePost( get_post( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function updatePost( $root, array $args ): array {
		$id = (int) $args['id'];
		if ( ! get_post( $id ) ) {
			throw new UserError( esc_html( "No post with id $id." ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new UserError( esc_html( 'You do not have permission to edit this post.' ) );
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
			throw new UserError( esc_html( $res->get_error_message() ) );
		}
		return $this->shapePost( get_post( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function deletePost( $root, array $args ): bool {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'delete_post', $id ) ) {
			throw new UserError( esc_html( 'You do not have permission to delete this post.' ) );
		}
		return (bool) wp_delete_post( $id, (bool) ( $args['force'] ?? false ) );
	}

}
