<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Features;

use Djinn\GraphQL\Feature;
use Djinn\GraphQL\Registry;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Tier-3 curated support for WooCommerce — hand-tuned product/order operations that beat the
 * generic post + REST paths in quality (real prices, stock, order totals). Registered ONLY when
 * WooCommerce is active (see SchemaFactory::features and self::isActive), so its types never enter
 * the schema — and therefore never the RAG index — on non-Woo sites. It also claims the `product`
 * post type so the generic discovery chunks don't redundantly describe it.
 */
class WooCommerceFeature implements Feature {

	public static function isActive(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function register( Registry $r ): void {
		// Tell the RAG indexer we own these so it skips the generic synthetic chunks for them.
		add_filter( 'djinn_curated_post_types', static fn( array $t ): array => array_merge( $t, [ 'product', 'shop_order' ] ) );

		$product = new ObjectType(
			[
				'name'        => 'Product',
				'description' => 'A WooCommerce product.',
				'fields'      => [
					'id'            => [ 'type' => Type::id() ],
					'name'          => [ 'type' => Type::string() ],
					'sku'           => [ 'type' => Type::string() ],
					'price'         => [ 'type' => Type::string(), 'description' => 'Active price (sale price if on sale, else regular).' ],
					'regularPrice'  => [ 'type' => Type::string() ],
					'salePrice'     => [ 'type' => Type::string() ],
					'status'        => [ 'type' => Type::string(), 'description' => 'publish, draft, …' ],
					'type'          => [ 'type' => Type::string(), 'description' => 'simple, variable, …' ],
					'stockStatus'   => [ 'type' => Type::string(), 'description' => 'instock, outofstock, onbackorder.' ],
					'stockQuantity' => [ 'type' => Type::int() ],
					'permalink'     => [ 'type' => Type::string() ],
				],
			]
		);
		$r->setType( 'Product', $product );

		$order = new ObjectType(
			[
				'name'        => 'Order',
				'description' => 'A WooCommerce order (read-only).',
				'fields'      => [
					'id'           => [ 'type' => Type::id() ],
					'status'       => [ 'type' => Type::string() ],
					'total'        => [ 'type' => Type::string() ],
					'currency'     => [ 'type' => Type::string() ],
					'dateCreated'  => [ 'type' => Type::string() ],
					'customerId'   => [ 'type' => Type::id() ],
					'billingEmail' => [ 'type' => Type::string() ],
					'itemCount'    => [ 'type' => Type::int() ],
				],
			]
		);
		$r->setType( 'Order', $order );

		$r->addQuery( 'products', [
			'type'        => Type::listOf( $product ),
			'description' => 'List WooCommerce products.',
			'args'        => [
				'search' => [ 'type' => Type::string() ],
				'status' => [ 'type' => Type::string(), 'description' => 'publish (default), draft, …' ],
				'first'  => [ 'type' => Type::int(), 'defaultValue' => 20 ],
			],
			'resolve'     => [ $this, 'products' ],
		] );

		$r->addQuery( 'product', [
			'type'        => $product,
			'args'        => [ 'id' => [ 'type' => Type::nonNull( Type::id() ) ] ],
			'resolve'     => [ $this, 'product' ],
		] );

		$r->addQuery( 'orders', [
			'type'        => Type::listOf( $order ),
			'description' => 'List recent WooCommerce orders.',
			'args'        => [
				'status' => [ 'type' => Type::string(), 'description' => 'e.g. processing, completed, refunded.' ],
				'first'  => [ 'type' => Type::int(), 'defaultValue' => 20 ],
			],
			'resolve'     => [ $this, 'orders' ],
		] );

		$r->addMutation( 'createProduct', [
			'type'        => $product,
			'description' => 'Create a WooCommerce (simple) product.',
			'args'        => [
				'name'          => [ 'type' => Type::nonNull( Type::string() ) ],
				'regularPrice'  => [ 'type' => Type::string() ],
				'sku'           => [ 'type' => Type::string() ],
				'description'   => [ 'type' => Type::string() ],
				'status'        => [ 'type' => Type::string(), 'description' => 'draft (default) or publish.' ],
				'stockQuantity' => [ 'type' => Type::int() ],
			],
			'resolve'     => [ $this, 'createProduct' ],
		] );

		$r->addMutation( 'updateProduct', [
			'type'        => $product,
			'args'        => [
				'id'            => [ 'type' => Type::nonNull( Type::id() ) ],
				'name'          => [ 'type' => Type::string() ],
				'regularPrice'  => [ 'type' => Type::string() ],
				'salePrice'     => [ 'type' => Type::string() ],
				'sku'           => [ 'type' => Type::string() ],
				'status'        => [ 'type' => Type::string() ],
				'stockQuantity' => [ 'type' => Type::int() ],
			],
			'resolve'     => [ $this, 'updateProduct' ],
		] );
	}

	/** @param \WC_Product $p */
	private function shapeProduct( $p ): array {
		return [
			'id'            => (string) $p->get_id(),
			'name'          => $p->get_name(),
			'sku'           => $p->get_sku(),
			'price'         => $p->get_price(),
			'regularPrice'  => $p->get_regular_price(),
			'salePrice'     => $p->get_sale_price(),
			'status'        => $p->get_status(),
			'type'          => $p->get_type(),
			'stockStatus'   => $p->get_stock_status(),
			'stockQuantity' => $p->get_stock_quantity() !== null ? (int) $p->get_stock_quantity() : null,
			'permalink'     => $p->get_permalink(),
		];
	}

	/** @param array<string,mixed> $args */
	public function products( $root, array $args ): array {
		if ( ! current_user_can( 'edit_products' ) ) {
			throw new UserError( 'You do not have permission to manage products.' );
		}
		$products = wc_get_products(
			[
				'limit'  => min( max( (int) ( $args['first'] ?? 20 ), 1 ), 100 ),
				'status' => $args['status'] ?? 'publish',
				's'      => $args['search'] ?? '',
			]
		);
		return array_map( [ $this, 'shapeProduct' ], $products );
	}

	/** @param array<string,mixed> $args */
	public function product( $root, array $args ): ?array {
		if ( ! current_user_can( 'edit_products' ) ) {
			throw new UserError( 'You do not have permission to view products.' );
		}
		$p = wc_get_product( (int) $args['id'] );
		return $p ? $this->shapeProduct( $p ) : null;
	}

	/** @param array<string,mixed> $args */
	public function orders( $root, array $args ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			throw new UserError( 'You do not have permission to view orders.' );
		}
		$orders = wc_get_orders(
			[
				'limit'  => min( max( (int) ( $args['first'] ?? 20 ), 1 ), 100 ),
				'status' => isset( $args['status'] ) ? (string) $args['status'] : array_keys( wc_get_order_statuses() ),
			]
		);
		return array_map(
			static function ( $o ): array {
				return [
					'id'           => (string) $o->get_id(),
					'status'       => $o->get_status(),
					'total'        => $o->get_total(),
					'currency'     => $o->get_currency(),
					'dateCreated'  => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
					'customerId'   => (string) $o->get_customer_id(),
					'billingEmail' => $o->get_billing_email(),
					'itemCount'    => $o->get_item_count(),
				];
			},
			$orders
		);
	}

	/** @param array<string,mixed> $args */
	public function createProduct( $root, array $args ): array {
		if ( ! current_user_can( 'publish_products' ) && ! current_user_can( 'edit_products' ) ) {
			throw new UserError( 'You do not have permission to create products.' );
		}
		$p = new \WC_Product_Simple();
		$p->set_name( (string) $args['name'] );
		$p->set_status( $args['status'] ?? 'draft' );
		if ( isset( $args['regularPrice'] ) ) {
			$p->set_regular_price( (string) $args['regularPrice'] );
		}
		if ( isset( $args['sku'] ) ) {
			$p->set_sku( (string) $args['sku'] );
		}
		if ( isset( $args['description'] ) ) {
			$p->set_description( (string) $args['description'] );
		}
		if ( isset( $args['stockQuantity'] ) ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( (int) $args['stockQuantity'] );
		}
		$id = $p->save();
		if ( ! $id ) {
			throw new UserError( 'Could not create the product.' );
		}
		return $this->shapeProduct( wc_get_product( $id ) );
	}

	/** @param array<string,mixed> $args */
	public function updateProduct( $root, array $args ): array {
		$id = (int) $args['id'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw new UserError( 'You do not have permission to edit this product.' );
		}
		$p = wc_get_product( $id );
		if ( ! $p ) {
			throw new UserError( "No product with id $id." );
		}
		if ( isset( $args['name'] ) ) {
			$p->set_name( (string) $args['name'] );
		}
		if ( isset( $args['regularPrice'] ) ) {
			$p->set_regular_price( (string) $args['regularPrice'] );
		}
		if ( isset( $args['salePrice'] ) ) {
			$p->set_sale_price( (string) $args['salePrice'] );
		}
		if ( isset( $args['sku'] ) ) {
			$p->set_sku( (string) $args['sku'] );
		}
		if ( isset( $args['status'] ) ) {
			$p->set_status( (string) $args['status'] );
		}
		if ( isset( $args['stockQuantity'] ) ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( (int) $args['stockQuantity'] );
		}
		$p->save();
		return $this->shapeProduct( wc_get_product( $id ) );
	}
}
