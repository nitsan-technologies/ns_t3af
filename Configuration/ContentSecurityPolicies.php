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

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;

/**
 * Backend CSP: allow framing T3Planet Credits checkout (Pabbly) in modal iframes.
 *
 * Hosts must stay in sync with {@see \NITSAN\NsT3AF\Credits\Service\CreditsCheckoutUrlValidator}.
 */
return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(
            MutationMode::Extend,
            Directive::FrameSrc,
            new UriValue('https://t3planet.shop'),
            new UriValue('*.t3planet.shop'),
            new UriValue('*.t3planet.de'),
            new UriValue('*.t3planet.com'),
            new UriValue('https://payments.pabbly.com'),
            new UriValue('*.pabbly.com'),
            new UriValue('https://pabbly.t3planet.de'),
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::ConnectSrc,
            new UriValue('https://t3planet.shop'),
            new UriValue('*.t3planet.shop'),
            new UriValue('*.t3planet.de'),
            new UriValue('*.t3planet.com'),
            new UriValue('https://payments.pabbly.com'),
            new UriValue('*.pabbly.com'),
        ),
    ),
]);
