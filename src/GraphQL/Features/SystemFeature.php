<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Automatic_Upgrader_Skin;
use Core_Upgrader;
use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Plugin_Upgrader;
use Theme_Upgrader;

/**
 * System management: activate/deactivate plugins & themes, install them from the WordPress.org
 * repository, and update plugins/themes/core. These touch the filesystem and network, so they are
 * the most powerful wishes — every one is capability-gated and (being mutations) paused for Grant.
 */
class SystemFeature implements Feature {

	public function register( Registry $r ): void {
		$plugin = new ObjectType(
			array(
				'name'        => 'Plugin',
				'description' => 'An installed plugin.',
				'fields'      => array(
					'file'      => array(
						'type'        => Type::id(),
						'description' => 'Plugin file path (e.g. akismet/akismet.php) — use this to activate/update.',
					),
					'name'      => array( 'type' => Type::string() ),
					'version'   => array( 'type' => Type::string() ),
					'active'    => array( 'type' => Type::boolean() ),
					'hasUpdate' => array( 'type' => Type::boolean() ),
				),
			)
		);
		$r->setType( 'Plugin', $plugin );

		$updates = new ObjectType(
			array(
				'name'        => 'Updates',
				'description' => 'Pending updates across the site.',
				'fields'      => array(
					'coreCurrent'   => array(
						'type'        => Type::string(),
						'description' => 'Installed WordPress version.',
					),
					'coreAvailable' => array(
						'type'        => Type::string(),
						'description' => 'Newer core version available, or null.',
					),
					'plugins'       => array(
						'type'        => Type::int(),
						'description' => 'Plugins with updates.',
					),
					'themes'        => array(
						'type'        => Type::int(),
						'description' => 'Themes with updates.',
					),
				),
			)
		);

		$r->addQuery(
			'plugins',
			array(
				'type'        => Type::listOf( $plugin ),
				'description' => 'List installed plugins.',
				'resolve'     => array( $this, 'plugins' ),
			)
		);
		$r->addQuery(
			'availableUpdates',
			array(
				'type'        => $updates,
				'description' => 'Pending core/plugin/theme updates.',
				'resolve'     => array( $this, 'availableUpdates' ),
			)
		);

		$r->addMutation(
			'activatePlugin',
			array(
				'type'    => Type::boolean(),
				'args'    => array( 'file' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve' => array( $this, 'activatePlugin' ),
			)
		);
		$r->addMutation(
			'deactivatePlugin',
			array(
				'type'    => Type::boolean(),
				'args'    => array( 'file' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve' => array( $this, 'deactivatePlugin' ),
			)
		);
		$r->addMutation(
			'installPlugin',
			array(
				'type'        => Type::boolean(),
				'description' => 'Install a plugin from the WordPress.org repository by slug (e.g. "classic-editor").',
				'args'        => array(
					'slug'     => array( 'type' => Type::nonNull( Type::string() ) ),
					'activate' => array(
						'type'         => Type::boolean(),
						'defaultValue' => false,
					),
				),
				'resolve'     => array( $this, 'installPlugin' ),
			)
		);
		$r->addMutation(
			'updatePlugin',
			array(
				'type'    => Type::boolean(),
				'args'    => array( 'file' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve' => array( $this, 'updatePlugin' ),
			)
		);
		$r->addMutation(
			'deletePlugin',
			array(
				'type'        => Type::boolean(),
				'description' => 'Delete an installed plugin by file. It must be deactivated first.',
				'args'        => array( 'file' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve'     => array( $this, 'deletePlugin' ),
			)
		);
		$r->addMutation(
			'installTheme',
			array(
				'type'        => Type::boolean(),
				'description' => 'Install a theme from the WordPress.org repository by slug.',
				'args'        => array( 'slug' => array( 'type' => Type::nonNull( Type::string() ) ) ),
				'resolve'     => array( $this, 'installTheme' ),
			)
		);
		$r->addMutation(
			'updateTheme',
			array(
				'type'    => Type::boolean(),
				'args'    => array( 'slug' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve' => array( $this, 'updateTheme' ),
			)
		);
		$r->addMutation(
			'deleteTheme',
			array(
				'type'        => Type::boolean(),
				'description' => 'Delete an installed theme by slug. It must not be the active theme.',
				'args'        => array( 'slug' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve'     => array( $this, 'deleteTheme' ),
			)
		);
		$r->addMutation(
			'updateCore',
			array(
				'type'        => Type::string(),
				'description' => 'Update WordPress core to the latest available version. Returns the version updated to.',
				'resolve'     => array( $this, 'updateCore' ),
			)
		);
	}

	// ---- Reads -------------------------------------------------------------

	/** @return array<int,array<string,mixed>> */
	public function plugins(): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			throw new UserError( 'You do not have permission to view plugins.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$updates = array_keys( get_plugin_updates() );
		$out     = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[] = array(
				'file'      => $file,
				'name'      => $data['Name'] ?? $file,
				'version'   => $data['Version'] ?? '',
				'active'    => is_plugin_active( $file ),
				'hasUpdate' => in_array( $file, $updates, true ),
			);
		}
		return $out;
	}

	public function availableUpdates(): array {
		if ( ! current_user_can( 'update_core' ) ) {
			throw new UserError( 'You do not have permission to view updates.' );
		}
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();

		$coreAvailable = null;
		foreach ( (array) get_core_updates() as $update ) {
			if ( isset( $update->response ) && $update->response === 'upgrade' ) {
				$coreAvailable = $update->current;
				break;
			}
		}
		return array(
			'coreCurrent'   => get_bloginfo( 'version' ),
			'coreAvailable' => $coreAvailable,
			'plugins'       => count( get_plugin_updates() ),
			'themes'        => count( get_theme_updates() ),
		);
	}

	// ---- Activate / deactivate --------------------------------------------

	/** @param array<string,mixed> $args */
	public function activatePlugin( $root, array $args ): bool {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			throw new UserError( 'You do not have permission to activate plugins.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$result = activate_plugin( (string) $args['file'] );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return true;
	}

	/** @param array<string,mixed> $args */
	public function deactivatePlugin( $root, array $args ): bool {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			throw new UserError( 'You do not have permission to deactivate plugins.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( array( (string) $args['file'] ) );
		return true;
	}

	// ---- Install / update from the repo -----------------------------------

	/** @param array<string,mixed> $args */
	public function installPlugin( $root, array $args ): bool {
		if ( ! current_user_can( 'install_plugins' ) ) {
			throw new UserError( 'You do not have permission to install plugins.' );
		}
		$this->bootstrapUpgrader();
		$slug = sanitize_key( (string) $args['slug'] );
		$api  = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			throw new UserError( "Couldn't find plugin '$slug' on WordPress.org: " . $api->get_error_message() );
		}
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		if ( ! $result ) {
			throw new UserError( 'Plugin installation failed: ' . implode( ' ', $upgrader->skin->get_upgrade_messages() ) );
		}
		if ( ! empty( $args['activate'] ) ) {
			$file = $upgrader->plugin_info();
			if ( $file ) {
				activate_plugin( $file );
			}
		}
		return true;
	}

	/** @param array<string,mixed> $args */
	public function updatePlugin( $root, array $args ): bool {
		if ( ! current_user_can( 'update_plugins' ) ) {
			throw new UserError( 'You do not have permission to update plugins.' );
		}
		$this->bootstrapUpgrader();
		wp_update_plugins();
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( (string) $args['file'] );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return (bool) $result;
	}

	/** @param array<string,mixed> $args */
	public function installTheme( $root, array $args ): bool {
		if ( ! current_user_can( 'install_themes' ) ) {
			throw new UserError( 'You do not have permission to install themes.' );
		}
		$this->bootstrapUpgrader();
		$slug = sanitize_key( (string) $args['slug'] );
		$api  = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			throw new UserError( "Couldn't find theme '$slug' on WordPress.org: " . $api->get_error_message() );
		}
		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return (bool) $result;
	}

	/** @param array<string,mixed> $args */
	public function updateTheme( $root, array $args ): bool {
		if ( ! current_user_can( 'update_themes' ) ) {
			throw new UserError( 'You do not have permission to update themes.' );
		}
		$this->bootstrapUpgrader();
		wp_update_themes();
		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( (string) $args['slug'] );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return (bool) $result;
	}

	/** @param array<string,mixed> $args */
	public function deletePlugin( $root, array $args ): bool {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			throw new UserError( 'You do not have permission to delete plugins.' );
		}
		$this->bootstrapUpgrader();
		$file = (string) $args['file'];
		if ( is_plugin_active( $file ) ) {
			throw new UserError( 'Deactivate the plugin before deleting it.' );
		}
		$result = delete_plugins( array( $file ) );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return (bool) $result;
	}

	/** @param array<string,mixed> $args */
	public function deleteTheme( $root, array $args ): bool {
		if ( ! current_user_can( 'delete_themes' ) ) {
			throw new UserError( 'You do not have permission to delete themes.' );
		}
		$this->bootstrapUpgrader();
		$slug = (string) $args['slug'];
		if ( get_stylesheet() === $slug || get_template() === $slug ) {
			throw new UserError( 'You cannot delete the active theme.' );
		}
		$result = delete_theme( $slug );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return (bool) $result;
	}

	public function updateCore(): string {
		if ( ! current_user_can( 'update_core' ) ) {
			throw new UserError( 'You do not have permission to update WordPress core.' );
		}
		$this->bootstrapUpgrader();
		require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		wp_version_check();

		$update = null;
		foreach ( (array) get_core_updates() as $candidate ) {
			if ( isset( $candidate->response ) && $candidate->response === 'upgrade' ) {
				$update = $candidate;
				break;
			}
		}
		if ( ! $update ) {
			throw new UserError( 'WordPress core is already up to date.' );
		}

		$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $update );
		if ( is_wp_error( $result ) ) {
			throw new UserError( $result->get_error_message() );
		}
		return (string) $update->current;
	}

	/** Load WordPress's upgrader stack and a writable filesystem, or fail clearly. */
	private function bootstrapUpgrader(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
		if ( ! \WP_Filesystem() ) {
			throw new UserError( 'Could not get filesystem access to install/update. The server may need FTP credentials or direct write access.' );
		}
	}
}
