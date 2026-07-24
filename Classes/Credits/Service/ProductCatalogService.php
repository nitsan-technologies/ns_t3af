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

namespace NITSAN\NsT3AF\Credits\Service;

use NITSAN\NsT3AF\Credits\Contract\CreditsApiResponseCacheInterface;
use NITSAN\NsT3AF\Credits\CreditsConstants;
use NITSAN\NsT3AF\Credits\Http\T3PlanetApiClient;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final class ProductCatalogService
{
    private const TABLE = 'tx_nst3af_product_catalog';

    public function __construct(
        private readonly T3PlanetApiClient $apiClient,
        private readonly TokenResolver $tokenResolver,
        private readonly CreditsDomainResolver $domainResolver,
        private readonly ConnectionPool $connectionPool,
        private readonly CreditsApiResponseCacheInterface $responseCache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fetch(string $redirectTo = ''): array
    {
        $domain = $this->domainResolver->resolve();
        $token = $this->tokenResolver->resolve();
        $redirectTo = trim($redirectTo);
        $cacheScope = CreditsApiResponseCache::scopeProducts($redirectTo);

        $memoryCached = $this->responseCache->get($cacheScope, $domain, $token);
        if ($memoryCached !== null) {
            return $memoryCached;
        }

        $cached = $this->loadCached();
        $etag = is_array($cached) ? (string) ($cached['etag'] ?? '') : '';
        $result = $this->apiClient->products(
            $domain,
            $token,
            $redirectTo,
            $etag !== '' ? $etag : null,
        );

        if (($result['body']['not_modified'] ?? false) === true && is_array($cached)) {
            $body = json_decode((string) ($cached['body_json'] ?? ''), true);
            $body = is_array($body) ? $body : [];
            $this->rememberMemoryCache($domain, $token, $redirectTo, $body);

            return $body;
        }

        $body = $result['body'];
        if (($body['not_modified'] ?? false) === true) {
            return $body;
        }

        $this->storeCache($body, (string) ($result['etag'] ?? ''));
        $this->rememberMemoryCache($domain, $token, $redirectTo, $body);

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rememberMemoryCache(string $domain, #[\SensitiveParameter] string $token, string $redirectTo, array $body): void
    {
        $this->responseCache->set(
            CreditsApiResponseCache::scopeProducts($redirectTo),
            $domain,
            $token,
            $body,
            CreditsApiResponseCache::TTL_PRODUCTS,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCached(): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)->select(
            ['*'],
            self::TABLE,
            ['uid' => CreditsConstants::PRODUCT_CATALOG_UID],
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function storeCache(array $body, string $etag): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $exists = $this->loadCached() !== null;
        $values = [
            'etag' => $etag,
            'body_json' => json_encode($body, JSON_THROW_ON_ERROR),
            'fetched_at' => time(),
        ];
        if ($exists) {
            $connection->update(self::TABLE, $values, ['uid' => CreditsConstants::PRODUCT_CATALOG_UID]);
        } else {
            $connection->insert(self::TABLE, array_merge(['uid' => CreditsConstants::PRODUCT_CATALOG_UID], $values));
        }
    }
}
