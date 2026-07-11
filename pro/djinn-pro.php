<?php
/**
 * Plugin Name:       Djinn Pro
 * Plugin URI:        https://github.com/mister-teddy/Djinn
 * Description:       Paid capability add-on for Djinn. Requires the base Djinn plugin.
 * Version:           0.7.6
 * Requires PHP:      7.4
 * Requires at least: 5.9
 * Requires Plugins:  djinn
 * Author:            Djinn
 * License:           GPL-2.0-or-later
 * Text Domain:       djinn-pro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DJINN_PRO_VERSION', '0.7.6' );
define( 'DJINN_PRO_FILE', __FILE__ );
define( 'DJINN_PRO_DIR', plugin_dir_path( __FILE__ ) );

add_action( 'plugins_loaded', 'djinn_pro_boot', 20 );

function djinn_pro_boot(): void {
	if ( ! defined( 'DJINN_VERSION' ) || ! class_exists( \Djinn\Plugin::class ) ) {
		add_action( 'admin_notices', 'djinn_pro_missing_base_notice' );
		return;
	}

	spl_autoload_register( 'djinn_pro_autoload' );

	add_filter( 'djinn_is_pro', '__return_true' );
	add_action( 'djinn_register_schema', 'djinn_pro_register_schema' );
	add_filter( 'djinn_tool_specs', 'djinn_pro_tool_specs' );
	add_filter( 'djinn_execute_rest_call', 'djinn_pro_execute_rest_call', 10, 5 );
}

function djinn_pro_missing_base_notice(): void {
	echo '<div class="notice notice-error"><p><strong>Djinn Pro:</strong> install and activate the base Djinn plugin first.</p></div>';
}

function djinn_pro_autoload( string $class ): void {
	$prefixes = array(
		'Djinn\\GraphQL\\Features\\' => DJINN_PRO_DIR . 'src/GraphQL/Features/',
		'Djinn\\Engine\\'            => DJINN_PRO_DIR . 'src/Engine/',
	);

	foreach ( $prefixes as $prefix => $base ) {
		if ( strpos( $class, $prefix ) !== 0 ) {
			continue;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
}

/** @param \Djinn\GraphQL\Registry $reg */
function djinn_pro_register_schema( $reg ): void {
	djinn_pro_register_core_mutations( $reg );

	$features = array(
		new \Djinn\GraphQL\Features\MetaFeature(),
		new \Djinn\GraphQL\Features\AppearanceFeature(),
		new \Djinn\GraphQL\Features\MenusFeature(),
		new \Djinn\GraphQL\Features\WidgetsFeature(),
		new \Djinn\GraphQL\Features\CustomizerFeature(),
		new \Djinn\GraphQL\Features\SiteEditorFeature(),
		new \Djinn\GraphQL\Features\PageStructureFeature(),
		new \Djinn\GraphQL\Features\UsersFeature(),
		new \Djinn\GraphQL\Features\SettingsFeature(),
		new \Djinn\GraphQL\Features\SystemFeature(),
		new \Djinn\GraphQL\Features\ToolsFeature(),
		new \Djinn\GraphQL\Features\CronFeature(),
		new \Djinn\GraphQL\Features\RestFeature(),
	);

	if ( \Djinn\GraphQL\Features\WooCommerceFeature::isActive() ) {
		$features[] = new \Djinn\GraphQL\Features\WooCommerceFeature();
	}

	$features = (array) apply_filters( 'djinn_pro_schema_features', $features );
	foreach ( $features as $feature ) {
		if ( ! $feature instanceof \Djinn\GraphQL\Feature ) {
			continue;
		}
		$reg->setCurrentDomain( djinn_pro_domain_label( $feature ) );
		$feature->register( $reg );
	}
}

/** @param \Djinn\GraphQL\Registry $reg */
function djinn_pro_register_core_mutations( $reg ): void {
	$reg->setCurrentDomain( 'Core' );
	$reg->addMutation(
		'updateOption',
		array(
			'type'        => \GraphQL\Type\Definition\Type::boolean(),
			'description' => 'Set a wp_options value. Value is a string (use JSON for non-scalar values).',
			'args'        => array(
				'name'  => array( 'type' => \GraphQL\Type\Definition\Type::nonNull( \GraphQL\Type\Definition\Type::string() ) ),
				'value' => array( 'type' => \GraphQL\Type\Definition\Type::nonNull( \GraphQL\Type\Definition\Type::string() ) ),
			),
			'resolve'     => 'djinn_pro_update_option',
		)
	);
	$reg->addMutation(
		'updateSiteInfo',
		array(
			'type'    => \GraphQL\Type\Definition\Type::boolean(),
			'args'    => array(
				'title'       => array( 'type' => \GraphQL\Type\Definition\Type::string() ),
				'description' => array( 'type' => \GraphQL\Type\Definition\Type::string() ),
			),
			'resolve' => 'djinn_pro_update_site_info',
		)
	);
}

/** @param array<string,mixed> $args */
function djinn_pro_update_option( $root, array $args ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		throw new \GraphQL\Error\UserError( esc_html( 'You do not have permission to change options.' ) );
	}
	return (bool) update_option( $args['name'], $args['value'] );
}

/** @param array<string,mixed> $args */
function djinn_pro_update_site_info( $root, array $args ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		throw new \GraphQL\Error\UserError( esc_html( 'You do not have permission to change site settings.' ) );
	}
	if ( isset( $args['title'] ) ) {
		update_option( 'blogname', $args['title'] );
	}
	if ( isset( $args['description'] ) ) {
		update_option( 'blogdescription', $args['description'] );
	}
	return true;
}

function djinn_pro_domain_label( \Djinn\GraphQL\Feature $feature ): string {
	$short = ( new \ReflectionClass( $feature ) )->getShortName();
	$short = (string) preg_replace( '/Feature$/', '', $short );
	return trim( (string) preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', $short ) );
}

/**
 * @param array<int,array<string,mixed>> $specs
 * @return array<int,array<string,mixed>>
 */
function djinn_pro_tool_specs( array $specs ): array {
	$specs[] = array(
		'name'        => 'rest_call',
		'description' => 'Call a WordPress REST route - the escape hatch for plugins with no native GraphQL field. Discover routes first via the `restRoutes` GraphQL query. GET/HEAD run immediately; POST/PUT/PATCH/DELETE are paused for the user to Grant. Each route enforces its own permissions.',
		'parameters'  => array(
			'type'       => 'object',
			'properties' => array(
				'method'  => array(
					'type'        => 'string',
					'description' => 'HTTP method: GET, POST, PUT, PATCH, or DELETE.',
				),
				'path'    => array(
					'type'        => 'string',
					'description' => 'REST route path beginning with "/", e.g. "/wp/v2/pages" or "/wc/v3/products/12".',
				),
				'body'    => array(
					'type'        => 'object',
					'description' => 'Body parameters for writes. Omit for reads.',
				),
				'params'  => array(
					'type'        => 'object',
					'description' => 'Query-string parameters, e.g. {"per_page": 5, "search": "hat"}. Omit if none.',
				),
				'summary' => array(
					'type'        => 'string',
					'description' => 'For writes: a one-sentence plain-language description, shown on the confirmation card.',
				),
			),
			'required'   => array( 'method', 'path' ),
		),
	);
	return $specs;
}

/**
 * @param mixed               $result
 * @param array<string,mixed> $body
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function djinn_pro_execute_rest_call( $result, string $path, string $method, array $body, array $params ): array {
	if ( is_array( $result ) ) {
		return $result;
	}
	return \Djinn\Engine\RestRunner::execute( $path, $method, $body, $params );
}
