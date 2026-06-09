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
 * The admin control-plane schema for the Cave + Lamp SPAs: settings, account, billing, the RAG
 * index, usage, and chat CRUD. Hand-built and separate from the AI's content schema
 * (SchemaFactory) — this surface is small, fixed, and never third-party extended or RAG-indexed.
 */
class AdminSchema {

	private static ?Schema $schema = null;

	public static function build(): Schema {
		if ( self::$schema !== null ) {
			return self::$schema;
		}

		$json = new CustomScalarType( [
			'name'         => 'JSON',
			'description'  => 'Arbitrary JSON (a GraphQL operation\'s variables or result).',
			'serialize'    => static fn( $value ) => $value,
			'parseValue'   => static fn( $value ) => $value,
			'parseLiteral' => static fn() => null,
		] );

		$settings = new ObjectType( [
			'name'   => 'Settings',
			'fields' => [
				'edition'        => Type::nonNull( Type::string() ),
				'isPro'          => Type::nonNull( Type::boolean() ),
				'provider'       => Type::nonNull( Type::string() ),
				'chatModel'      => Type::string(),
				'embeddingModel' => Type::string(),
				'hasApiKey'      => Type::nonNull( Type::boolean() ),
				'hasSiteToken'   => Type::nonNull( Type::boolean() ),
				'usesProxy'      => Type::nonNull( Type::boolean() ),
				'configured'     => Type::nonNull( Type::boolean() ),
			],
		] );

		$account = new ObjectType( [
			'name'   => 'Account',
			'fields' => [
				'usesProxy'  => Type::nonNull( Type::boolean() ),
				'connected'  => Type::boolean(),
				'balanceUsd' => Type::float(),
				'spentUsd'   => Type::float(),
				'paid'       => Type::boolean(),
				'subscribed' => Type::boolean(),
			],
		] );

		$chatModel = new ObjectType( [
			'name'   => 'ChatModel',
			'fields' => [
				'id'    => Type::nonNull( Type::string() ),
				'tier'  => Type::string(),
				'price' => Type::string(),
			],
		] );
		$embedModel = new ObjectType( [
			'name'   => 'EmbedModel',
			'fields' => [
				'id'    => Type::nonNull( Type::string() ),
				'price' => Type::string(),
			],
		] );
		$modelCatalog = new ObjectType( [
			'name'   => 'ModelCatalog',
			'fields' => [
				'chat'  => Type::nonNull( Type::listOf( Type::nonNull( $chatModel ) ) ),
				'embed' => Type::nonNull( Type::listOf( Type::nonNull( $embedModel ) ) ),
				'live'  => Type::nonNull( Type::boolean() ),
				'error' => Type::string(),
			],
		] );

		$opArg = new ObjectType( [
			'name'   => 'OpArg',
			'fields' => [
				'name'     => Type::nonNull( Type::string() ),
				'type'     => Type::nonNull( Type::string() ),
				'required' => Type::nonNull( Type::boolean() ),
			],
		] );
		$operation = new ObjectType( [
			'name'   => 'Operation',
			'fields' => [
				'domain'      => Type::nonNull( Type::string() ),
				'name'        => Type::nonNull( Type::string() ),
				'kind'        => Type::nonNull( Type::string() ),
				'description' => Type::string(),
				'args'        => Type::nonNull( Type::listOf( Type::nonNull( $opArg ) ) ),
				'returns'     => Type::string(),
			],
		] );
		$operationsReport = new ObjectType( [
			'name'   => 'OperationsReport',
			'fields' => [
				'operations' => Type::nonNull( Type::listOf( Type::nonNull( $operation ) ) ),
				'unindexed'  => Type::nonNull( Type::listOf( Type::nonNull( Type::string() ) ) ),
				'outdated'   => Type::nonNull( Type::listOf( Type::nonNull( Type::string() ) ) ),
			],
		] );

		$indexEstimate = new ObjectType( [
			'name'   => 'IndexEstimate',
			'fields' => [
				'chunks'   => Type::nonNull( Type::int() ),
				'tokens'   => Type::nonNull( Type::int() ),
				'cost'     => Type::nonNull( Type::float() ),
				'free'     => Type::nonNull( Type::boolean() ),
				'unpriced' => Type::nonNull( Type::boolean() ),
			],
		] );
		$indexDiff = new ObjectType( [
			'name'   => 'IndexDiff',
			'fields' => [
				'added'   => Type::nonNull( Type::listOf( Type::nonNull( Type::string() ) ) ),
				'changed' => Type::nonNull( Type::listOf( Type::nonNull( Type::string() ) ) ),
			],
		] );
		$indexStatus = new ObjectType( [
			'name'   => 'IndexStatus',
			'fields' => [
				'configured'  => Type::nonNull( Type::boolean() ),
				'embeds'      => Type::nonNull( Type::boolean() ),
				'indexed'     => Type::boolean(),
				'upToDate'    => Type::boolean(),
				'model'       => Type::string(),
				'storedModel' => Type::string(),
				'indexedAt'   => Type::string(),
				'countStored' => Type::int(),
				'countLive'   => Type::int(),
				'estimate'    => $indexEstimate,
				'diff'        => $indexDiff,
			],
		] );

		$usageTotals = new ObjectType( [
			'name'   => 'UsageTotals',
			'fields' => [
				'calls'        => Type::nonNull( Type::int() ),
				'prompt'       => Type::nonNull( Type::int() ),
				'completion'   => Type::nonNull( Type::int() ),
				'cost'         => Type::nonNull( Type::float() ),
				'hasEstimates' => Type::nonNull( Type::boolean() ),
			],
		] );
		$usageByModel = new ObjectType( [
			'name'   => 'UsageByModel',
			'fields' => [
				'provider'   => Type::string(),
				'model'      => Type::string(),
				'kind'       => Type::string(),
				'calls'      => Type::nonNull( Type::int() ),
				'prompt'     => Type::nonNull( Type::int() ),
				'completion' => Type::nonNull( Type::int() ),
				'cost'       => Type::nonNull( Type::float() ),
				'estimated'  => Type::nonNull( Type::boolean() ),
			],
		] );
		$usageByDay = new ObjectType( [
			'name'   => 'UsageByDay',
			'fields' => [
				'day'   => Type::nonNull( Type::string() ),
				'calls' => Type::nonNull( Type::int() ),
				'cost'  => Type::nonNull( Type::float() ),
			],
		] );
		$usageRecent = new ObjectType( [
			'name'   => 'UsageRecent',
			'fields' => [
				'createdAt'        => Type::string(),
				'provider'         => Type::string(),
				'model'            => Type::string(),
				'kind'             => Type::string(),
				'promptTokens'     => Type::nonNull( Type::int() ),
				'completionTokens' => Type::nonNull( Type::int() ),
				'estimated'        => Type::nonNull( Type::boolean() ),
				'cost'             => Type::nonNull( Type::float() ),
			],
		] );
		$usage = new ObjectType( [
			'name'   => 'Usage',
			'fields' => [
				'totals'  => Type::nonNull( $usageTotals ),
				'byModel' => Type::nonNull( Type::listOf( Type::nonNull( $usageByModel ) ) ),
				'byDay'   => Type::nonNull( Type::listOf( Type::nonNull( $usageByDay ) ) ),
				'recent'  => Type::nonNull( Type::listOf( Type::nonNull( $usageRecent ) ) ),
				'account' => $account,
			],
		] );

		$attachment = new ObjectType( [
			'name'   => 'Attachment',
			'fields' => [
				'filename' => Type::string(),
				'token'    => Type::string(),
				'size'     => Type::int(),
			],
		] );
		$chatMessage = new ObjectType( [
			'name'   => 'ChatMessage',
			'fields' => [
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
				'pendingId'   => [
					'type'    => Type::int(),
					'resolve' => static fn( $row ) => $row['pending_id'] ?? null,
				],
			],
		] );
		$chatUsage = new ObjectType( [
			'name'   => 'ChatUsage',
			'fields' => [
				'prompt'     => Type::nonNull( Type::int() ),
				'completion' => Type::nonNull( Type::int() ),
				'tokens'     => Type::nonNull( Type::int() ),
				'cost'       => Type::nonNull( Type::float() ),
				'calls'      => Type::nonNull( Type::int() ),
			],
		] );
		$chat = new ObjectType( [
			'name'   => 'Chat',
			'fields' => [
				'id'        => Type::nonNull( Type::int() ),
				'title'     => Type::string(),
				'createdAt' => Type::string(),
			],
		] );
		$chatDetail = new ObjectType( [
			'name'   => 'ChatDetail',
			'fields' => [
				'chatId'   => Type::nonNull( Type::int() ),
				'messages' => Type::nonNull( Type::listOf( Type::nonNull( $chatMessage ) ) ),
				'usage'    => Type::nonNull( $chatUsage ),
			],
		] );

		$settingsInput = new InputObjectType( [
			'name'   => 'SettingsInput',
			'fields' => [
				'provider'       => Type::string(),
				'apiKey'         => Type::string(),
				'chatModel'      => Type::string(),
				'embeddingModel' => Type::string(),
				'siteToken'      => Type::string(),
			],
		] );
		$billingKind = new EnumType( [
			'name'   => 'BillingKind',
			'values' => [ 'credit' => [ 'value' => 'credit' ], 'subscription' => [ 'value' => 'subscription' ] ],
		] );
		$reindexResult = new ObjectType( [
			'name'   => 'ReindexResult',
			'fields' => [
				'status'  => Type::nonNull( Type::string() ),
				'chunks'  => Type::int(),
				'message' => Type::string(),
			],
		] );
		$checkoutSession = new ObjectType( [
			'name'   => 'CheckoutSession',
			'fields' => [ 'url' => Type::nonNull( Type::string() ) ],
		] );

		$query = new ObjectType( [
			'name'   => 'Query',
			'fields' => [
				'settings'    => [ 'type' => Type::nonNull( $settings ), 'resolve' => static fn() => AdminResolvers::settings() ],
				'account'     => [ 'type' => Type::nonNull( $account ), 'resolve' => static fn() => AdminResolvers::account() ],
				'models'      => [
					'type'    => Type::nonNull( $modelCatalog ),
					'args'    => [ 'provider' => Type::string(), 'refresh' => Type::boolean() ],
					'resolve' => static fn( $root, $args ) => AdminResolvers::models( $args['provider'] ?? null, ! empty( $args['refresh'] ) ),
				],
				'operations'  => [ 'type' => Type::nonNull( $operationsReport ), 'resolve' => static fn() => AdminResolvers::operations() ],
				'indexStatus' => [ 'type' => Type::nonNull( $indexStatus ), 'resolve' => static fn() => AdminResolvers::indexStatus() ],
				'usage'       => [ 'type' => Type::nonNull( $usage ), 'resolve' => static fn() => AdminResolvers::usage() ],
				'chats'       => [ 'type' => Type::nonNull( Type::listOf( Type::nonNull( $chat ) ) ), 'resolve' => static fn() => AdminResolvers::chats() ],
				'chat'        => [
					'type'    => $chatDetail,
					'args'    => [ 'id' => Type::nonNull( Type::int() ) ],
					'resolve' => static fn( $root, $args ) => AdminResolvers::chat( (int) $args['id'] ),
				],
			],
		] );

		$mutation = new ObjectType( [
			'name'   => 'Mutation',
			'fields' => [
				'saveSettings'    => [
					'type'    => Type::nonNull( $settings ),
					'args'    => [ 'input' => Type::nonNull( $settingsInput ) ],
					'resolve' => static fn( $root, $args ) => AdminResolvers::saveSettings( (array) $args['input'] ),
				],
				'connect'         => [ 'type' => Type::nonNull( $account ), 'resolve' => static fn() => AdminResolvers::connect() ],
				'activateLicense' => [
					'type'    => Type::nonNull( $settings ),
					'args'    => [ 'key' => Type::nonNull( Type::string() ) ],
					'resolve' => static fn( $root, $args ) => AdminResolvers::activateLicense( (string) $args['key'] ),
				],
				'deactivateLicense' => [ 'type' => Type::nonNull( $settings ), 'resolve' => static fn() => AdminResolvers::deactivateLicense() ],
				'reindex'         => [ 'type' => Type::nonNull( $reindexResult ), 'resolve' => static fn() => AdminResolvers::reindex() ],
				'resetUsage'      => [ 'type' => Type::nonNull( Type::boolean() ), 'resolve' => static fn() => AdminResolvers::resetUsage() ],
				'billingCheckout' => [
					'type'    => Type::nonNull( $checkoutSession ),
					'args'    => [ 'kind' => Type::nonNull( $billingKind ) ],
					'resolve' => static fn( $root, $args ) => AdminResolvers::billingCheckout( (string) $args['kind'] ),
				],
				'deleteChat'      => [
					'type'    => Type::nonNull( Type::boolean() ),
					'args'    => [ 'id' => Type::nonNull( Type::int() ) ],
					'resolve' => static fn( $root, $args ) => AdminResolvers::deleteChat( (int) $args['id'] ),
				],
			],
		] );

		return self::$schema = new Schema( [ 'query' => $query, 'mutation' => $mutation ] );
	}
}
