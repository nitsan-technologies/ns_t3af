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
 * file that was distributed with this source code.
 */

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;

/**
 * @internal
 */
final class FeatureCatalogService
{
    public function __construct(
        private readonly T3PlanetApiClient $apiClient,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly CreditsApiResponseCacheInterface $responseCache,
        private readonly CreditsCatalogLanguageResolver $catalogLanguage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        $domain = $this->domainResolver->resolve();
        $token = $this->tokenResolver->resolve();
        $language = $this->catalogLanguage->resolve();
        $cacheScope = CreditsApiResponseCache::scopeFeatures($language);

        $cached = $this->responseCache->get($cacheScope, $domain, $token);
        if ($cached !== null) {
            return $cached;
        }

        $payload = $this->apiClient->features($domain, $token, $language);
        if (($payload['not_modified'] ?? false) === true) {
            return $payload;
        }

        $this->responseCache->set(
            $cacheScope,
            $domain,
            $token,
            $payload,
            CreditsApiResponseCache::TTL_FEATURES,
        );

        return $payload;
    }
}
