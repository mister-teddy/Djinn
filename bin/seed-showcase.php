<?php
/**
 * Seed showcase conversations into the Lamp for product screenshots.
 *
 * Run inside wp-env:  npx wp-env run cli wp eval-file wp-content/plugins/Djinn/bin/seed-showcase.php
 *
 * Drift-free by design: it writes RAW chat messages in the exact shape Engine\AgentLoop persists,
 * so the live transcript() + React UI render them identically — no mock components to fall out of
 * date. No LLM calls are made; the conversation text is authored here. It flips the two UI flags a
 * clean screenshot needs (configured + indexed) without contacting any provider.
 *
 * Showcase-only: it truncates existing Djinn conversations first, so run it on a throwaway site.
 */

use Djinn\Store\Repository;

if ( ! class_exists( Repository::class ) ) {
	fwrite( STDERR, "Djinn is not active on this site.\n" );
	return;
}

global $wpdb;

// --- 1. Make the UI render the chat (configured) with no slumber notice (indexed) -------------
$opt = get_option( 'djinn_settings', [] );
$opt = is_array( $opt ) ? $opt : [];
if ( empty( $opt['api_key'] ) && empty( $opt['site_token'] ) ) {
	$opt['provider'] = $opt['provider'] ?? 'openai';
	$opt['api_key']  = 'showcase-demo-key'; // only flips isConfigured(); no calls are ever made
	update_option( 'djinn_settings', $opt );
}
$chunks = $wpdb->prefix . 'djinn_schema_chunks';
if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $chunks" ) === 0 ) {
	$wpdb->insert( $chunks, [
		'name'       => 'showcase',
		'fragment'   => 'showcase placeholder',
		'embedding'  => '[]',
		'model'      => 'showcase',
		'updated_at' => current_time( 'mysql', true ),
	] );
}

// --- 2. Clean slate (showcase env only) -------------------------------------------------------
foreach ( [ 'djinn_chats', 'djinn_messages', 'djinn_pending', 'djinn_usage' ] as $t ) {
	$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . $t ); // phpcs:ignore
}

$admin = get_user_by( 'login', 'admin' );
$uid   = $admin ? (int) $admin->ID : 1;

$base = home_url();

/**
 * Build one conversation from a compact turn list, then attach a usage total so the meter shows.
 * Turn kinds:
 *   ['user', text]
 *   ['action', operation, variables, resultArray, summary?]   one run_graphql call + its result
 *   ['reply', markdown]                                       assistant final text
 *   ['pending', operation, variables, summary]                run_graphql mutation awaiting a Grant
 */
$seed = static function ( int $uid, string $title, array $turns, array $usage ) use ( $base ) {
	$cid = Repository::createChat( $uid, $title );
	$n   = 0;
	foreach ( $turns as $turn ) {
		$kind = $turn[0];
		$id   = 'show_' . $cid . '_' . ( ++$n );

		if ( $kind === 'user' ) {
			Repository::addMessage( $cid, [ 'role' => 'user', 'content' => $turn[1] ] );
		} elseif ( $kind === 'reply' ) {
			Repository::addMessage( $cid, [ 'role' => 'assistant', 'content' => $turn[1] ] );
		} elseif ( $kind === 'action' ) {
			$args = [ 'operation' => $turn[1], 'variables' => $turn[2] ];
			if ( isset( $turn[4] ) ) {
				$args['summary'] = $turn[4];
			}
			Repository::addMessage( $cid, [
				'role'       => 'assistant',
				'content'    => null,
				'tool_calls' => [ [ 'id' => $id, 'name' => 'run_graphql', 'arguments' => $args ] ],
			] );
			Repository::addMessage( $cid, [
				'role'         => 'tool',
				'tool_call_id' => $id,
				'name'         => 'run_graphql',
				'content'      => wp_json_encode( $turn[3] ),
			] );
		} elseif ( $kind === 'pending' ) {
			Repository::addMessage( $cid, [
				'role'       => 'assistant',
				'content'    => null,
				'tool_calls' => [ [ 'id' => $id, 'name' => 'run_graphql', 'arguments' => [ 'operation' => $turn[1], 'variables' => $turn[2], 'summary' => $turn[3] ] ] ],
			] );
			Repository::createPending( $cid, $id, 'graphql', $turn[1], $turn[2], $turn[3] );
		}
	}
	Repository::recordUsage( [
		'user_id'           => $uid,
		'chat_id'           => $cid,
		'provider'          => 'gemini',
		'model'             => 'gemini-2.5-flash-lite',
		'kind'              => 'chat',
		'prompt_tokens'     => $usage[0],
		'completion_tokens' => $usage[1],
		'estimated'         => 0,
		'cost'              => $usage[2],
	] );
	return $cid;
};

// --- 3. The five showcase conversations -------------------------------------------------------

// 1) Content: write & publish a post — shows the incantation card with View/Edit link chips.
$seed( $uid, 'Write and publish a welcome post for the bakery', [
	[ 'user', 'Write and publish a short, warm welcome post for our new bakery, Sourdough & Co.' ],
	[ 'action',
		'mutation($input: PostInput!) { createPost(input: $input) { id title status link editUrl } }',
		[ 'input' => [ 'title' => 'Welcome to Sourdough & Co.', 'status' => 'publish', 'content' => '...' ] ],
		[ 'data' => [ 'createPost' => [
			'id'      => '142',
			'title'   => 'Welcome to Sourdough & Co.',
			'status'  => 'publish',
			'link'    => $base . '/?p=142',
			'editUrl' => $base . '/wp-admin/post.php?post=142&action=edit',
		] ] ],
		'Publish the post “Welcome to Sourdough & Co.”',
	],
	[ 'reply', "Granted. **Welcome to Sourdough & Co.** is live.\n\nIt opens warmly, invites readers in for a fresh loaf, and closes with your hours. It's published, so it's already on your site — tap **View** to see it." ],
], [ 1486, 232, 0.00031 ] );

// 2) Plugins: a wish paused for approval — the Grant card (human-in-the-loop) with the incantation.
$seed( $uid, 'Install and activate Yoast SEO', [
	[ 'user', 'Install and activate the Yoast SEO plugin.' ],
	[ 'pending',
		'mutation { installPlugin(slug: "wordpress-seo", activate: true) }',
		[],
		'Install and activate Yoast SEO from the WordPress.org repository.',
	],
], [ 980, 64, 0.00019 ] );

// 3) Settings: set the homepage — shows breadth into core settings + a markdown table reply.
$seed( $uid, 'Make About my homepage and blog at News', [
	[ 'user', 'Make my About page the homepage, and show the blog on the News page.' ],
	[ 'action',
		'mutation { setHomepage(mode: "page", pageId: "12", postsPageId: "34") }',
		[],
		[ 'data' => [ 'setHomepage' => true ] ],
		'Set the front page to “About” and the posts page to “News”.',
	],
	[ 'reply', "Done — your front page now shows **About**, and the blog lives on **News**.\n\n| Setting | Now |\n| --- | --- |\n| Front page | About (static) |\n| Posts page | News |\n\nVisitors will land on About first." ],
], [ 1602, 198, 0.00033 ] );

// 4) Commerce: WooCommerce product — shows curated plugin reach.
$seed( $uid, "Add the product Aladdin's Brass Lamp", [
	[ 'user', "Add a product called “Aladdin's Brass Lamp”, priced $49, with 12 in stock." ],
	[ 'action',
		'mutation { createProduct(name: "Aladdin\'s Brass Lamp", regularPrice: "49", stockQuantity: 12, status: "publish") { id name price stockStatus permalink } }',
		[],
		[ 'data' => [ 'createProduct' => [
			'id'          => '210',
			'name'        => "Aladdin's Brass Lamp",
			'price'       => '49',
			'stockStatus' => 'instock',
			'permalink'   => $base . '/?product=aladdins-brass-lamp',
		] ] ],
		"Create the product “Aladdin's Brass Lamp” at $49, 12 in stock.",
	],
	[ 'reply', "**Aladdin's Brass Lamp** is in your shop — **\$49.00**, 12 in stock, published. [View it ↗](" . $base . "/?product=aladdins-brass-lamp)" ],
], [ 1521, 176, 0.00031 ] );

// 5) Diagnostics: a read query with a rich markdown table.
$seed( $uid, 'Is my site healthy and up to date?', [
	[ 'user', 'Is my site healthy and up to date?' ],
	[ 'action',
		'query { siteHealth { phpVersion wpVersion httpsEnabled pendingUpdates } }',
		[],
		[ 'data' => [ 'siteHealth' => [
			'phpVersion'     => '8.2.12',
			'wpVersion'      => '6.5',
			'httpsEnabled'   => true,
			'pendingUpdates' => 3,
		] ] ],
	],
	[ 'reply', "Your site is in good shape:\n\n| Check | Status |\n| --- | --- |\n| WordPress | 6.5 |\n| PHP | 8.2.12 |\n| HTTPS | Enabled |\n| Pending updates | **3** |\n\nThe one thing I'd tend to is the **3 pending updates** — say the word and I'll apply them." ],
], [ 1120, 210, 0.00026 ] );

$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'djinn_chats' );
fwrite( STDOUT, "Seeded $count showcase conversations for user #$uid.\n" );
