<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Navigation menus (Appearance → Menus): list menus and their items, see theme menu locations,
 * create menus, add/remove items, and assign a menu to a location. Every operation gates on
 * edit_theme_options, the capability the Menus screen itself requires.
 */
class MenusFeature implements Feature {

	public function register( Registry $r ): void {
		$item = new ObjectType(
			array(
				'name'        => 'NavMenuItem',
				'description' => 'An entry in a navigation menu.',
				'fields'      => array(
					'id'       => array( 'type' => Type::id() ),
					'title'    => array( 'type' => Type::string() ),
					'url'      => array( 'type' => Type::string() ),
					'type'     => array(
						'type'        => Type::string(),
						'description' => 'custom, post_type, or taxonomy.',
					),
					'object'   => array(
						'type'        => Type::string(),
						'description' => 'e.g. page, category — the linked object kind.',
					),
					'objectId' => array(
						'type'        => Type::id(),
						'description' => 'ID of the linked post/term, when not a custom link.',
					),
					'parentId' => array(
						'type'        => Type::id(),
						'description' => 'Parent menu-item id (for sub-items), or null.',
					),
					'order'    => array( 'type' => Type::int() ),
				),
			)
		);
		$r->setType( 'NavMenuItem', $item );

		$menu = new ObjectType(
			array(
				'name'        => 'NavMenu',
				'description' => 'A navigation menu.',
				'fields'      => array(
					'id'        => array( 'type' => Type::id() ),
					'name'      => array( 'type' => Type::string() ),
					'slug'      => array( 'type' => Type::string() ),
					'count'     => array(
						'type'        => Type::int(),
						'description' => 'Number of items in the menu.',
					),
					'locations' => array(
						'type'        => Type::listOf( Type::string() ),
						'description' => 'Theme location slugs this menu is assigned to.',
					),
				),
			)
		);
		$r->setType( 'NavMenu', $menu );

		$location = new ObjectType(
			array(
				'name'        => 'MenuLocation',
				'description' => 'A theme-registered navigation location a menu can be assigned to.',
				'fields'      => array(
					'location'       => array(
						'type'        => Type::string(),
						'description' => 'The location slug (pass to assignMenuLocation).',
					),
					'description'    => array( 'type' => Type::string() ),
					'assignedMenuId' => array(
						'type'        => Type::id(),
						'description' => 'The menu currently in this location, or null.',
					),
				),
			)
		);
		$r->setType( 'MenuLocation', $location );

		$r->addQuery(
			'navMenus',
			array(
				'type'        => Type::listOf( $menu ),
				'description' => 'List the site\'s navigation menus, or pass a theme location (e.g. "primary", "footer") to get the menu assigned there.',
				'args'        => array(
					'location' => array(
						'type'        => Type::string(),
						'description' => 'A theme location slug; returns only the menu assigned to it (empty if none).',
					),
				),
				'resolve'     => array( $this, 'navMenus' ),
			)
		);
		$r->addQuery(
			'navMenuItems',
			array(
				'type'        => Type::listOf( $item ),
				'description' => 'List the items in a navigation menu (by id, slug, or name).',
				'args'        => array( 'menu' => array( 'type' => Type::nonNull( Type::string() ) ) ),
				'resolve'     => array( $this, 'navMenuItems' ),
			)
		);
		$r->addQuery(
			'menuLocations',
			array(
				'type'        => Type::listOf( $location ),
				'description' => 'List the theme\'s registered menu locations and which menu fills each.',
				'resolve'     => array( $this, 'menuLocations' ),
			)
		);

		$r->addMutation(
			'createNavMenu',
			array(
				'type'        => $menu,
				'description' => 'Create a new (empty) navigation menu.',
				'args'        => array( 'name' => array( 'type' => Type::nonNull( Type::string() ) ) ),
				'resolve'     => array( $this, 'createNavMenu' ),
			)
		);
		$r->addMutation(
			'addNavMenuItem',
			array(
				'type'        => $item,
				'description' => 'Add an item to a menu. For a custom link pass url; to link a post/page/term pass type+object+objectId.',
				'args'        => array(
					'menu'     => array(
						'type'        => Type::nonNull( Type::string() ),
						'description' => 'Target menu id, slug, or name.',
					),
					'title'    => array( 'type' => Type::nonNull( Type::string() ) ),
					'url'      => array(
						'type'        => Type::string(),
						'description' => 'For a custom link.',
					),
					'type'     => array(
						'type'        => Type::string(),
						'description' => 'custom (default), post_type, or taxonomy.',
					),
					'object'   => array(
						'type'        => Type::string(),
						'description' => 'e.g. page or category, when linking an object.',
					),
					'objectId' => array(
						'type'        => Type::id(),
						'description' => 'The linked post/term id, when type is post_type/taxonomy.',
					),
					'parentId' => array(
						'type'        => Type::id(),
						'description' => 'Parent menu-item id, for a sub-item.',
					),
				),
				'resolve'     => array( $this, 'addNavMenuItem' ),
			)
		);
		$r->addMutation(
			'deleteNavMenuItem',
			array(
				'type'        => Type::boolean(),
				'description' => 'Remove a single item from a menu by its menu-item id.',
				'args'        => array( 'itemId' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve'     => array( $this, 'deleteNavMenuItem' ),
			)
		);
		$r->addMutation(
			'assignMenuLocation',
			array(
				'type'        => Type::boolean(),
				'description' => 'Assign a menu to a theme location (e.g. "primary").',
				'args'        => array(
					'menu'     => array( 'type' => Type::nonNull( Type::string() ) ),
					'location' => array( 'type' => Type::nonNull( Type::string() ) ),
				),
				'resolve'     => array( $this, 'assignMenuLocation' ),
			)
		);
	}

	private function gate(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to manage menus.' );
		}
	}

	/** @param \WP_Term $m */
	private function shapeMenu( $m ): array {
		$locations = array();
		foreach ( get_nav_menu_locations() as $loc => $menuId ) {
			if ( (int) $menuId === (int) $m->term_id ) {
				$locations[] = (string) $loc;
			}
		}
		return array(
			'id'        => (string) $m->term_id,
			'name'      => $m->name,
			'slug'      => $m->slug,
			'count'     => (int) $m->count,
			'locations' => $locations,
		);
	}

	/** @param \WP_Post $i */
	private function shapeItem( $i ): array {
		return array(
			'id'       => (string) $i->ID,
			'title'    => $i->title,
			'url'      => $i->url,
			'type'     => $i->type,
			'object'   => $i->object,
			'objectId' => $i->object_id ? (string) $i->object_id : null,
			'parentId' => $i->menu_item_parent ? (string) $i->menu_item_parent : null,
			'order'    => (int) $i->menu_order,
		);
	}

	private function menuOrFail( string $ref ): \WP_Term {
		$menu = wp_get_nav_menu_object( is_numeric( $ref ) ? (int) $ref : $ref );
		if ( ! $menu ) {
			throw new UserError( "No navigation menu matching '$ref'." );
		}
		return $menu;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	public function navMenus( $root = null, array $args = array() ): array {
		$this->gate();
		$location = isset( $args['location'] ) ? (string) $args['location'] : '';
		if ( $location !== '' ) {
			$menuId = get_nav_menu_locations()[ $location ] ?? 0;
			$menu   = $menuId ? wp_get_nav_menu_object( (int) $menuId ) : false;
			return $menu ? array( $this->shapeMenu( $menu ) ) : array();
		}
		return array_map( array( $this, 'shapeMenu' ), wp_get_nav_menus() );
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	public function navMenuItems( $root, array $args ): array {
		$this->gate();
		$menu  = $this->menuOrFail( (string) $args['menu'] );
		$items = wp_get_nav_menu_items( $menu->term_id );
		return array_map( array( $this, 'shapeItem' ), $items ?: array() );
	}

	/** @return array<int,array<string,mixed>> */
	public function menuLocations(): array {
		$this->gate();
		$assigned = get_nav_menu_locations();
		$out      = array();
		foreach ( get_registered_nav_menus() as $loc => $description ) {
			$out[] = array(
				'location'       => (string) $loc,
				'description'    => (string) $description,
				'assignedMenuId' => ! empty( $assigned[ $loc ] ) ? (string) $assigned[ $loc ] : null,
			);
		}
		return $out;
	}

	/** @param array<string,mixed> $args */
	public function createNavMenu( $root, array $args ): array {
		$this->gate();
		$id = wp_create_nav_menu( (string) $args['name'] );
		if ( is_wp_error( $id ) ) {
			throw new UserError( $id->get_error_message() );
		}
		return $this->shapeMenu( wp_get_nav_menu_object( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function addNavMenuItem( $root, array $args ): array {
		$this->gate();
		$menu = $this->menuOrFail( (string) $args['menu'] );
		$type = (string) ( $args['type'] ?? 'custom' );

		$itemArgs = array(
			'menu-item-title'     => (string) $args['title'],
			'menu-item-type'      => $type,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => isset( $args['parentId'] ) ? (int) $args['parentId'] : 0,
		);
		if ( $type === 'custom' ) {
			$itemArgs['menu-item-url'] = (string) ( $args['url'] ?? '' );
		} else {
			$itemArgs['menu-item-object']    = (string) ( $args['object'] ?? '' );
			$itemArgs['menu-item-object-id'] = (int) ( $args['objectId'] ?? 0 );
		}

		$itemId = wp_update_nav_menu_item( $menu->term_id, 0, $itemArgs );
		if ( is_wp_error( $itemId ) ) {
			throw new UserError( $itemId->get_error_message() );
		}
		$items = wp_get_nav_menu_items( $menu->term_id );
		foreach ( $items ?: array() as $i ) {
			if ( (int) $i->ID === (int) $itemId ) {
				return $this->shapeItem( $i );
			}
		}
		throw new UserError( 'The item was added but could not be read back.' );
	}

	/** @param array<string,mixed> $args */
	public function deleteNavMenuItem( $root, array $args ): bool {
		$this->gate();
		$id = (int) $args['itemId'];
		if ( ! is_nav_menu_item( $id ) ) {
			throw new UserError( "Item $id is not a menu item." );
		}
		return (bool) wp_delete_post( $id, true );
	}

	/** @param array<string,mixed> $args */
	public function assignMenuLocation( $root, array $args ): bool {
		$this->gate();
		$location = (string) $args['location'];
		if ( ! array_key_exists( $location, get_registered_nav_menus() ) ) {
			throw new UserError( "No registered menu location '$location'." );
		}
		$menu      = $this->menuOrFail( (string) $args['menu'] );
		$locations = get_theme_mod( 'nav_menu_locations', array() );
		$locations = is_array( $locations ) ? $locations : array();

		$locations[ $location ] = $menu->term_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return true;
	}
}
