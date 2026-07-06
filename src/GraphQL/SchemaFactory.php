<?php

declare( strict_types=1 );

namespace Djinn\GraphQL;

use Djinn\Settings;
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

	private static ?Schema $schema     = null;
	private static ?Registry $registry = null;

	public static function build(): Schema {
		if ( self::$schema instanceof Schema ) {
			return self::$schema;
		}

		$post = new ObjectType(
			array(
				'name'        => 'Post',
				'description' => 'A post, page, or any post-type entry.',
				'fields'      => array(
					'id'       => array(
						'type'        => Type::id(),
						'description' => 'The post ID.',
					),
					'title'    => array(
						'type'        => Type::string(),
						'description' => 'The post title.',
					),
					'content'  => array(
						'type'        => Type::string(),
						'description' => 'The raw post content (HTML/blocks).',
					),
					'excerpt'  => array( 'type' => Type::string() ),
					'status'   => array(
						'type'        => Type::string(),
						'description' => 'publish, draft, pending, private, etc.',
					),
					'type'     => array(
						'type'        => Type::string(),
						'description' => 'The post type (post, page, ...).',
					),
					'slug'     => array( 'type' => Type::string() ),
					'link'     => array(
						'type'        => Type::string(),
						'description' => 'The public permalink (to view).',
					),
					'editUrl'  => array(
						'type'        => Type::string(),
						'description' => 'The wp-admin edit URL.',
					),
					'date'     => array(
						'type'        => Type::string(),
						'description' => 'Publish date (GMT, ISO-ish).',
					),
					'authorId' => array( 'type' => Type::id() ),
				),
			)
		);

		$user = new ObjectType(
			array(
				'name'        => 'User',
				'description' => 'A WordPress user.',
				'fields'      => array(
					'id'          => array( 'type' => Type::id() ),
					'displayName' => array( 'type' => Type::string() ),
					'email'       => array( 'type' => Type::string() ),
					'roles'       => array( 'type' => Type::listOf( Type::string() ) ),
				),
			)
		);

		$siteInfo = new ObjectType(
			array(
				'name'        => 'SiteInfo',
				'description' => 'Top-level site settings.',
				'fields'      => array(
					'title'       => array(
						'type'        => Type::string(),
						'description' => 'Site title (blogname).',
					),
					'description' => array(
						'type'        => Type::string(),
						'description' => 'Tagline (blogdescription).',
					),
					'url'         => array( 'type' => Type::string() ),
					'adminEmail'  => array( 'type' => Type::string() ),
					'language'    => array( 'type' => Type::string() ),
				),
			)
		);

		$fetchedPage = new ObjectType(
			array(
				'name'        => 'FetchedPage',
				'description' => 'The readable content of an external web page, fetched by URL.',
				'fields'      => array(
					'url'     => array( 'type' => Type::string() ),
					'title'   => array(
						'type'        => Type::string(),
						'description' => 'The page title (from its <title>).',
					),
					'content' => array(
						'type'        => Type::string(),
						'description' => 'The main content as sanitized HTML — pass to createPost/updatePost content.',
					),
					'text'    => array(
						'type'        => Type::string(),
						'description' => 'The main content as plain text.',
					),
				),
			)
		);

		$postInput = new InputObjectType(
			array(
				'name'        => 'PostInput',
				'description' => 'Fields for creating or updating a post/page.',
				'fields'      => array(
					'title'    => array( 'type' => Type::string() ),
					'content'  => array( 'type' => Type::string() ),
					'excerpt'  => array( 'type' => Type::string() ),
					'status'   => array(
						'type'        => Type::string(),
						'description' => 'publish, draft (default), pending, private.',
					),
					'postType' => array(
						'type'        => Type::string(),
						'description' => 'post (default) or page or a registered type.',
					),
				),
			)
		);

		$res = new Resolvers();
		$reg = new Registry();
		$reg->setType( 'Post', $post );
		$reg->setType( 'User', $user );
		$reg->setType( 'SiteInfo', $siteInfo );
		$reg->setType( 'PostInput', $postInput );
		$reg->setType( 'FetchedPage', $fetchedPage );

		// --- Core content/site fields -------------------------------------
		$reg->setCurrentDomain( 'Core' );
		$reg->addQuery(
			'siteInfo',
			array(
				'type'    => $siteInfo,
				'resolve' => array( $res, 'siteInfo' ),
			)
		);
		$reg->addQuery(
			'posts',
			array(
				'type'    => Type::listOf( $post ),
				'args'    => array(
					'first'    => array(
						'type'         => Type::int(),
						'defaultValue' => 10,
					),
					'postType' => array(
						'type'         => Type::string(),
						'defaultValue' => 'post',
					),
					'status'   => array( 'type' => Type::string() ),
					'search'   => array( 'type' => Type::string() ),
				),
				'resolve' => array( $res, 'posts' ),
			)
		);
		$reg->addQuery(
			'post',
			array(
				'type'    => $post,
				'args'    => array( 'id' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve' => array( $res, 'post' ),
			)
		);
		$reg->addQuery(
			'users',
			array(
				'type'    => Type::listOf( $user ),
				'args'    => array(
					'first'  => array(
						'type'         => Type::int(),
						'defaultValue' => 10,
					),
					'search' => array( 'type' => Type::string() ),
				),
				'resolve' => array( $res, 'users' ),
			)
		);
		$reg->addQuery(
			'option',
			array(
				'type'        => Type::string(),
				'description' => 'Read a wp_options value by name. Returns the value as a string (JSON-encoded if not scalar).',
				'args'        => array( 'name' => array( 'type' => Type::nonNull( Type::string() ) ) ),
				'resolve'     => array( $res, 'option' ),
			)
		);

		$reg->addQuery(
			'fetchUrl',
			array(
				'type'        => $fetchedPage,
				'description' => 'Fetch an external web page by URL and return its readable content (title + main HTML/text). To import a page into a post: call fetchUrl, then createPost with the returned title and content.',
				'args'        => array( 'url' => array( 'type' => Type::nonNull( Type::string() ) ) ),
				'resolve'     => array( $res, 'fetchUrl' ),
			)
		);
		$reg->addMutation(
			'createPost',
			array(
				'type'    => $post,
				'args'    => array( 'input' => array( 'type' => Type::nonNull( $postInput ) ) ),
				'resolve' => array( $res, 'createPost' ),
			)
		);
		$reg->addMutation(
			'updatePost',
			array(
				'type'    => $post,
				'args'    => array(
					'id'    => array( 'type' => Type::nonNull( Type::id() ) ),
					'input' => array( 'type' => Type::nonNull( $postInput ) ),
				),
				'resolve' => array( $res, 'updatePost' ),
			)
		);
		$reg->addMutation(
			'deletePost',
			array(
				'type'    => Type::boolean(),
				'args'    => array(
					'id'    => array( 'type' => Type::nonNull( Type::id() ) ),
					'force' => array(
						'type'         => Type::boolean(),
						'defaultValue' => false,
					),
				),
				'resolve' => array( $res, 'deletePost' ),
			)
		);
		// Site-settings writes are Pro: Free can read options/site info but changes only content.
		if ( Settings::isPro() ) {
			$reg->addMutation(
				'updateOption',
				array(
					'type'        => Type::boolean(),
					'description' => 'Set a wp_options value. Value is a string (use JSON for non-scalar values).',
					'args'        => array(
						'name'  => array( 'type' => Type::nonNull( Type::string() ) ),
						'value' => array( 'type' => Type::nonNull( Type::string() ) ),
					),
					'resolve'     => array( $res, 'updateOption' ),
				)
			);
			$reg->addMutation(
				'updateSiteInfo',
				array(
					'type'    => Type::boolean(),
					'args'    => array(
						'title'       => array( 'type' => Type::string() ),
						'description' => array( 'type' => Type::string() ),
					),
					'resolve' => array( $res, 'updateSiteInfo' ),
				)
			);
		}

		// --- Built-in feature domains -------------------------------------
		foreach ( self::features() as $feature ) {
			$reg->setCurrentDomain( self::domainLabel( $feature ) );
			$feature->register( $reg );
		}

		// --- Third-party extensions ---------------------------------------
		// Plugins can add their own types/resolvers without touching core:
		//   add_action( 'djinn_register_schema', fn( $reg ) => $reg->addQuery( ... ) );
		$reg->setCurrentDomain( 'Extensions' );
		do_action( 'djinn_register_schema', $reg );

		self::$registry = $reg;
		self::$schema   = new Schema(
			array(
				'query'    => new ObjectType(
					array(
						'name'   => 'Query',
						'fields' => $reg->queries(),
					)
				),
				'mutation' => new ObjectType(
					array(
						'name'   => 'Mutation',
						'fields' => $reg->mutations(),
					)
				),
			)
		);
		return self::$schema;
	}

	/** Every supported operation, grouped by capability domain — for the admin Capabilities view. */
	public static function operations(): array {
		self::build(); // populates the registry
		return self::$registry ? self::$registry->operations() : array();
	}

	/** Human domain label from a Feature class, e.g. SiteEditorFeature → "Site Editor". */
	private static function domainLabel( Feature $feature ): string {
		$short = ( new \ReflectionClass( $feature ) )->getShortName();
		$short = (string) preg_replace( '/Feature$/', '', $short );
		return trim( (string) preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', $short ) );
	}

	/**
	 * The Free edition's content-only feature set. Everything else is Pro — a newly added Feature is
	 * Pro by default until it is listed here, which is the safe direction for a new capability.
	 */
	private const FREE_FEATURES = array(
		Features\MediaFeature::class,
		Features\TaxonomyFeature::class,
		Features\CommentsFeature::class,
	);

	/**
	 * Built-in capability domains, in menu-ish order. Add a class here to grow the schema. Free
	 * registers only FREE_FEATURES (plus the always-on inline content block); Pro registers all, so
	 * the scope gate is purely which features exist in the schema — no per-call tier checks.
	 *
	 * @return array<int,Feature>
	 */
	private static function features(): array {
		$features = array(
			new Features\MetaFeature(),
			new Features\AppearanceFeature(),
			new Features\MenusFeature(),
			new Features\WidgetsFeature(),
			new Features\CustomizerFeature(),
			new Features\SiteEditorFeature(),
			new Features\PageStructureFeature(),
			new Features\TaxonomyFeature(),
			new Features\CommentsFeature(),
			new Features\UsersFeature(),
			new Features\MediaFeature(),
			new Features\SettingsFeature(),
			new Features\SystemFeature(),
			new Features\ToolsFeature(),
			new Features\CronFeature(),
			new Features\RestFeature(),
		);

		// Curated per-plugin domains, registered only when their plugin is active — so their types
		// (and therefore their RAG chunks) never appear on sites that don't have the plugin.
		if ( Features\WooCommerceFeature::isActive() ) {
			$features[] = new Features\WooCommerceFeature();
		}

		if ( ! Settings::isPro() ) {
			$features = array_values(
				array_filter(
					$features,
					static fn( Feature $f ) => in_array( get_class( $f ), self::FREE_FEATURES, true )
				)
			);
		}

		return $features;
	}
}
