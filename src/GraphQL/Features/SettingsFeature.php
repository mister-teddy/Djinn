<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Site settings (General / Reading / Writing / Discussion / Permalinks) as curated operations, so
 * the Djinn doesn't have to guess raw option names and formats. Everything gates on manage_options.
 */
class SettingsFeature implements Feature {

	public function register( Registry $r ): void {
		$settings = new ObjectType(
			[
				'name'        => 'SiteSettings',
				'description' => 'Common site settings.',
				'fields'      => [
					'title'                => [ 'type' => Type::string() ],
					'tagline'              => [ 'type' => Type::string() ],
					'adminEmail'           => [ 'type' => Type::string() ],
					'timezone'             => [ 'type' => Type::string() ],
					'dateFormat'           => [ 'type' => Type::string() ],
					'timeFormat'           => [ 'type' => Type::string() ],
					'startOfWeek'          => [ 'type' => Type::int() ],
					'language'             => [ 'type' => Type::string() ],
					'homepageMode'         => [ 'type' => Type::string(), 'description' => '"posts" (latest posts) or "page" (a static page).' ],
					'homepagePageId'       => [ 'type' => Type::id() ],
					'postsPageId'          => [ 'type' => Type::id() ],
					'postsPerPage'         => [ 'type' => Type::int() ],
					'searchEngineVisible'  => [ 'type' => Type::boolean(), 'description' => 'False when discouraging search engines (blog_public = 0).' ],
					'defaultCommentStatus' => [ 'type' => Type::string() ],
					'defaultCategoryId'    => [ 'type' => Type::id() ],
					'permalinkStructure'   => [ 'type' => Type::string() ],
				],
			]
		);
		$r->setType( 'SiteSettings', $settings );

		$r->addQuery( 'settings', [
			'type'        => $settings,
			'description' => 'Read common site settings.',
			'resolve'     => [ $this, 'settings' ],
		] );

		$r->addMutation( 'setHomepage', [
			'type'        => Type::boolean(),
			'description' => 'Set what the front page shows: latest posts, or a static page (with an optional separate posts page).',
			'args'        => [
				'mode'        => [ 'type' => Type::nonNull( Type::string() ), 'description' => '"posts" or "page".' ],
				'pageId'      => [ 'type' => Type::id(), 'description' => 'Required when mode is "page": the front page.' ],
				'postsPageId' => [ 'type' => Type::id(), 'description' => 'Optional page on which to show the blog posts.' ],
			],
			'resolve'     => [ $this, 'setHomepage' ],
		] );
		$r->addMutation( 'setPermalinkStructure', [
			'type'        => Type::boolean(),
			'description' => 'Set the permalink structure (e.g. "/%postname%/") and flush rewrite rules.',
			'args'        => [ 'structure' => [ 'type' => Type::nonNull( Type::string() ) ] ],
			'resolve'     => [ $this, 'setPermalinkStructure' ],
		] );
		$r->addMutation( 'updateReadingSettings', [
			'type'        => Type::boolean(),
			'args'        => [
				'postsPerPage'        => [ 'type' => Type::int() ],
				'searchEngineVisible' => [ 'type' => Type::boolean() ],
			],
			'resolve'     => [ $this, 'updateReadingSettings' ],
		] );
		$r->addMutation( 'updateGeneralSettings', [
			'type'        => Type::boolean(),
			'args'        => [
				'timezone'    => [ 'type' => Type::string(), 'description' => 'A PHP timezone like "Europe/Berlin".' ],
				'dateFormat'  => [ 'type' => Type::string() ],
				'timeFormat'  => [ 'type' => Type::string() ],
				'startOfWeek' => [ 'type' => Type::int() ],
			],
			'resolve'     => [ $this, 'updateGeneralSettings' ],
		] );
		$r->addMutation( 'updateDiscussionSettings', [
			'type'        => Type::boolean(),
			'args'        => [
				'allowComments'       => [ 'type' => Type::boolean(), 'description' => 'Default comment status for new posts.' ],
				'requireModeration'   => [ 'type' => Type::boolean() ],
				'requireRegistration' => [ 'type' => Type::boolean() ],
			],
			'resolve'     => [ $this, 'updateDiscussionSettings' ],
		] );
	}

	private function gate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new UserError( 'You do not have permission to change site settings.' );
		}
	}

	/** @return array<string,mixed> */
	public function settings(): array {
		$this->gate();
		$tz       = (string) get_option( 'timezone_string' );
		$onFront  = (int) get_option( 'page_on_front' );
		$forPosts = (int) get_option( 'page_for_posts' );
		$defCat   = (int) get_option( 'default_category' );
		return [
			'title'                => get_option( 'blogname' ),
			'tagline'              => get_option( 'blogdescription' ),
			'adminEmail'           => get_option( 'admin_email' ),
			'timezone'             => $tz !== '' ? $tz : ( 'UTC' . get_option( 'gmt_offset' ) ),
			'dateFormat'           => get_option( 'date_format' ),
			'timeFormat'           => get_option( 'time_format' ),
			'startOfWeek'          => (int) get_option( 'start_of_week' ),
			'language'             => get_bloginfo( 'language' ),
			'homepageMode'         => get_option( 'show_on_front' ) === 'page' ? 'page' : 'posts',
			'homepagePageId'       => $onFront ? (string) $onFront : null,
			'postsPageId'          => $forPosts ? (string) $forPosts : null,
			'postsPerPage'         => (int) get_option( 'posts_per_page' ),
			'searchEngineVisible'  => (bool) get_option( 'blog_public' ),
			'defaultCommentStatus' => get_option( 'default_comment_status' ),
			'defaultCategoryId'    => $defCat ? (string) $defCat : null,
			'permalinkStructure'   => get_option( 'permalink_structure' ),
		];
	}

	/** @param array<string,mixed> $args */
	public function setHomepage( $root, array $args ): bool {
		$this->gate();
		$mode = (string) $args['mode'];
		if ( $mode === 'posts' ) {
			update_option( 'show_on_front', 'posts' );
			return true;
		}
		if ( $mode !== 'page' ) {
			throw new UserError( 'mode must be "posts" or "page".' );
		}
		$pageId = (int) ( $args['pageId'] ?? 0 );
		if ( ! $pageId || get_post_type( $pageId ) !== 'page' ) {
			throw new UserError( 'pageId must be the id of an existing page.' );
		}
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $pageId );
		if ( isset( $args['postsPageId'] ) ) {
			update_option( 'page_for_posts', (int) $args['postsPageId'] );
		}
		return true;
	}

	/** @param array<string,mixed> $args */
	public function setPermalinkStructure( $root, array $args ): bool {
		$this->gate();
		global $wp_rewrite;
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$structure = (string) $args['structure'];
		$wp_rewrite->set_permalink_structure( $structure );
		$wp_rewrite->flush_rules( true );
		return get_option( 'permalink_structure' ) === $structure;
	}

	/** @param array<string,mixed> $args */
	public function updateReadingSettings( $root, array $args ): bool {
		$this->gate();
		if ( isset( $args['postsPerPage'] ) ) {
			update_option( 'posts_per_page', max( 1, (int) $args['postsPerPage'] ) );
		}
		if ( isset( $args['searchEngineVisible'] ) ) {
			update_option( 'blog_public', $args['searchEngineVisible'] ? 1 : 0 );
		}
		return true;
	}

	/** @param array<string,mixed> $args */
	public function updateGeneralSettings( $root, array $args ): bool {
		$this->gate();
		if ( isset( $args['timezone'] ) ) {
			$tz = (string) $args['timezone'];
			if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) {
				throw new UserError( "Invalid timezone '$tz'." );
			}
			update_option( 'timezone_string', $tz );
			update_option( 'gmt_offset', '' );
		}
		if ( isset( $args['dateFormat'] ) ) {
			update_option( 'date_format', (string) $args['dateFormat'] );
		}
		if ( isset( $args['timeFormat'] ) ) {
			update_option( 'time_format', (string) $args['timeFormat'] );
		}
		if ( isset( $args['startOfWeek'] ) ) {
			update_option( 'start_of_week', (int) $args['startOfWeek'] );
		}
		return true;
	}

	/** @param array<string,mixed> $args */
	public function updateDiscussionSettings( $root, array $args ): bool {
		$this->gate();
		if ( isset( $args['allowComments'] ) ) {
			update_option( 'default_comment_status', $args['allowComments'] ? 'open' : 'closed' );
		}
		if ( isset( $args['requireModeration'] ) ) {
			update_option( 'comment_moderation', $args['requireModeration'] ? 1 : 0 );
		}
		if ( isset( $args['requireRegistration'] ) ) {
			update_option( 'comment_registration', $args['requireRegistration'] ? 1 : 0 );
		}
		return true;
	}
}
