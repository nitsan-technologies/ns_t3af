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

use NITSAN\NsT3AF\Access\RecordAccessEnforcer;
use NITSAN\NsT3AF\Access\RecordAccessGate;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

final class RecordAccessEnforcerTest extends TestCase
{
    private RecordAccessEnforcer $enforcer;

    protected function setUp(): void
    {
        $this->enforcer = new RecordAccessEnforcer(new RecordAccessGate());
    }

    public function testAdminBypassReturnsNull(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);

        self::assertNull($this->enforcer->denyUnlessCanModifyTable($user, 'tx_nst3cs_domain_model_datasource'));
        self::assertNull($this->enforcer->denyUnlessCanModifyCatalogId($user, 't3csDatasource'));
    }

    public function testReadOnlyUserGets403Json(): void
    {
        $user = $this->user(
            tablesSelect: ['tx_nst3cs_domain_model_datasource'],
            tablesModify: [],
        );

        $response = $this->enforcer->denyUnlessCanModifyTable($user, 'tx_nst3cs_domain_model_datasource');
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertFalse($payload['ok']);
    }

    public function testReadWriteUserGetsNull(): void
    {
        $user = $this->user(
            tablesSelect: ['tx_nst3cs_domain_model_datasource'],
            tablesModify: ['tx_nst3cs_domain_model_datasource'],
        );

        self::assertNull($this->enforcer->denyUnlessCanModifyTable($user, 'tx_nst3cs_domain_model_datasource'));
    }

    public function testCatalogIdDeniedIncludesCatalogInMessage(): void
    {
        $user = $this->user(tablesSelect: [], tablesModify: []);

        $response = $this->enforcer->denyUnlessCanModifyCatalogId($user, 't3csDatasource');
        self::assertInstanceOf(JsonResponse::class, $response);
        $payload = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('t3csDatasource', $payload['message']);
    }

    public function testT3csReadOnlyDatasourceSelectWithoutModify(): void
    {
        $user = $this->user(
            tablesSelect: ['tx_nst3cs_domain_model_datasource'],
            tablesModify: [],
        );

        self::assertInstanceOf(
            JsonResponse::class,
            $this->enforcer->denyUnlessCanModifyCatalogId($user, 't3csDatasource'),
        );
        self::assertInstanceOf(
            JsonResponse::class,
            $this->enforcer->denyUnlessCanModifyCatalogId($user, 't3csSourceGroup'),
        );
    }

    /**
     * @param list<string> $tablesSelect
     * @param list<string> $tablesModify
     */
    private function user(array $tablesSelect, array $tablesModify): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturnCallback(
            static function (string $type, string $value) use ($tablesSelect, $tablesModify): bool {
                if ($type === 'tables_select') {
                    return in_array($value, $tablesSelect, true);
                }
                if ($type === 'tables_modify') {
                    return in_array($value, $tablesModify, true);
                }

                return false;
            },
        );

        return $user;
    }
}
