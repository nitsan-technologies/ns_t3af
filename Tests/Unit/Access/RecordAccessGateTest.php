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

namespace NITSAN\NsT3AF\Tests\Unit\Access;

use NITSAN\NsT3AF\Access\RecordAccessGate;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class RecordAccessGateTest extends TestCase
{
    private RecordAccessGate $gate;

    protected function setUp(): void
    {
        $this->gate = new RecordAccessGate();
    }

    public function testAdminCanAlwaysModify(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);

        self::assertTrue($this->gate->canSelectTable($user, 'tx_nst3af_provider'));
        self::assertTrue($this->gate->canModifyTable($user, 'tx_nst3af_provider'));
    }

    public function testReadOnlyTableAccess(): void
    {
        $user = $this->user(
            tablesSelect: ['tx_nst3af_provider'],
            tablesModify: [],
        );

        self::assertTrue($this->gate->canSelectTable($user, 'tx_nst3af_provider'));
        self::assertFalse($this->gate->canModifyTable($user, 'tx_nst3af_provider'));
    }

    public function testReadWriteTableAccess(): void
    {
        $user = $this->user(
            tablesSelect: ['tx_nst3af_group_settings'],
            tablesModify: ['tx_nst3af_group_settings'],
        );

        self::assertTrue($this->gate->canSelectTable($user, 'tx_nst3af_group_settings'));
        self::assertTrue($this->gate->canModifyTable($user, 'tx_nst3af_group_settings'));
    }

    public function testExcludedFieldNeedsAGrant(): void
    {
        $original = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['tt_content']['columns'] = [
            'header' => ['config' => ['type' => 'input']],
            'subheader' => ['exclude' => true, 'config' => ['type' => 'input']],
            'bodytext' => ['exclude' => true, 'config' => ['type' => 'text']],
        ];

        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'non_exclude_fields' && $value === 'tt_content:bodytext',
        );

        try {
            self::assertTrue($this->gate->canModifyField($user, 'tt_content', 'header'));
            self::assertFalse($this->gate->canModifyField($user, 'tt_content', 'subheader'));
            self::assertTrue($this->gate->canModifyField($user, 'tt_content', 'bodytext'));
            self::assertSame(
                ['header' => 'A', 'bodytext' => 'C'],
                $this->gate->withoutForbiddenFields($user, 'tt_content', ['header' => 'A', 'subheader' => 'B', 'bodytext' => 'C']),
            );
            self::assertSame([], $this->gate->withoutForbiddenFields($user, 'tt_content', ['subheader' => 'B']));
        } finally {
            $GLOBALS['TCA'] = $original;
        }
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
