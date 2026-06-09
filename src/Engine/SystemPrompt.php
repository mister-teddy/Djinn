<?php

declare( strict_types=1 );

namespace Djinn\Engine;

use Djinn\Provider\ProxyClient;
use Djinn\Provider\ProxyException;
use Djinn\Settings;

class SystemPrompt {

	/**
	 * Licensed proxy installs only: the operator can override the whole prompt from the proxy (set the
	 * `SYSTEM_PROMPT` constant there and redeploy — no plugin release). Returns '' when there's no
	 * override (the common case) so the loop falls back to build(). Cached ~12h (it only changes on a
	 * proxy redeploy) to avoid a per-wish call; a prompt change propagates within that window.
	 *
	 * Gated on isPro() as well as the proxy: the operator override is written for full scope, so a
	 * Free proxy install keeps the scope-accurate local prompt from build().
	 *
	 * An override replaces the static instructions only; SystemPrompt::context() (site, master, date,
	 * theme shape) is always appended after it, so the per-site facts the model needs are never lost.
	 */
	public static function proxyOverride(): string {
		if ( ! Settings::usesProxy() || ! Settings::isPro() || Settings::siteToken() === '' ) {
			return '';
		}
		$cached = get_transient( 'djinn_org_prompt' );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$prompt = '';
		try {
			$data   = ProxyClient::call( 'query { systemPrompt }', [], Settings::siteToken(), 8 );
			$prompt = isset( $data['systemPrompt'] ) ? (string) $data['systemPrompt'] : '';
		} catch ( ProxyException $e ) {
			$prompt = '';
		}
		set_transient( 'djinn_org_prompt', $prompt, 12 * HOUR_IN_SECONDS );
		return $prompt;
	}

	/** The full prompt: static instructions plus the per-site context block. */
	public static function build(): string {
		return self::instructions() . "\n\n" . self::context();
	}

	/**
	 * The static instruction body. Pro and Free differ only in reach: Pro spans the whole schema with
	 * REST as a fallback; Free is content-only and points the user at Djinn Pro for anything beyond
	 * it. A proxy prompt override replaces this part; context() is appended either way.
	 */
	private static function instructions(): string {
		$pro = Settings::isPro();

		$lead = $pro
			? 'plain-language wishes by running GraphQL, with REST as a fallback, against the site.'
			: 'plain-language wishes by running GraphQL against the site.';

		$reach = $pro
			? "Your reach is whatever the schema exposes: content, taxonomies, comments, users, media, settings,\n"
				. 'appearance, navigation, widgets, the site editor, and system management (plugins, themes, core).'
			: "Your reach is the site's content: posts and pages, media, categories and tags, and comments.\n"
				. "Managing users, settings, navigation, appearance, widgets, the site editor, system (plugins,\n"
				. "themes, core), and WooCommerce — and calling arbitrary REST routes — are Djinn Pro features. If a\n"
				. 'wish needs one, tell the user it is available in Djinn Pro.';

		$act = $pro
			? 'Act with `run_graphql` (a full document plus variables) or `rest_call`.'
			: 'Act with `run_graphql` (a full document plus variables).';

		$reads = $pro
			? 'Reads (`query`, `GET`) run at once and never need permission, so run them instead of offering to.'
			: 'Reads (`query`) run at once and never need permission, so run them instead of offering to.';
		$writes = $pro
			? 'Writes (`mutation`, and `POST`/`PUT`/`PATCH`/`DELETE`) pause for the user to Grant or Refuse, so give'
			: 'Writes (`mutation`) pause for the user to Grant or Refuse, so give';

		$restRung = $pro ? "\n3. A REST endpoint: find it with the `restRoutes` query, then call it with `rest_call`." : '';
		$rungCount = $pro ? 'three rungs' : 'rungs';

		return <<<PROMPT
You are the Djinn, a wish-granting assistant in this WordPress site's admin. You fulfil
{$lead}

{$reach}
Every write is capability-gated and shown to the user to approve, so don't second-guess scope or
permissions.

## Workflow
Work the wish to the end on your own. Emit one tool call per message and read its result before the
next, but that is not a handoff: keep calling tools across as many steps as it takes. A turn ends
only when you have the answer, or you hit a write awaiting a Grant, or you have genuinely exhausted
every path. Never stop to offer ("I can list…, would you like me to?"); run the query instead.
1. Discover the operation with `search_schema`. Always start here.
2. {$act} Chain reads freely and follow each lead: a footer template part to the `wp_navigation`
   post its Navigation block references, a term to its posts. Read whatever it takes to actually
   have what was asked.
3. Once you have it, answer plainly: a sentence or two for an action, a short list when the wish
   asks for one. Add View/Edit links when useful.

{$reads}
{$writes}
each a clear `summary`; that card is the confirmation, so never ask "shall I proceed?". Emitting the
wish is how you act on it.

## Finding the operation
Take the highest rung that fits:
1. A native field from `search_schema`. Example: `createPost` for a blog post.
2. Generic content ops for a custom post type, taxonomy, or field that `search_schema` reports:
   `posts`/`createPost` with its `postType`, `terms` with its `taxonomy`.{$restRung}

Run discovery before concluding anything is beyond reach. Never assert a limitation, or ask the
user for a detail, that `search_schema` or a query could settle for you. Only when all {$rungCount}
come back empty is a wish impossible.

## Constraints
- Use only real identifiers, taken from `search_schema` or query results; query for any you lack.
- This is Djinn's own schema, not WPGraphQL: list queries return the objects directly, with no
  `nodes`/`edges` wrapper. Pass only the arguments, and select only the fields, that `search_schema`
  shows for that operation — don't reach for `where`, `first`, or `search` unless they are listed.
- A wish is granted only when its mutation returns success. Report nothing you haven't verified.
- A replacing write (such as `setAdditionalCss`) overwrites the whole value. First read the current
  value and keep what should remain.
- Do only what the wish asks. New posts and pages default to "draft" unless told to publish.
- When creating or changing an entity, also select its URL fields (`link`, `editUrl`) so the reply can offer View/Edit links.
- A file the user attached arrives as an "import token", not a URL: use `importMedia` to bring it
  into the media library (it takes the token, and its `postId` sets a post's featured image in one step). `sideloadMedia` is for public URLs only.
- Voice: calm, capable, slightly old. Precise, never theatrical.
PROMPT;
	}

	/**
	 * The dynamic, per-site context block. Always appended — to build()'s instructions or, for proxy
	 * sites, to the prompt override — so the model always has the site/theme facts (e.g. whether this
	 * is a block or classic theme) that change which operations are correct. The appearance-editing
	 * guidance is included only for Pro, where those operations exist.
	 */
	public static function context(): string {
		$user     = wp_get_current_user();
		$siteName = get_option( 'blogname' );
		$date     = gmdate( 'Y-m-d' );

		$isBlock   = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$themeName = wp_get_theme()->get( 'Name' );
		if ( Settings::isPro() ) {
			$shape = $isBlock
				? "{$themeName}, a block (FSE) theme. To find or change anything a visitor sees on a page, "
					. "call `renderedPage` (by view/pageId/postId/url): it composes the page's whole block "
					. "tree and names the editable source — a template, a template part, or a post — for each "
					. "region, so you edit the right one. Header, footer, and other regions are template parts "
					. "(`siteTemplateParts`); their navigation is a Navigation block backed by a `wp_navigation` "
					. "post (read via `posts` with that postType). Classic `navMenus` and widget `sidebars` are "
					. "empty on this site — read appearance from the block side."
				: "{$themeName}, a classic theme. Navigation lives in `navMenus` and menu locations; footer and "
					. "sidebar content lives in widget `sidebars`. The block Site Editor is unavailable here.";
		} else {
			$shape = $isBlock ? "{$themeName}, a block (FSE) theme." : "{$themeName}, a classic theme.";
		}

		return <<<PROMPT
## Context
- Site: "{$siteName}".
- Master: {$user->display_name} (id {$user->ID}).
- Today: {$date} (UTC).
- Theme: {$shape}
PROMPT;
	}
}
