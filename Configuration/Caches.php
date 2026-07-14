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
    'nst3af_responses' => [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['system', 'nst3af'],
    ],
    'nst3af_provider_models' => [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 86400,
        ],
        'groups' => ['system', 'nst3af'],
    ],
    'nst3af_credits_token' => [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['system', 'nst3af'],
    ],
    'nst3af_credits_api' => [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['system', 'nst3af'],
    ],
    'nst3af_mcp_oauth' => [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 300,
        ],
        'groups' => ['system', 'nst3af'],
    ],
    'nst3af_api_alert' => [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 3600,
        ],
        'groups' => ['system', 'nst3af'],
    ],
    'nst3af_dashboard_analytics' => [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'options' => [
            'defaultLifetime' => 900,
        ],
        'groups' => ['system', 'nst3af'],
    ],
];
