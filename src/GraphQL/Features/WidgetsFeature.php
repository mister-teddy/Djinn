<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Widgets (Appearance → Widgets): list widget areas and their widgets, add a widget to an area,
 * and remove one. Works with the classic widget model (the sidebars_widgets option plus per-type
 * widget_* instance options), which still backs both classic and block widget areas. Gated on
 * edit_theme_options.
 */
class WidgetsFeature implements Feature {

	public function register( Registry $r ): void {
		$widget = new ObjectType(
			array(
				'name'        => 'Widget',
				'description' => 'A widget instance inside a sidebar.',
				'fields'      => array(
					'id'     => array(
						'type'        => Type::id(),
						'description' => 'Instance id, e.g. "search-2".',
					),
					'name'   => array( 'type' => Type::string() ),
					'idBase' => array(
						'type'        => Type::string(),
						'description' => 'Widget type base, e.g. "search" — pass to addWidget.',
					),
				),
			)
		);
		$r->setType( 'Widget', $widget );

		$sidebar = new ObjectType(
			array(
				'name'        => 'Sidebar',
				'description' => 'A registered widget area.',
				'fields'      => array(
					'id'      => array( 'type' => Type::id() ),
					'name'    => array( 'type' => Type::string() ),
					'widgets' => array( 'type' => Type::listOf( $widget ) ),
				),
			)
		);
		$r->setType( 'Sidebar', $sidebar );

		$r->addQuery(
			'sidebars',
			array(
				'type'        => Type::listOf( $sidebar ),
				'description' => 'List widget areas (sidebars) and the widgets in each.',
				'args'        => array(
					'id' => array(
						'type'        => Type::string(),
						'description' => 'Filter to one widget area by id, e.g. "footer-1".',
					),
				),
				'resolve'     => array( $this, 'sidebars' ),
			)
		);

		$r->addMutation(
			'addWidget',
			array(
				'type'        => Type::string(),
				'description' => 'Add a widget to a sidebar; returns the new instance id. Common idBase values: text, custom_html, search, recent-posts, categories, nav_menu, block.',
				'args'        => array(
					'sidebar'  => array( 'type' => Type::nonNull( Type::id() ) ),
					'idBase'   => array( 'type' => Type::nonNull( Type::string() ) ),
					'settings' => array(
						'type'        => Type::string(),
						'description' => 'JSON object of the widget\'s settings, e.g. {"title":"Hi","text":"…"}.',
					),
				),
				'resolve'     => array( $this, 'addWidget' ),
			)
		);
		$r->addMutation(
			'removeWidget',
			array(
				'type'        => Type::boolean(),
				'description' => 'Remove a widget instance from its sidebar by id (e.g. "text-3").',
				'args'        => array( 'id' => array( 'type' => Type::nonNull( Type::id() ) ) ),
				'resolve'     => array( $this, 'removeWidget' ),
			)
		);
	}

	private function gate(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new UserError( 'You do not have permission to manage widgets.' );
		}
	}

	private function idBase( string $widgetId ): string {
		return preg_replace( '/-\d+$/', '', $widgetId ) ?? $widgetId;
	}

	/** @return array<int,array<string,mixed>> */
	/** @param array<string,mixed> $args */
	public function sidebars( $root = null, array $args = array() ): array {
		$this->gate();
		global $wp_registered_sidebars, $wp_registered_widgets;
		$only = isset( $args['id'] ) ? (string) $args['id'] : '';
		$map  = wp_get_sidebars_widgets();
		$out  = array();
		foreach ( $wp_registered_sidebars as $id => $sb ) {
			if ( $id === 'wp_inactive_widgets' || ( $only !== '' && $id !== $only ) ) {
				continue;
			}
			$widgets = array();
			foreach ( (array) ( $map[ $id ] ?? array() ) as $wid ) {
				$widgets[] = array(
					'id'     => $wid,
					'name'   => $wp_registered_widgets[ $wid ]['name'] ?? $wid,
					'idBase' => $this->idBase( (string) $wid ),
				);
			}
			$out[] = array(
				'id'      => (string) $id,
				'name'    => $sb['name'] ?? $id,
				'widgets' => $widgets,
			);
		}
		return $out;
	}

	/** @param array<string,mixed> $args */
	public function addWidget( $root, array $args ): string {
		$this->gate();
		global $wp_registered_sidebars;
		$sidebar = (string) $args['sidebar'];
		if ( ! isset( $wp_registered_sidebars[ $sidebar ] ) ) {
			throw new UserError( "No such sidebar '$sidebar'." );
		}
		$idBase   = (string) $args['idBase'];
		$settings = isset( $args['settings'] ) ? json_decode( (string) $args['settings'], true ) : array();
		if ( ! is_array( $settings ) ) {
			throw new UserError( 'settings must be a JSON object.' );
		}

		$option    = 'widget_' . $idBase;
		$instances = get_option( $option, array() );
		$instances = is_array( $instances ) ? $instances : array();
		$index     = 1;
		foreach ( array_keys( $instances ) as $k ) {
			if ( is_numeric( $k ) ) {
				$index = max( $index, (int) $k + 1 );
			}
		}
		$instances[ $index ]       = $settings;
		$instances['_multiwidget'] = 1;
		update_option( $option, $instances );

		$widgetId          = $idBase . '-' . $index;
		$map               = wp_get_sidebars_widgets();
		$map[ $sidebar ][] = $widgetId;
		wp_set_sidebars_widgets( $map );
		return $widgetId;
	}

	/** @param array<string,mixed> $args */
	public function removeWidget( $root, array $args ): bool {
		$this->gate();
		$widgetId = (string) $args['id'];
		$idBase   = $this->idBase( $widgetId );
		$index    = (int) substr( $widgetId, strlen( $idBase ) + 1 );

		$map   = wp_get_sidebars_widgets();
		$found = false;
		foreach ( $map as $sb => $ids ) {
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$pos = array_search( $widgetId, $ids, true );
			if ( $pos !== false ) {
				unset( $map[ $sb ][ $pos ] );
				$map[ $sb ] = array_values( $map[ $sb ] );
				$found      = true;
			}
		}
		wp_set_sidebars_widgets( $map );

		$option    = 'widget_' . $idBase;
		$instances = get_option( $option, array() );
		if ( is_array( $instances ) && isset( $instances[ $index ] ) ) {
			unset( $instances[ $index ] );
			update_option( $option, $instances );
		}
		return $found;
	}
}
