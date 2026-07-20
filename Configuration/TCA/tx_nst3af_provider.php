<?php

declare(strict_types=1);

/*
 * This file is part of the "AI Foundation for TYPO3" (ns_t3af) extension.
 *
 * (c) T3Planet / NITSAN Technologies <support@t3planet.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, either version 2 of the
 * License, or (at your option) any later version.
 *
 * For the full copyright and license information, please read the LICENSE
 * and COMMERCIAL-LICENSE.md files that were distributed with this source code.
 */

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ns_t3af/Resources/Private/Language/locallang_db.xlf:tx_nst3af_provider',
        'label' => 'title',
        'label_alt' => 'identifier',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'identifier,title,adapter_type,model_id',
        'iconfile' => 'EXT:ns_t3af/Resources/Public/Icons/Extension.svg',
        'rootLevel' => -1,
        'versioningWS' => false,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'identifier' => [
            'label' => 'Identifier',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'eval' => 'trim,unique',
                'required' => true,
            ],
        ],
        'title' => [
            'label' => 'Display name',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'adapter_type' => [
            'label' => 'Adapter type',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 64,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'endpoint_url' => [
            'label' => 'Endpoint URL',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'api_key' => [
            'label' => 'API key (encrypted)',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 50,
                'readOnly' => true,
            ],
            'description' => 'Stored as ciphertext (enc:v1:). Edit via the dashboard drawer to rotate.',
        ],
        'model_id' => [
            'label' => 'Model ID',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 128,
                'eval' => 'trim',
            ],
        ],
        'embedding_model_id' => [
            'label' => 'Embedding model ID',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 128,
                'eval' => 'trim',
            ],
        ],
        'capabilities' => [
            'label' => 'Capabilities',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'items' => [
                    ['label' => 'Chat', 'value' => 'chat'],
                    ['label' => 'Completion', 'value' => 'completion'],
                    ['label' => 'Embeddings', 'value' => 'embeddings'],
                    ['label' => 'Vision', 'value' => 'vision'],
                    ['label' => 'Streaming', 'value' => 'streaming'],
                    ['label' => 'Tool use', 'value' => 'tool_use'],
                ],
            ],
        ],
        'temperature' => [
            'label' => 'Temperature',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'size' => 5,
                'default' => 0.7,
                'range' => ['lower' => 0.0, 'upper' => 2.0],
            ],
        ],
        'system_prompt' => [
            'label' => 'System prompt',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'cols' => 50,
            ],
        ],
        'is_default' => [
            'label' => 'Default provider',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'priority' => [
            'label' => 'Priority',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => 50,
                'range' => ['lower' => 0, 'upper' => 100],
            ],
        ],
        'be_groups' => [
            'label' => 'Allowed BE groups',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'be_groups',
                'size' => 5,
                'maxitems' => 20,
            ],
            'description' => 'Restrict this provider to selected backend groups. Empty = available to all groups.',
        ],
        'privacy_level' => [
            'label' => 'Logging privacy',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Standard (full request logging)', 'value' => 'standard'],
                    ['label' => 'Reduced (log counters only, no fingerprint)', 'value' => 'reduced'],
                    ['label' => 'None (no request logging)', 'value' => 'none'],
                ],
                'default' => 'standard',
            ],
            'description' => 'Controls how much request telemetry is stored locally. Does not change what is sent to the AI provider. The strictest of provider and user setting wins.',
        ],
        'no_rerouting' => [
            'label' => 'Prevent rerouting',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
            'description' => 'When enabled, smart routing may not redirect requests away from this provider (e.g. for sensitive/local models).',
        ],
        'is_enabled' => [
            'label' => 'Enabled',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'enabled_for_dashboard' => [
            'label' => 'Use in dashboard analytics',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
        'pricing_input_per_1m' => [
            'label' => 'Input price / 1M tokens',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'default' => 0.0,
                'range' => ['lower' => 0.0],
            ],
        ],
        'pricing_output_per_1m' => [
            'label' => 'Output price / 1M tokens',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'default' => 0.0,
                'range' => ['lower' => 0.0],
            ],
        ],
        'pricing_currency' => [
            'label' => 'Pricing currency',
            'config' => [
                'type' => 'input',
                'size' => 4,
                'max' => 3,
                'eval' => 'trim,upper',
                'default' => 'USD',
            ],
        ],
        'retention_days_override' => [
            'label' => 'Retention override (days)',
            'config' => [
                'type' => 'number',
                'default' => 0,
                'range' => ['lower' => 0, 'upper' => 3650],
            ],
        ],
        'cost_center' => [
            'label' => 'Cost center',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'max' => 64,
                'eval' => 'trim',
            ],
        ],
        'last_status' => [
            'label' => 'Last status',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'size' => 20,
            ],
        ],
        'last_status_at' => [
            'label' => 'Last status checked',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'last_status_message' => [
            'label' => 'Last status message',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
                'rows' => 2,
            ],
        ],
        'last_used_at' => [
            'label' => 'Last used',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;Identity, identifier, title, adapter_type,
                --div--;Connection, endpoint_url, api_key, model_id, embedding_model_id,
                --div--;Configuration, capabilities, temperature, system_prompt, is_default, priority, is_enabled, enabled_for_dashboard,
                --div--;Pricing, pricing_input_per_1m, pricing_output_per_1m, pricing_currency, cost_center, retention_days_override,
                --div--;Access, be_groups, privacy_level, no_rerouting,
                --div--;Status, last_status, last_status_at, last_status_message, last_used_at,
            ',
        ],
    ],
];
