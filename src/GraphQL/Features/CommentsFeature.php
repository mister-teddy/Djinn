<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Comments: list, moderate (approve / hold / spam / trash), reply, and delete.
 */
class CommentsFeature implements Feature {

	private const STATUSES = array( 'approve', 'hold', 'spam', 'trash' );

	public function register( Registry $r ): void {
		$comment = new ObjectType(
			array(
				'name'        => 'Comment',
				'description' => 'A comment on a post.',
				'fields'      => array(
					'id'       => array( 'type' => Type::id() ),
					'postId'   => array( 'type' => Type::id() ),
					'author'   => array( 'type' => Type::string() ),
					'email'    => array( 'type' => Type::string() ),
					'content'  => array( 'type' => Type::string() ),
					'status'   => array(
						'type'        => Type::string(),
						'description' => 'approved, hold, spam, or trash.',
					),
					'date'     => array( 'type' => Type::string() ),
					'parentId' => array( 'type' => Type::id() ),
				),
			)
		);
		$r->setType( 'Comment', $comment );

		$r->addQuery(
			'comments',
			array(
				'type'        => Type::listOf( $comment ),
				'description' => 'List comments, optionally filtered by post or status.',
				'args'        => array(
					'postId' => array( 'type' => Type::id() ),
					'status' => array(
						'type'        => Type::string(),
						'description' => 'all (default), approve, hold, spam, trash.',
					),
					'first'  => array(
						'type'         => Type::int(),
						'defaultValue' => 20,
					),
				),
				'resolve'     => array( $this, 'comments' ),
			)
		);

		$r->addMutation(
			'moderateComment',
			array(
				'type'        => Type::boolean(),
				'description' => 'Set a comment status: approve, hold, spam, or trash.',
				'args'        => array(
					'id'     => array( 'type' => Type::nonNull( Type::id() ) ),
					'status' => array( 'type' => Type::nonNull( Type::string() ) ),
				),
				'resolve'     => array( $this, 'moderateComment' ),
			)
		);

		$r->addMutation(
			'replyToComment',
			array(
				'type'        => $comment,
				'description' => 'Reply to a comment as the current user.',
				'args'        => array(
					'commentId' => array( 'type' => Type::nonNull( Type::id() ) ),
					'content'   => array( 'type' => Type::nonNull( Type::string() ) ),
				),
				'resolve'     => array( $this, 'replyToComment' ),
			)
		);

		$r->addMutation(
			'deleteComment',
			array(
				'type'    => Type::boolean(),
				'args'    => array(
					'id'    => array( 'type' => Type::nonNull( Type::id() ) ),
					'force' => array(
						'type'         => Type::boolean(),
						'defaultValue' => false,
					),
				),
				'resolve' => array( $this, 'deleteComment' ),
			)
		);
	}

	private function shape( \WP_Comment $c ): array {
		return array(
			'id'       => (string) $c->comment_ID,
			'postId'   => (string) $c->comment_post_ID,
			'author'   => $c->comment_author,
			'email'    => current_user_can( 'moderate_comments' ) ? $c->comment_author_email : null,
			'content'  => $c->comment_content,
			'status'   => wp_get_comment_status( $c ) ?: 'unknown',
			'date'     => $c->comment_date_gmt,
			'parentId' => $c->comment_parent ? (string) $c->comment_parent : null,
		);
	}

	/** @param array<string,mixed> $args */
	public function comments( $root, array $args ): array {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			throw new UserError( 'You do not have permission to view comments.' );
		}
		$comments = get_comments(
			array(
				'post_id' => isset( $args['postId'] ) ? (int) $args['postId'] : 0,
				'status'  => $args['status'] ?? 'all',
				'number'  => min( max( (int) ( $args['first'] ?? 20 ), 1 ), 200 ),
			)
		);
		return array_map( array( $this, 'shape' ), $comments );
	}

	/** @param array<string,mixed> $args */
	public function moderateComment( $root, array $args ): bool {
		$id     = (int) $args['id'];
		$status = (string) $args['status'];
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new UserError( 'Status must be one of: ' . implode( ', ', self::STATUSES ) . '.' );
		}
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			throw new UserError( 'You do not have permission to moderate this comment.' );
		}
		return (bool) wp_set_comment_status( $id, $status );
	}

	/** @param array<string,mixed> $args */
	public function replyToComment( $root, array $args ): array {
		$parent = get_comment( (int) $args['commentId'] );
		if ( ! $parent ) {
			throw new UserError( 'No such comment.' );
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			throw new UserError( 'You do not have permission to reply to comments.' );
		}
		$user = wp_get_current_user();
		$id   = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $parent->comment_post_ID,
				'comment_parent'       => (int) $parent->comment_ID,
				'comment_content'      => (string) $args['content'],
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_approved'     => 1,
			)
		);
		if ( ! $id ) {
			throw new UserError( 'Could not post the reply.' );
		}
		return $this->shape( get_comment( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function deleteComment( $root, array $args ): bool {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			throw new UserError( 'You do not have permission to delete this comment.' );
		}
		return (bool) wp_delete_comment( $id, (bool) ( $args['force'] ?? false ) );
	}
}
