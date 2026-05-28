<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Djinn's in-house, curated GraphQL schema. Every resolver is a thin wrapper over a WordPress
 * core function and enforces the current user's capabilities — so the Djinn can never grant a
 * wish the logged-in admin could not grant themselves.
 *
 * Deliberately small for the MVP (posts/pages, users, site info, options). This is the surface
 * the Djinn discovers via RAG and writes against.
 */
class SchemaFactory {

	private static ?Schema $schema = null;

	public static function build(): Schema {
		if ( self::$schema instanceof Schema ) {
			return self::$schema;
		}

		$post = new ObjectType(
			[
				'name'        => 'Post',
				'description' => 'A post, page, or any post-type entry.',
				'fields'      => [
					'id'       => [ 'type' => Type::id(), 'description' => 'The post ID.' ],
					'title'    => [ 'type' => Type::string(), 'description' => 'The post title.' ],
					'content'  => [ 'type' => Type::string(), 'description' => 'The raw post content (HTML/blocks).' ],
					'excerpt'  => [ 'type' => Type::string() ],
					'status'   => [ 'type' => Type::string(), 'description' => 'publish, draft, pending, private, etc.' ],
					'type'     => [ 'type' => Type::string(), 'description' => 'The post type (post, page, ...).' ],
					'slug'     => [ 'type' => Type::string() ],
					'link'     => [ 'type' => Type::string(), 'description' => 'The permalink.' ],
					'date'     => [ 'type' => Type::string(), 'description' => 'Publish date (GMT, ISO-ish).' ],
					'authorId' => [ 'type' => Type::id() ],
				],
			]
		);

		$user = new ObjectType(
			[
				'name'        => 'User',
				'description' => 'A WordPress user.',
				'fields'      => [
					'id'          => [ 'type' => Type::id() ],
					'displayName' => [ 'type' => Type::string() ],
					'email'       => [ 'type' => Type::string() ],
					'roles'       => [ 'type' => Type::listOf( Type::string() ) ],
				],
			]
		);

		$siteInfo = new ObjectType(
			[
				'name'        => 'SiteInfo',
				'description' => 'Top-level site settings.',
				'fields'      => [
					'title'       => [ 'type' => Type::string(), 'description' => 'Site title (blogname).' ],
					'description' => [ 'type' => Type::string(), 'description' => 'Tagline (blogdescription).' ],
					'url'         => [ 'type' => Type::string() ],
					'adminEmail'  => [ 'type' => Type::string() ],
					'language'    => [ 'type' => Type::string() ],
				],
			]
		);

		$postInput = new InputObjectType(
			[
				'name'        => 'PostInput',
				'description' => 'Fields for creating or updating a post/page.',
				'fields'      => [
					'title'    => [ 'type' => Type::string() ],
					'content'  => [ 'type' => Type::string() ],
					'excerpt'  => [ 'type' => Type::string() ],
					'status'   => [ 'type' => Type::string(), 'description' => 'publish, draft (default), pending, private.' ],
					'postType' => [ 'type' => Type::string(), 'description' => 'post (default) or page or a registered type.' ],
				],
			]
		);

		$r = new Resolvers();

		$query = new ObjectType(
			[
				'name'   => 'Query',
				'fields' => [
					'siteInfo' => [
						'type'    => $siteInfo,
						'resolve' => [ $r, 'siteInfo' ],
					],
					'posts'    => [
						'type'    => Type::listOf( $post ),
						'args'    => [
							'first'    => [ 'type' => Type::int(), 'defaultValue' => 10 ],
							'postType' => [ 'type' => Type::string(), 'defaultValue' => 'post' ],
							'status'   => [ 'type' => Type::string() ],
							'search'   => [ 'type' => Type::string() ],
						],
						'resolve' => [ $r, 'posts' ],
					],
					'post'     => [
						'type'    => $post,
						'args'    => [ 'id' => [ 'type' => Type::nonNull( Type::id() ) ] ],
						'resolve' => [ $r, 'post' ],
					],
					'users'    => [
						'type'    => Type::listOf( $user ),
						'args'    => [
							'first'  => [ 'type' => Type::int(), 'defaultValue' => 10 ],
							'search' => [ 'type' => Type::string() ],
						],
						'resolve' => [ $r, 'users' ],
					],
					'option'   => [
						'type'        => Type::string(),
						'description' => 'Read a wp_options value by name. Returns the value as a string (JSON-encoded if not scalar).',
						'args'        => [ 'name' => [ 'type' => Type::nonNull( Type::string() ) ] ],
						'resolve'     => [ $r, 'option' ],
					],
				],
			]
		);

		$mutation = new ObjectType(
			[
				'name'   => 'Mutation',
				'fields' => [
					'createPost'     => [
						'type'    => $post,
						'args'    => [ 'input' => [ 'type' => Type::nonNull( $postInput ) ] ],
						'resolve' => [ $r, 'createPost' ],
					],
					'updatePost'     => [
						'type'    => $post,
						'args'    => [
							'id'    => [ 'type' => Type::nonNull( Type::id() ) ],
							'input' => [ 'type' => Type::nonNull( $postInput ) ],
						],
						'resolve' => [ $r, 'updatePost' ],
					],
					'deletePost'     => [
						'type'    => Type::boolean(),
						'args'    => [
							'id'    => [ 'type' => Type::nonNull( Type::id() ) ],
							'force' => [ 'type' => Type::boolean(), 'defaultValue' => false ],
						],
						'resolve' => [ $r, 'deletePost' ],
					],
					'updateOption'   => [
						'type'        => Type::boolean(),
						'description' => 'Set a wp_options value. Value is a string (use JSON for non-scalar values).',
						'args'        => [
							'name'  => [ 'type' => Type::nonNull( Type::string() ) ],
							'value' => [ 'type' => Type::nonNull( Type::string() ) ],
						],
						'resolve'     => [ $r, 'updateOption' ],
					],
					'updateSiteInfo' => [
						'type'    => Type::boolean(),
						'args'    => [
							'title'       => [ 'type' => Type::string() ],
							'description' => [ 'type' => Type::string() ],
						],
						'resolve' => [ $r, 'updateSiteInfo' ],
					],
				],
			]
		);

		self::$schema = new Schema(
			[
				'query'    => $query,
				'mutation' => $mutation,
			]
		);
		return self::$schema;
	}
}
