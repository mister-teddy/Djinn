<?php

declare( strict_types=1 );

namespace Djinn\GraphQL\Admin;

use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * The admin control-plane schema for the Cave + Lamp SPAs: settings, account, billing, usage,
 * and chat CRUD. Hand-built and separate from the AI's content schema (SchemaFactory) — this
 * surface is small, fixed, and never third-party extended.
 */
class AdminSchema {

	private static ?Schema $schema = null;

	public static function build(): Schema {
		if ( self::$schema !== null ) {
			return self::$schema;
		}

		$json = new CustomScalarType(
			array(
				'name'         => 'JSON',
				'description'  => 'Arbitrary JSON (a GraphQL operation\'s variables or result).',
				'serialize'    => static fn( $value ) => $value,
				'parseValue'   => static fn( $value ) => $value,
				'parseLiteral' => static fn() => null,
			)
		);

		$settings = new ObjectType(
			array(
				'name'   => 'Settings',
				'fields' => array(
					'edition'      => Type::nonNull( Type::string() ),
					'isPro'        => Type::nonNull( Type::boolean() ),
					'provider'     => Type::nonNull( Type::string() ),
					'chatModel'    => Type::string(),
					'hasApiKey'    => Type::nonNull( Type::boolean() ),
					'hasSiteToken' => Type::nonNull( Type::boolean() ),
					'usesProxy'    => Type::nonNull( Type::boolean() ),
					'configured'   => Type::nonNull( Type::boolean() ),
				),
			)
		);

		$account = new ObjectType(
			array(
				'name'   => 'Account',
				'fields' => array(
					'usesProxy'  => Type::nonNull( Type::boolean() ),
					'connected'  => Type::boolean(),
					'balanceUsd' => Type::float(),
					'spentUsd'   => Type::float(),
					'paid'       => Type::boolean(),
					'subscribed' => Type::boolean(),
				),
			)
		);

		$chatModel    = new ObjectType(
			array(
				'name'   => 'ChatModel',
				'fields' => array(
					'id'    => Type::nonNull( Type::string() ),
					'tier'  => Type::string(),
					'price' => Type::string(),
				),
			)
		);
		$modelCatalog = new ObjectType(
			array(
				'name'   => 'ModelCatalog',
				'fields' => array(
					'chat'  => Type::nonNull( Type::listOf( Type::nonNull( $chatModel ) ) ),
					'live'  => Type::nonNull( Type::boolean() ),
					'error' => Type::string(),
				),
			)
		);

		$opArg            = new ObjectType(
			array(
				'name'   => 'OpArg',
				'fields' => array(
					'name'     => Type::nonNull( Type::string() ),
					'type'     => Type::nonNull( Type::string() ),
					'required' => Type::nonNull( Type::boolean() ),
				),
			)
		);
		$operation        = new ObjectType(
			array(
				'name'   => 'Operation',
				'fields' => array(
					'domain'      => Type::nonNull( Type::string() ),
					'name'        => Type::nonNull( Type::string() ),
					'kind'        => Type::nonNull( Type::string() ),
					'description' => Type::string(),
					'args'        => Type::nonNull( Type::listOf( Type::nonNull( $opArg ) ) ),
					'returns'     => Type::string(),
				),
			)
		);
		$operationsReport = new ObjectType(
			array(
				'name'   => 'OperationsReport',
				'fields' => array(
					'operations' => Type::nonNull( Type::listOf( Type::nonNull( $operation ) ) ),
				),
			)
		);

		$usageTotals  = new ObjectType(
			array(
				'name'   => 'UsageTotals',
				'fields' => array(
					'calls'        => Type::nonNull( Type::int() ),
					'prompt'       => Type::nonNull( Type::int() ),
					'completion'   => Type::nonNull( Type::int() ),
					'cost'         => Type::nonNull( Type::float() ),
					'hasEstimates' => Type::nonNull( Type::boolean() ),
				),
			)
		);
		$usageByModel = new ObjectType(
			array(
				'name'   => 'UsageByModel',
				'fields' => array(
					'provider'   => Type::string(),
					'model'      => Type::string(),
					'kind'       => Type::string(),
					'calls'      => Type::nonNull( Type::int() ),
					'prompt'     => Type::nonNull( Type::int() ),
					'completion' => Type::nonNull( Type::int() ),
					'cost'       => Type::nonNull( Type::float() ),
					'estimated'  => Type::nonNull( Type::boolean() ),
				),
			)
		);
		$usageByDay   = new ObjectType(
			array(
				'name'   => 'UsageByDay',
				'fields' => array(
					'day'   => Type::nonNull( Type::string() ),
					'calls' => Type::nonNull( Type::int() ),
					'cost'  => Type::nonNull( Type::float() ),
				),
			)
		);
		$usageRecent  = new ObjectType(
			array(
				'name'   => 'UsageRecent',
				'fields' => array(
					'createdAt'        => Type::string(),
					'provider'         => Type::string(),
					'model'            => Type::string(),
					'kind'             => Type::string(),
					'promptTokens'     => Type::nonNull( Type::int() ),
					'completionTokens' => Type::nonNull( Type::int() ),
					'estimated'        => Type::nonNull( Type::boolean() ),
					'cost'             => Type::nonNull( Type::float() ),
				),
			)
		);
		$usage        = new ObjectType(
			array(
				'name'   => 'Usage',
				'fields' => array(
					'totals'  => Type::nonNull( $usageTotals ),
					'byModel' => Type::nonNull( Type::listOf( Type::nonNull( $usageByModel ) ) ),
					'byDay'   => Type::nonNull( Type::listOf( Type::nonNull( $usageByDay ) ) ),
					'recent'  => Type::nonNull( Type::listOf( Type::nonNull( $usageRecent ) ) ),
					'account' => $account,
				),
			)
		);

		$attachment  = new ObjectType(
			array(
				'name'   => 'Attachment',
				'fields' => array(
					'filename' => Type::string(),
					'token'    => Type::string(),
					'size'     => Type::int(),
				),
			)
		);
		$chatMessage = new ObjectType(
			array(
				'name'   => 'ChatMessage',
				'fields' => array(
					'role'        => Type::nonNull( Type::string() ),
					'content'     => Type::string(),
					'attachments' => Type::listOf( Type::nonNull( $attachment ) ),
					'kind'        => Type::string(),
					'status'      => Type::string(),
					'operation'   => Type::string(),
					'variables'   => $json,
					'summary'     => Type::string(),
					'message'     => Type::string(),
					'result'      => $json,
					'pendingId'   => array(
						'type'    => Type::int(),
						'resolve' => static fn( $row ) => $row['pending_id'] ?? null,
					),
				),
			)
		);
		$chatUsage   = new ObjectType(
			array(
				'name'   => 'ChatUsage',
				'fields' => array(
					'prompt'     => Type::nonNull( Type::int() ),
					'completion' => Type::nonNull( Type::int() ),
					'tokens'     => Type::nonNull( Type::int() ),
					'cost'       => Type::nonNull( Type::float() ),
					'calls'      => Type::nonNull( Type::int() ),
				),
			)
		);
		$chat        = new ObjectType(
			array(
				'name'   => 'Chat',
				'fields' => array(
					'id'        => Type::nonNull( Type::int() ),
					'title'     => Type::string(),
					'createdAt' => Type::string(),
				),
			)
		);
		$chatDetail  = new ObjectType(
			array(
				'name'   => 'ChatDetail',
				'fields' => array(
					'chatId'   => Type::nonNull( Type::int() ),
					'messages' => Type::nonNull( Type::listOf( Type::nonNull( $chatMessage ) ) ),
					'usage'    => Type::nonNull( $chatUsage ),
				),
			)
		);

		$settingsInput   = new InputObjectType(
			array(
				'name'   => 'SettingsInput',
				'fields' => array(
					'provider'  => Type::string(),
					'apiKey'    => Type::string(),
					'chatModel' => Type::string(),
					'siteToken' => Type::string(),
				),
			)
		);
		$billingKind     = new EnumType(
			array(
				'name'   => 'BillingKind',
				'values' => array(
					'credit'       => array( 'value' => 'credit' ),
					'subscription' => array( 'value' => 'subscription' ),
				),
			)
		);
		$checkoutSession = new ObjectType(
			array(
				'name'   => 'CheckoutSession',
				'fields' => array( 'url' => Type::nonNull( Type::string() ) ),
			)
		);

		$query = new ObjectType(
			array(
				'name'   => 'Query',
				'fields' => array(
					'settings'   => array(
						'type'    => Type::nonNull( $settings ),
						'resolve' => static fn() => AdminResolvers::settings(),
					),
					'account'    => array(
						'type'    => Type::nonNull( $account ),
						'resolve' => static fn() => AdminResolvers::account(),
					),
					'models'     => array(
						'type'    => Type::nonNull( $modelCatalog ),
						'args'    => array(
							'provider' => Type::string(),
							'refresh'  => Type::boolean(),
						),
						'resolve' => static fn( $root, $args ) => AdminResolvers::models( $args['provider'] ?? null, ! empty( $args['refresh'] ) ),
					),
					'operations' => array(
						'type'    => Type::nonNull( $operationsReport ),
						'resolve' => static fn() => AdminResolvers::operations(),
					),
					'usage'      => array(
						'type'    => Type::nonNull( $usage ),
						'resolve' => static fn() => AdminResolvers::usage(),
					),
					'chats'      => array(
						'type'    => Type::nonNull( Type::listOf( Type::nonNull( $chat ) ) ),
						'resolve' => static fn() => AdminResolvers::chats(),
					),
					'chat'       => array(
						'type'    => $chatDetail,
						'args'    => array( 'id' => Type::nonNull( Type::int() ) ),
						'resolve' => static fn( $root, $args ) => AdminResolvers::chat( (int) $args['id'] ),
					),
				),
			)
		);

		$mutation = new ObjectType(
			array(
				'name'   => 'Mutation',
				'fields' => array(
					'saveSettings'      => array(
						'type'    => Type::nonNull( $settings ),
						'args'    => array( 'input' => Type::nonNull( $settingsInput ) ),
						'resolve' => static fn( $root, $args ) => AdminResolvers::saveSettings( (array) $args['input'] ),
					),
					'connect'           => array(
						'type'    => Type::nonNull( $account ),
						'resolve' => static fn() => AdminResolvers::connect(),
					),
					'activateLicense'   => array(
						'type'    => Type::nonNull( $settings ),
						'args'    => array( 'key' => Type::nonNull( Type::string() ) ),
						'resolve' => static fn( $root, $args ) => AdminResolvers::activateLicense( (string) $args['key'] ),
					),
					'deactivateLicense' => array(
						'type'    => Type::nonNull( $settings ),
						'resolve' => static fn() => AdminResolvers::deactivateLicense(),
					),
					'resetUsage'        => array(
						'type'    => Type::nonNull( Type::boolean() ),
						'resolve' => static fn() => AdminResolvers::resetUsage(),
					),
					'billingCheckout'   => array(
						'type'    => Type::nonNull( $checkoutSession ),
						'args'    => array( 'kind' => Type::nonNull( $billingKind ) ),
						'resolve' => static fn( $root, $args ) => AdminResolvers::billingCheckout( (string) $args['kind'] ),
					),
					'deleteChat'        => array(
						'type'    => Type::nonNull( Type::boolean() ),
						'args'    => array( 'id' => Type::nonNull( Type::int() ) ),
						'resolve' => static fn( $root, $args ) => AdminResolvers::deleteChat( (int) $args['id'] ),
					),
				),
			)
		);

		return self::$schema = new Schema(
			array(
				'query'    => $query,
				'mutation' => $mutation,
			)
		);
	}
}
