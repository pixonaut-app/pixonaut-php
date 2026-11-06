<?php

declare(strict_types=1);

/**
 * What the generator needs to know that the spec does not say. Every entry
 * carries its reason.
 */
return [
    // Operations whose answers carry a secret, so an error keeps none of the
    // body (`Operation::$sensitive`).
    'sensitive' => [
        'apiKeys.create' => 'Answers the new key\'s secret, shown once.',
        'connectedStore.exchangeCode' => 'Answers the store\'s integration, member and publishable keys and its webhook secret.',
        'connectedStore.holdAccess' => 'Its body is the store\'s credential, which an error body may echo.',
        'designs.correctionLinks.create' => 'Answers the correction link\'s token, shown once.',
        'shareLinks.create' => 'Answers the share link\'s token, shown once.',
        'shareLinks.regenerate' => 'Answers the share link\'s new token, shown once.',
        'templates.designerLinks.create' => 'Answers the designer link\'s token, shown once.',
        'webhookEndpoints.create' => 'Answers the endpoint\'s signing secret, shown once.',
    ],

    // `x-sdk-skip` operations the PHP SDK still generates. The other skipped
    // routes are signed links a host hands to a browser or a printshop, not
    // calls a server makes.
    'generateSkipped' => [
        'templates.mockup.get' => 'A server renders a template\'s mockup with its key, e.g. for a product image.',
        'templates.preview.get' => 'A server renders a template\'s preview with its key, e.g. for a product image.',
    ],

    // The resource (`$pixonaut->designs()`) each tag's operations are on, or
    // null for a tag whose operations the SDK sends only through `call()`
    // so far. An operation in a tag not listed here stops the generator.
    'resources' => [
        'API keys' => 'connect',
        'Assets' => null,
        'Automations' => null,
        'Blanks' => 'blanks',
        'Designs' => 'designs',
        'Events' => 'events',
        'Orders' => 'orders',
        'Pixel' => null,
        'Printshops' => 'printshops',
        'Prints' => 'prints',
        'Proofs' => 'proofs',
        'Sessions' => null,
        'Share links' => 'shareLinks',
        'Templates' => 'templates',
        'Webhook endpoints' => 'webhooks',
    ],

    // Operations on another resource than their tag's, or on none.
    'operationResources' => [
        // The workspace's own keys are administration; `API keys` maps to
        // `connect()` for the connected store's operations.
        'apiKeys.create' => null,
        'apiKeys.delete' => null,
        'apiKeys.list' => null,
        'apiKeys.update' => null,
        // A designer link opens the designer on a template, not a share link.
        'templates.designerLinks.create' => 'designerLinks',
    ],

    // The `operationId` prefix a resource's method names leave out, where it
    // is not the resource's own name (`designs.revisions.get` →
    // `designs()->getRevision()`).
    'resourcePrefixes' => [
        'connect' => 'connectedStore',
        'designerLinks' => 'templates.designerLinks',
        'webhooks' => 'webhookEndpoints',
    ],

    // Method names where the one derived from the `operationId` reads badly.
    'methodNames' => [
        // One answer holding every constraint, not one of several.
        'templates.constraints.get' => 'getConstraints',
        'blanks.previewTables.get' => 'getPreviewTables',
        // A ZIP of every file, not one.
        'prints.files.bundle' => 'bundleFiles',
        'orders.job.files.bundle' => 'bundleJobFiles',
        'webhookEndpoints.deliveries.redeliverFailed' => 'redeliverFailed',
        'webhookEndpoints.deliveries.redeliver' => 'redeliver',
        // A noun alone does not say the method lists.
        'orders.rerouteTargets' => 'listRerouteTargets',
        'printshopConnections.list' => 'listConnections',
        // `createPrintOrder` would read as making a print.
        'prints.orders.create' => 'createForPrint',
    ],

    // Fields of an answer named like a secret (`key`, `secret`, `token`)
    // that are not one, by operation and field path.
    'notSecret' => [
        'automations.placeholders' => [
            '$.namespaces[].placeholders[].token' => 'The placeholder as a text writes it, e.g. `{order.id}`.',
        ],
        'designs.correctionProofs.create' => [
            '$.proofToken' => 'Names the proof; redeeming it takes a secret or integration key.',
        ],
        'designs.findOrCreateStanding' => [
            '$.key' => 'The store\'s own key for the design, echoed from the request.',
        ],
        'proofs.create' => [
            '$.proofToken' => 'Names the proof; redeeming it takes a secret or integration key.',
        ],
        'proofs.createThroughLayout' => [
            '$.proofToken' => 'Names the proof; redeeming it takes a secret or integration key.',
        ],
        'scenes.list' => [
            '$.stockItems[].areas[].key' => 'The print area\'s name.',
            '$.stockItems[].key' => 'The stock item\'s name.',
        ],
        'shareLinks.designer.get' => [
            '$.sides[].key' => 'The side\'s short name, such as `front`.',
        ],
        'shareLinks.list' => [
            '$.counts.sources[].key' => 'Names the key a link was made with, not its secret.',
            '$.links[].subject.meta.sides[].key' => 'The side\'s short name, such as `front`.',
        ],
        'shareLinks.update' => [
            '$.subject.meta.sides[].key' => 'The side\'s short name, such as `front`.',
        ],
        'templates.list' => [
            '$.groups[].key' => 'The template group\'s name.',
        ],
    ],
];
