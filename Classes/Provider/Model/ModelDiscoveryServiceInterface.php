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

namespace NITSAN\NsT3AF\Provider\Model;

use NITSAN\NsT3AF\Domain\Model\Provider;

/**
 * Returns the merged model list for a provider: live `/models` (when reachable
 * + authenticated) overlaid with bundled Symfony AI ModelCatalog metadata, and
 * capability inference as a last-resort overlay.
 *
 * Cached in `nst3af_provider_models` for 24h (see
 * `Configuration/Caches.php`).
 *
 * @internal Public-only via {@see \NITSAN\NsT3AF\Controller\Backend\ProviderController}.
 */
interface ModelDiscoveryServiceInterface
{
    /**
     * @param  Provider $provider Persisted (uid > 0) or transient (uid 0) provider.
     *                            For transient providers only `adapterType`,
     *                            `endpointUrl`, `apiKeyCipher` are required.
     * @param  bool     $refresh  When true, bypass cache and re-probe.
     * @return list<ModelInfo>
     */
    public function discover(Provider $provider, bool $refresh = false): array;
}
