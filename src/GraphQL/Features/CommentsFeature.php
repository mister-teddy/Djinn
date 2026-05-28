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

	private const STATUSES = [ 'approve', 'hold', 'spam', 'trash' ];

	public function register( Registry $r ): void {
		$comment = new ObjectType(
			[
				'name'        => 'Comment',
				'description' => 'A comment on a post.',
				'fields'      => [
					'id'       => [ 'type' => Type::id() ],
					'postId'   => [ 'type' => Type::id() ],
					'author'   => [ 'type' => Type::string() ],
					'email'    => [ 'type' => Type::string() ],
					'content'  => [ 'type' => Type::string() ],
					'status'   => [ 'type' => Type::string(), 'description' => 'approved, hold, spam, or trash.' ],
					'date'     => [ 'type' => Type::string() ],
					'parentId' => [ 'type' => Type::id() ],
				],
			]
		);
		$r->setType( 'Comment', $comment );

		$r->addQuery( 'comments', [
			'type'        => Type::listOf( $comment ),
			'description' => 'List comments, optionally filtered by post or status.',
			'args'        => [
				'postId' => [ 'type' => Type::id() ],
				'status' => [ 'type' => Type::string(), 'description' => 'all (default), approve, hold, spam, trash.' ],
				'first'  => [ 'type' => Type::int(), 'defaultValue' => 20 ],
			],
			'resolve'     => [ $this, 'comments' ],
		] );

		$r->addMutation( 'moderateComment', [
			'type'        => Type::boolean(),
			'description' => 'Set a comment status: approve, hold, spam, or trash.',
			'args'        => [
				'id'     => [ 'type' => Type::nonNull( Type::id() ) ],
				'status' => [ 'type' => Type::nonNull( Type::string() ) ],
			],
			'resolve'     => [ $this, 'moderateComment' ],
		] );

		$r->addMutation( 'replyToComment', [
			'type'        => $comment,
			'description' => 'Reply to a comment as the current user.',
			'args'        => [
				'commentId' => [ 'type' => Type::nonNull( Type::id() ) ],
				'content'   => [ 'type' => Type::nonNull( Type::string() ) ],
			],
			'resolve'     => [ $this, 'replyToComment' ],
		] );

		$r->addMutation( 'deleteComment', [
			'type'        => Type::boolean(),
			'args'        => [
				'id'    => [ 'type' => Type::nonNull( Type::id() ) ],
				'force' => [ 'type' => Type::boolean(), 'defaultValue' => false ],
			],
			'resolve'     => [ $this, 'deleteComment' ],
		] );
	}

	private function shape( \WP_Comment $c ): array {
		return [
			'id'       => (string) $c->comment_ID,
			'postId'   => (string) $c->comment_post_ID,
			'author'   => $c->comment_author,
			'email'    => current_user_can( 'moderate_comments' ) ? $c->comment_author_email : null,
			'content'  => $c->comment_content,
			'status'   => wp_get_comment_status( $c ) ?: 'unknown',
			'date'     => $c->comment_date_gmt,
			'parentId' => $c->comment_parent ? (string) $c->comment_parent : null,
		];
	}

	/** @param array<string,mixed> $args */
	public function comments( $root, array $args ): array {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			throw new UserError( 'You do not have permission to view comments.' );
		}
		$comments = get_comments(
			[
				'post_id' => isset( $args['postId'] ) ? (int) $args['postId'] : 0,
				'status'  => $args['status'] ?? 'all',
				'number'  => min( max( (int) ( $args['first'] ?? 20 ), 1 ), 200 ),
			]
		);
		return array_map( [ $this, 'shape' ], $comments );
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
			[
				'comment_post_ID'      => (int) $parent->comment_post_ID,
				'comment_parent'       => (int) $parent->comment_ID,
				'comment_content'      => (string) $args['content'],
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_approved'     => 1,
			]
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
