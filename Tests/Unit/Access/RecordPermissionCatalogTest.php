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

use NITSAN\NsT3AF\Access\RecordAccessDeniedException;
use NITSAN\NsT3AF\Access\RecordAccessGate;
use NITSAN\NsT3AF\Access\RecordPermissionCatalog;
use NITSAN\NsT3AF\Tests\Unit\Access\Support\LoadedExtensionsTestTrait;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class RecordPermissionCatalogTest extends TestCase
{
    use LoadedExtensionsTestTrait;

    private RecordPermissionCatalog $catalog;

    protected function setUp(): void
    {
        $this->mockAllCatalogExtensionsLoaded();
        $this->catalog = $this->createRecordPermissionCatalog();
    }

    protected function tearDown(): void
    {
        $this->resetLoadedExtensions();
        parent::tearDown();
    }

    public function testFindByTableReturnsRow(): void
    {
        $row = $this->catalog->findByTable('tx_nst3cs_domain_model_datasource');
        self::assertNotNull($row);
        self::assertSame('t3csDatasource', $row['id']);
    }

    public function testFindByTableReturnsAiPromptStorageRow(): void
    {
        $row = $this->catalog->findByTable('tx_nst3af_ai_prompt');
        self::assertNotNull($row);
        self::assertSame('aiPromptStorage', $row['id']);
        self::assertContains('aiPrompts', $row['relevantModules']);
        self::assertContains('prompts', $row['relevantFeatures']);
    }

    public function testTablesForCatalogId(): void
    {
        $tables = $this->catalog->tablesForCatalogId('t3csSourceGroup');
        self::assertSame(['tx_nst3cs_domain_model_sourcegroup'], $tables);
    }

    public function testUnknownCatalogReturnsEmptyTables(): void
    {
        self::assertSame([], $this->catalog->tablesForCatalogId('nonexistent'));
    }
}

final class RecordAccessGateCatalogTest extends TestCase
{
    private RecordAccessGate $gate;

    protected function setUp(): void
    {
        $this->gate = new RecordAccessGate();
    }

    public function testCanModifyCatalogRowWhenAnyTableWritable(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturnCallback(
            static function (string $type, string $value): bool {
                return $type === 'tables_modify' && $value === 'tx_nst3cs_domain_model_datasource';
            },
        );

        self::assertTrue($this->gate->canModifyCatalogRow($user, 't3csDatasource'));
        self::assertFalse($this->gate->canModifyCatalogRow($user, 't3csSourceGroup'));
    }

    public function testAssertThrowsWhenDenied(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturn(false);

        $this->expectException(RecordAccessDeniedException::class);
        $this->gate->assertCanModifyTable($user, 'tx_nst3af_provider');
    }
}
