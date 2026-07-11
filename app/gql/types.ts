export default {
    "scalars": [
        1,
        2,
        3,
        6,
        21,
        25
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
                9
            ],
            "usage": [
                12
            ],
            "chats": [
                17
            ],
            "chat": [
                18,
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
        "OperationsReport": {
            "operations": [
                10
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
                11
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
        "Usage": {
            "totals": [
                13
            ],
            "byModel": [
                14
            ],
            "byDay": [
                15
            ],
            "recent": [
                16
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
                19
            ],
            "usage": [
                22
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
                20
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
                21
            ],
            "summary": [
                1
            ],
            "message": [
                1
            ],
            "result": [
                21
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
            "mime": [
                1
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
                        24,
                        "SettingsInput!"
                    ]
                }
            ],
            "connect": [
                5
            ],
            "resetUsage": [
                2
            ],
            "billingCheckout": [
                26,
                {
                    "kind": [
                        25,
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
            "siteToken": [
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