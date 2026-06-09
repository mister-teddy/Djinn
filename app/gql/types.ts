export default {
    "scalars": [
        1,
        2,
        3,
        6,
        25,
        30
    ],
    "types": {
        "Query": {
            "settings": [
                4
            ],
            "account": [
                5
            ],
            "models": [
                7,
                {
                    "provider": [
                        1
                    ],
                    "refresh": [
                        2
                    ]
                }
            ],
            "operations": [
                10
            ],
            "indexStatus": [
                13
            ],
            "usage": [
                16
            ],
            "chats": [
                21
            ],
            "chat": [
                22,
                {
                    "id": [
                        3,
                        "Int!"
                    ]
                }
            ],
            "__typename": [
                1
            ]
        },
        "String": {},
        "Boolean": {},
        "Int": {},
        "Settings": {
            "edition": [
                1
            ],
            "isPro": [
                2
            ],
            "provider": [
                1
            ],
            "chatModel": [
                1
            ],
            "embeddingModel": [
                1
            ],
            "hasApiKey": [
                2
            ],
            "hasSiteToken": [
                2
            ],
            "usesProxy": [
                2
            ],
            "configured": [
                2
            ],
            "__typename": [
                1
            ]
        },
        "Account": {
            "usesProxy": [
                2
            ],
            "connected": [
                2
            ],
            "balanceUsd": [
                6
            ],
            "spentUsd": [
                6
            ],
            "paid": [
                2
            ],
            "subscribed": [
                2
            ],
            "__typename": [
                1
            ]
        },
        "Float": {},
        "ModelCatalog": {
            "chat": [
                8
            ],
            "embed": [
                9
            ],
            "live": [
                2
            ],
            "error": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "ChatModel": {
            "id": [
                1
            ],
            "tier": [
                1
            ],
            "price": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "EmbedModel": {
            "id": [
                1
            ],
            "price": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "OperationsReport": {
            "operations": [
                11
            ],
            "unindexed": [
                1
            ],
            "outdated": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "Operation": {
            "domain": [
                1
            ],
            "name": [
                1
            ],
            "kind": [
                1
            ],
            "description": [
                1
            ],
            "args": [
                12
            ],
            "returns": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "OpArg": {
            "name": [
                1
            ],
            "type": [
                1
            ],
            "required": [
                2
            ],
            "__typename": [
                1
            ]
        },
        "IndexStatus": {
            "configured": [
                2
            ],
            "embeds": [
                2
            ],
            "indexed": [
                2
            ],
            "upToDate": [
                2
            ],
            "model": [
                1
            ],
            "storedModel": [
                1
            ],
            "indexedAt": [
                1
            ],
            "countStored": [
                3
            ],
            "countLive": [
                3
            ],
            "estimate": [
                14
            ],
            "diff": [
                15
            ],
            "__typename": [
                1
            ]
        },
        "IndexEstimate": {
            "chunks": [
                3
            ],
            "tokens": [
                3
            ],
            "cost": [
                6
            ],
            "free": [
                2
            ],
            "unpriced": [
                2
            ],
            "__typename": [
                1
            ]
        },
        "IndexDiff": {
            "added": [
                1
            ],
            "changed": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "Usage": {
            "totals": [
                17
            ],
            "byModel": [
                18
            ],
            "byDay": [
                19
            ],
            "recent": [
                20
            ],
            "account": [
                5
            ],
            "__typename": [
                1
            ]
        },
        "UsageTotals": {
            "calls": [
                3
            ],
            "prompt": [
                3
            ],
            "completion": [
                3
            ],
            "cost": [
                6
            ],
            "hasEstimates": [
                2
            ],
            "__typename": [
                1
            ]
        },
        "UsageByModel": {
            "provider": [
                1
            ],
            "model": [
                1
            ],
            "kind": [
                1
            ],
            "calls": [
                3
            ],
            "prompt": [
                3
            ],
            "completion": [
                3
            ],
            "cost": [
                6
            ],
            "estimated": [
                2
            ],
            "__typename": [
                1
            ]
        },
        "UsageByDay": {
            "day": [
                1
            ],
            "calls": [
                3
            ],
            "cost": [
                6
            ],
            "__typename": [
                1
            ]
        },
        "UsageRecent": {
            "createdAt": [
                1
            ],
            "provider": [
                1
            ],
            "model": [
                1
            ],
            "kind": [
                1
            ],
            "promptTokens": [
                3
            ],
            "completionTokens": [
                3
            ],
            "estimated": [
                2
            ],
            "cost": [
                6
            ],
            "__typename": [
                1
            ]
        },
        "Chat": {
            "id": [
                3
            ],
            "title": [
                1
            ],
            "createdAt": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "ChatDetail": {
            "chatId": [
                3
            ],
            "messages": [
                23
            ],
            "usage": [
                26
            ],
            "__typename": [
                1
            ]
        },
        "ChatMessage": {
            "role": [
                1
            ],
            "content": [
                1
            ],
            "attachments": [
                24
            ],
            "kind": [
                1
            ],
            "status": [
                1
            ],
            "operation": [
                1
            ],
            "variables": [
                25
            ],
            "summary": [
                1
            ],
            "message": [
                1
            ],
            "result": [
                25
            ],
            "pendingId": [
                3
            ],
            "__typename": [
                1
            ]
        },
        "Attachment": {
            "filename": [
                1
            ],
            "token": [
                1
            ],
            "size": [
                3
            ],
            "__typename": [
                1
            ]
        },
        "JSON": {},
        "ChatUsage": {
            "prompt": [
                3
            ],
            "completion": [
                3
            ],
            "tokens": [
                3
            ],
            "cost": [
                6
            ],
            "calls": [
                3
            ],
            "__typename": [
                1
            ]
        },
        "Mutation": {
            "saveSettings": [
                4,
                {
                    "input": [
                        28,
                        "SettingsInput!"
                    ]
                }
            ],
            "connect": [
                5
            ],
            "activateLicense": [
                4,
                {
                    "key": [
                        1,
                        "String!"
                    ]
                }
            ],
            "deactivateLicense": [
                4
            ],
            "reindex": [
                29
            ],
            "resetUsage": [
                2
            ],
            "billingCheckout": [
                31,
                {
                    "kind": [
                        30,
                        "BillingKind!"
                    ]
                }
            ],
            "deleteChat": [
                2,
                {
                    "id": [
                        3,
                        "Int!"
                    ]
                }
            ],
            "__typename": [
                1
            ]
        },
        "SettingsInput": {
            "provider": [
                1
            ],
            "apiKey": [
                1
            ],
            "chatModel": [
                1
            ],
            "embeddingModel": [
                1
            ],
            "siteToken": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "ReindexResult": {
            "status": [
                1
            ],
            "chunks": [
                3
            ],
            "message": [
                1
            ],
            "__typename": [
                1
            ]
        },
        "BillingKind": {},
        "CheckoutSession": {
            "url": [
                1
            ],
            "__typename": [
                1
            ]
        }
    }
}