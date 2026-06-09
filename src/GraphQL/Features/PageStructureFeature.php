<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\BlockMarkup;
use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Resolve what actually renders at a front-end view. A block-theme page is not one object: the
 * template hierarchy picks a template (front-page → home → index, single-…, page-…), which embeds
 * the header/footer parts and — for singular views — the post's own content. This walks that
 * hierarchy, inlines the parts and patterns, fills in post-content, and tags each region with its
 * editable source, so the agent can locate any element a visitor sees and know what to edit.
 *
 * Gated on edit_theme_options. Only meaningful on block themes; classic themes have no templates.
 */
class PageStructureFeature implements Feature {

	public function register( Registry $r ): void {
		$source = new ObjectType(
			array(
				'name'        => 'PageSource',
				'description' => 'An editable source that contributes blocks to a rendered page.',
				'fields'      => array(
					'kind'  => array(
						'type'        => Type::string(),
						'description' => 'template, template_part, or post.',
					),
					'id'    => array(
						'type'        => Type::id(),
						'description' => 'Edit via updateSiteTemplate (template), updateSiteTemplatePart (template_part), or updatePost (post).',
					),
					'title' => array( 'type' => Type::string() ),
				),
			)
		);
		$r->setType( 'PageSource', $source );

		$page = new ObjectType(
			array(
				'name'        => 'RenderedPage',
				'description' => 'The fully composed block tree for a front-end view, with provenance.',
				'fields'      => array(
					'view'         => array(
						'type'        => Type::string(),
						'description' => 'What resolved, in words.',
					),
					'templateId'   => array(
						'type'        => Type::id(),
						'description' => 'The top template (edit via updateSiteTemplate).',
					),
					'templateSlug' => array( 'type' => Type::string() ),
					'sources'      => array(
						'type'        => Type::listOf( $source ),
						'description' => 'Every editable source in the page. Find a block in blocks, see which `djinn:region` encloses it, then edit that source.',
					),
					'blocks'       => array(
						'type'        => Type::string(),
						'description' => 'Resolved block markup: template + parts + patterns inlined, post-content filled. `djinn:region` comments mark which source each region belongs to (the outermost is the template).',
					),
				),
			)
		);
		$r->setType( 'RenderedPage', $page );

		$r->addQuery(
			'renderedPage',
			array(
				'type'        => $page,
				'description' => 'Resolve what renders at a view, to find or edit something a visitor sees. Pass one of: view ("home" or "front"), pageId, postId, templateSlug, or url.',
				'args'        => array(
					'view'         => array(
						'type'        => Type::string(),
						'description' => '"home" (blog posts index) or "front" (the front page).',
					),
					'pageId'       => array( 'type' => Type::id() ),
					'postId'       => array( 'type' => Type::id() ),
					'templateSlug' => array(
						'type'        => Type::string(),
						'description' => 'A template slug, e.g. "single", "archive", "404".',
					),
					'url'          => array(
						'type'        => Type::string(),
						'description' => 'A front-end URL on this site.',
					),
				),
				'resolve'     => array( $this, 'renderedPage' ),
			)
		);
	}

	private function gate(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to read site templates.' );
		}
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function renderedPage( $root, array $args ): array {
		$this->gate();
		if ( ! function_exists( 'get_block_templates' ) ) {
			throw new UserError( 'This site is not a block theme, so pages are not composed from templates. Read navMenus, sidebars, or the post/page content instead.' );
		}

		[ $hierarchy, $postId, $desc ] = $this->resolve( $args );

		$template = $this->pickTemplate( $hierarchy );
		if ( ! $template ) {
			throw new UserError( "No template found for $desc (looked for: " . implode( ', ', $hierarchy ) . ').' );
		}

		$markup = (string) $template->content;
		if ( $postId ) {
			$markup = $this->fillPostContent( $markup, $postId );
		}

		$title  = is_string( $template->title ) && $template->title !== '' ? $template->title : (string) $template->slug;
		$blocks = BlockMarkup::region( 'template', (string) $template->id, $title, BlockMarkup::expand( $markup ) );

		return array(
			'view'         => "$desc → template '{$template->slug}'",
			'templateId'   => (string) $template->id,
			'templateSlug' => (string) $template->slug,
			'sources'      => BlockMarkup::sources( $blocks ),
			'blocks'       => $blocks,
		);
	}

	/**
	 * Map the requested view to a WordPress template hierarchy (most specific slug first) and, for
	 * singular views, the post whose content fills the template.
	 *
	 * @param array<string,mixed> $args
	 * @return array{0:array<int,string>,1:int,2:string}
	 */
	private function resolve( array $args ): array {
		$templateSlug = trim( (string) ( $args['templateSlug'] ?? '' ) );
		if ( $templateSlug !== '' ) {
			return array( array( $templateSlug ), 0, "template '$templateSlug'" );
		}

		$postId = (int) ( $args['postId'] ?? 0 );
		$pageId = (int) ( $args['pageId'] ?? 0 );
		$view   = strtolower( trim( (string) ( $args['view'] ?? '' ) ) );
		$url    = trim( (string) ( $args['url'] ?? '' ) );

		if ( $url !== '' ) {
			$path = trim( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' ), '/' );
			if ( $path === '' || untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
				$view = 'home';
			} else {
				$found = url_to_postid( $url );
				if ( $found ) {
					$postId = $found;
				} else {
					return array( array( 'index' ), 0, "URL $url (could not map to a specific template; showing index)" );
				}
			}
		}

		if ( $pageId && ! $postId ) {
			$postId = $pageId;
		}

		if ( $postId ) {
			$post = get_post( $postId );
			if ( ! $post ) {
				throw new UserError( "No post or page with id $postId." );
			}
			$slug = $post->post_name;
			$type = $post->post_type;
			if ( $type === 'page' ) {
				$h = array();
				if ( get_option( 'show_on_front' ) === 'page' && (int) get_option( 'page_on_front' ) === $postId ) {
					$h[] = 'front-page';
				}
				$h = array_merge( $h, array( "page-$slug", "page-$postId", 'page', 'singular', 'index' ) );
				return array( $h, $postId, "page '{$post->post_title}'" );
			}
			$h = array( "single-$type-$slug", "single-$type", 'single', 'singular', 'index' );
			return array( $h, $postId, ucfirst( $type ) . " '{$post->post_title}'" );
		}

		$showsPosts = get_option( 'show_on_front' ) !== 'page';

		if ( $view === 'front' || $view === 'front-page' ) {
			if ( ! $showsPosts ) {
				$frontId = (int) get_option( 'page_on_front' );
				if ( $frontId ) {
					return $this->resolve( array( 'pageId' => $frontId ) );
				}
			}
			return array( array( 'front-page', 'home', 'index' ), 0, 'front page (latest posts)' );
		}

		// Default and view "home": the blog posts index. When posts are the front page, the
		// front-page template wins first; when a static page is the front, the posts index is `home`.
		$h = $showsPosts ? array( 'front-page', 'home', 'index' ) : array( 'home', 'index' );
		return array( $h, 0, 'blog home (latest posts)' );
	}

	/** First template whose slug matches the hierarchy; custom (DB) templates already override theme ones. */
	private function pickTemplate( array $hierarchy ): ?\WP_Block_Template {
		$all = get_block_templates( array(), 'wp_template' );
		foreach ( $hierarchy as $slug ) {
			foreach ( $all as $t ) {
				if ( $t->slug === $slug ) {
					return $t;
				}
			}
		}
		return null;
	}

	/** Replace the template's `wp:post-content` placeholder with the post's own (expanded) blocks. */
	private function fillPostContent( string $markup, int $postId ): string {
		$post = get_post( $postId );
		if ( ! $post ) {
			return $markup;
		}
		$region = BlockMarkup::region( 'post', (string) $postId, "Content of '{$post->post_title}'", BlockMarkup::expand( (string) $post->post_content ) );
		$fill   = static fn(): string => $region; // callback avoids $-backreference interpretation in the post content
		$out    = preg_replace_callback( '#<!--\s*wp:post-content\b.*?/-->#s', $fill, $markup, 1, $count );
		if ( ! $count ) {
			$out = preg_replace_callback( '#<!--\s*wp:post-content\b.*?/wp:post-content\s*-->#s', $fill, $markup, 1, $count );
		}
		return $count ? (string) $out : $markup;
	}
}
