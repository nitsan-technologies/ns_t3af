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

namespace NITSAN\NsT3AF\Tests\Unit\Access;

use NITSAN\NsT3AF\Access\MatrixScopeCatalog;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\TestCase;

final class MatrixScopeCatalogTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    protected function setUp(): void
    {
        $this->mockAllCatalogExtensionsLoaded();
    }

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    public function testBuildsFoundationAndChildScopes(): void
    {
        $catalog = new MatrixScopeCatalog(
            $this->createModuleAccessCatalog(),
            $this->createFeaturePermissionCatalog(),
            $this->createRecordPermissionCatalog(),
        );

        $scopes = $catalog->buildScopes();

        self::assertSame('ai-universe', $scopes[0]['id']);
        self::assertNotEmpty($scopes[0]['adminModuleKeys']);
        self::assertContains('t3ai', array_column(array_slice($scopes, 1), 'id'));
        self::assertContains('content', $this->scopeById($scopes, 't3ai')['featureIds']);
        self::assertContains('t3csChatbot', $this->scopeById($scopes, 't3cs')['recordIds']);
    }

    /**
     * @param list<array<string, mixed>> $scopes
     * @return array<string, mixed>
     */
    private function scopeById(array $scopes, string $id): array
    {
        foreach ($scopes as $scope) {
            if ($scope['id'] === $id) {
                return $scope;
            }
        }

        self::fail('Scope not found: ' . $id);
    }
}
