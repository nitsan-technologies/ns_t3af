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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service\RecordsApply;

use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyPreflight;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyValidationException;
use NITSAN\NsT3AF\Mcp\Service\RecordService;
use NITSAN\NsT3AF\Mcp\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final class RecordsApplyPreflightTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalTca;

    private RecordService&MockObject $recordService;

    private ConnectionPool&MockObject $connectionPool;

    private Connection&MockObject $defaultConnection;

    private Connection&MockObject $otherConnection;

    private RecordsApplyPreflight $preflight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTca = $GLOBALS['TCA'] ?? [];
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => ['label' => 'title', 'sortby' => 'sorting'],
            'columns' => ['title' => ['config' => ['type' => 'input']]],
        ];
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['label' => 'header'],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'locked' => ['config' => ['type' => 'input', 'readOnly' => true]],
                'categories' => ['config' => ['type' => 'category']],
                'assets' => ['config' => ['type' => 'file']],
                'items' => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_items', 'foreign_field' => 'parent']],
            ],
        ];
        $GLOBALS['TCA']['sys_file_reference'] = ['ctrl' => ['label' => 'title'], 'columns' => []];
        $GLOBALS['TCA']['tx_items'] = ['ctrl' => ['label' => 'title'], 'columns' => ['title' => ['config' => ['type' => 'input']]]];
        $GLOBALS['TCA']['tx_denied'] = ['ctrl' => ['label' => 'title'], 'columns' => ['title' => ['config' => ['type' => 'input']]]];
        $GLOBALS['TCA']['tx_remote'] = ['ctrl' => ['label' => 'title'], 'columns' => ['title' => ['config' => ['type' => 'input']]]];

        $this->recordService = $this->createMock(RecordService::class);
        $this->recordService->method('findExistingUids')
            ->willReturnCallback(static fn(string $table, array $uids): array => $uids);

        $this->defaultConnection = $this->createMock(Connection::class);
        $this->otherConnection = $this->createMock(Connection::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->connectionPool->method('getConnectionByName')->willReturn($this->defaultConnection);
        $this->connectionPool->method('getConnectionForTable')
            ->willReturnCallback(fn(string $table): Connection => $table === 'tx_remote' ? $this->otherConnection : $this->defaultConnection);

        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('check')
            ->willReturnCallback(static fn(string $type, string $table): bool => $table !== 'tx_denied');
        $GLOBALS['BE_USER'] = $backendUser;

        $this->preflight = new RecordsApplyPreflight(new TcaSchemaService(), $this->recordService, $this->connectionPool);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->originalTca;
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aValidRequestComesBackNormalized(): void
    {
        $checked = $this->preflight->check(
            [
                'pages' => ['NEWp' => ['pid' => '1', 'title' => 'T']],
                'tt_content' => [
                    'NEWc1' => ['pid' => 'NEWp', 'header' => 'A', 'categories' => [8, '12'], 'assets' => 'NEWf1', 'items' => ['NEWi1', 'NEWi2']],
                    '5' => ['header' => 'B'],
                ],
                'sys_file_reference' => ['NEWf1' => ['pid' => 'NEWp']],
                'tx_items' => ['NEWi1' => ['pid' => 'NEWp', 'title' => 'I1'], 'NEWi2' => ['pid' => 'NEWp', 'title' => 'I2']],
            ],
            [],
            true,
        );

        self::assertSame(
            [
                'pages' => ['NEWp' => ['pid' => 1, 'title' => 'T']],
                'tt_content' => [
                    'NEWc1' => ['pid' => 'NEWp', 'header' => 'A', 'categories' => '8,12', 'assets' => 'NEWf1', 'items' => 'NEWi1,NEWi2'],
                    5 => ['header' => 'B'],
                ],
                'sys_file_reference' => ['NEWf1' => ['pid' => 'NEWp']],
                'tx_items' => ['NEWi1' => ['pid' => 'NEWp', 'title' => 'I1'], 'NEWi2' => ['pid' => 'NEWp', 'title' => 'I2']],
            ],
            $checked['datamap'],
        );
        self::assertSame(6, $checked['count']);
        self::assertSame([], $checked['ignored']);
    }

    #[Test]
    public function aRecordOnAPageThatDoesNotExistIsRefused(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')
            ->willReturnCallback(static fn(string $table, array $uids): array => $table === 'pages' ? [1] : $uids);
        $preflight = new RecordsApplyPreflight(new TcaSchemaService(), $recordService, $this->connectionPool);

        try {
            $preflight->check(['tt_content' => ['NEWa' => ['pid' => 1, 'header' => 'A'], 'NEWb' => ['pid' => 404, 'header' => 'B']]], [], true);
            self::fail('Expected a validation exception.');
        } catch (RecordsApplyValidationException $exception) {
            self::assertCount(1, $exception->getProblems());
            self::assertSame('NEWb', $exception->getProblems()[0]['id']);
            self::assertSame('Page 404 was not found or is not accessible.', $exception->getProblems()[0]['error']);
        }
    }

    #[Test]
    public function aRecordToPlaceANewOneAfterMustExist(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')
            ->willReturnCallback(static fn(string $table, array $uids): array => array_values(array_diff($uids, [77])));
        $preflight = new RecordsApplyPreflight(new TcaSchemaService(), $recordService, $this->connectionPool);

        try {
            $preflight->check(['tt_content' => ['NEWa' => ['pid' => -77, 'header' => 'A'], 'NEWb' => ['pid' => -78, 'header' => 'B']]], [], true);
            self::fail('Expected a validation exception.');
        } catch (RecordsApplyValidationException $exception) {
            self::assertCount(1, $exception->getProblems());
            self::assertStringContainsString('Record 77 to place this one after', (string) $exception->getProblems()[0]['error']);
        }
    }

    #[Test]
    public function aNewIdAsPidMustBeAPageCreatedInTheSameCall(): void
    {
        $problems = $this->problemsOf(
            [
                'pages' => ['NEWp' => ['pid' => 1, 'title' => 'P']],
                'tt_content' => [
                    'NEWa' => ['pid' => 'NEWp', 'header' => 'ok'],
                    'NEWb' => ['pid' => 'NEWmissing', 'header' => 'B'],
                    'NEWc' => ['pid' => 'NEWa', 'header' => 'C'],
                ],
            ],
            [],
        );

        self::assertCount(2, $problems);
        self::assertSame('NEWb', $problems[0]['id']);
        self::assertStringContainsString('not created as a page', (string) $problems[0]['error']);
        self::assertSame('NEWc', $problems[1]['id']);
    }

    #[Test]
    public function aNewIdAfterPidMustBeCreatedInTheSameTableAndCall(): void
    {
        $problems = $this->problemsOf(
            [
                'pages' => ['NEWp' => ['pid' => 1, 'title' => 'P']],
                'tt_content' => [
                    'NEWa' => ['pid' => 'NEWp', 'header' => 'A'],
                    'NEWb' => ['pid' => '-NEWa', 'header' => 'ok'],
                    'NEWc' => ['pid' => '-NEWp', 'header' => 'wrong table'],
                    'NEWd' => ['pid' => '-NEWnone', 'header' => 'unknown'],
                ],
            ],
            [],
        );

        self::assertCount(2, $problems);
        self::assertSame(['NEWc', 'NEWd'], array_column($problems, 'id'));
        self::assertStringContainsString('in this table', (string) $problems[0]['error']);
    }

    #[Test]
    public function aRelationListMayOnlyPointAtNewIdsCreatedInTheCall(): void
    {
        $problems = $this->problemsOf(
            [
                'tt_content' => ['NEWc' => ['pid' => 1, 'items' => ['NEWgone']]],
            ],
            [],
        );

        self::assertSame(['items'], $problems[0]['fields']);
        self::assertStringContainsString('NEWgone', (string) $problems[0]['error']);
    }

    #[Test]
    public function aValidCmdMapComesBackNormalized(): void
    {
        $checked = $this->preflight->check(
            [],
            ['tt_content' => ['7' => ['delete' => true], '8' => ['move' => '3', 'copy' => -9]]],
            true,
        );

        self::assertSame(
            ['tt_content' => [7 => ['delete' => 1], 8 => ['move' => 3, 'copy' => -9]]],
            $checked['cmdmap'],
        );
        self::assertSame(2, $checked['count']);
    }

    #[Test]
    public function withoutABackendUserNothingPasses(): void
    {
        unset($GLOBALS['BE_USER']);

        $this->expectException(RecordsApplyValidationException::class);
        $this->expectExceptionMessage('rejected');

        $this->preflight->check(['tt_content' => ['NEWc' => ['pid' => 1]]], [], true);
    }

    #[Test]
    public function anEmptyRequestIsRefused(): void
    {
        self::assertStringContainsString('Nothing to apply', $this->problemsOf([], [])[0]['error']);
    }

    #[Test]
    public function anUnknownTableAndATableTheUserMayNotModifyAreRefused(): void
    {
        $problems = $this->problemsOf(
            ['does_not_exist' => ['NEWa' => ['pid' => 1]], 'tx_denied' => ['NEWb' => ['pid' => 1, 'title' => 'x']]],
            [],
        );

        self::assertSame('Unknown table.', $problems[0]['error']);
        self::assertSame('No permission to modify this table.', $problems[1]['error']);
    }

    #[Test]
    public function aTableOnAnotherDatabaseConnectionIsRefusedBecauseATransactionCannotCoverIt(): void
    {
        $problems = $this->problemsOf(['tx_remote' => ['NEWa' => ['pid' => 1, 'title' => 'x']]], []);

        self::assertStringContainsString('separate database connection', $problems[0]['error']);
    }

    #[Test]
    public function newRecordsNeedAValidPidAndAnIdWithoutUnderscore(): void
    {
        $problems = $this->problemsOf(
            [
                'tt_content' => [
                    'NEWa' => ['header' => 'A'],
                    'NEW_b' => ['pid' => 1, 'header' => 'B'],
                    'NEWc' => ['pid' => 'abc', 'header' => 'C'],
                    'nonsense' => ['header' => 'D'],
                ],
            ],
            [],
        );

        self::assertSame('A new record needs a pid.', $problems[0]['error']);
        self::assertStringContainsString('Invalid record id', $problems[1]['error']);
        self::assertStringContainsString('Invalid pid', $problems[2]['error']);
        self::assertStringContainsString('Invalid record id', $problems[3]['error']);
    }

    #[Test]
    public function aPidInAnUpdateIsRefusedBecauseMovingIsACommand(): void
    {
        $problems = $this->problemsOf(['tt_content' => [5 => ['pid' => 9, 'header' => 'A']]], []);

        self::assertStringContainsString('cmd {"move"', $problems[0]['error']);
    }

    #[Test]
    public function strictModeRefusesTheWholeCallAndNamesTheFields(): void
    {
        $problems = $this->problemsOf(['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'A', 'locked' => 'x', 'bogus' => 1]]], []);

        self::assertSame(['locked', 'bogus'], $problems[0]['fields']);
        self::assertStringContainsString('strict', $problems[0]['error']);
    }

    #[Test]
    public function nonStrictModeDropsTheFieldsAndReportsThem(): void
    {
        $checked = $this->preflight->check(
            ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'A', 'locked' => 'x', 'bogus' => 1]]],
            [],
            false,
        );

        self::assertSame(['pid' => 1, 'header' => 'A'], $checked['datamap']['tt_content']['NEWc']);
        self::assertSame([['table' => 'tt_content', 'id' => 'NEWc', 'fields' => ['locked', 'bogus']]], $checked['ignored']);
    }

    #[Test]
    public function anUpdateWithNothingWritableLeftIsRefusedEvenInNonStrictMode(): void
    {
        try {
            $this->preflight->check(['tt_content' => [5 => ['bogus' => 1]]], [], false);
            self::fail('Expected a validation exception.');
        } catch (RecordsApplyValidationException $exception) {
            self::assertSame('No writable fields left to update.', $exception->getProblems()[0]['error']);
        }
    }

    #[Test]
    public function aBadRelationListIsRefusedAndNamed(): void
    {
        $problems = $this->problemsOf(['tt_content' => ['NEWc' => ['pid' => 1, 'categories' => 'a;b', 'items' => ['ok', 3.5]]]], []);

        self::assertSame(['categories'], $problems[0]['fields']);
        self::assertSame(['items'], $problems[1]['fields']);
    }

    #[Test]
    public function recordsThatDoNotExistAreRefusedWithTheSameAnswerAsInaccessibleOnes(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([]);
        $preflight = new RecordsApplyPreflight(new TcaSchemaService(), $recordService, $this->connectionPool);

        try {
            $preflight->check(['tt_content' => [5 => ['header' => 'A']]], ['tt_content' => [6 => ['delete' => 1]]], true);
            self::fail('Expected a validation exception.');
        } catch (RecordsApplyValidationException $exception) {
            self::assertSame('Record not found or not accessible.', $exception->getProblems()[0]['error']);
            self::assertSame('Record not found or not accessible.', $exception->getProblems()[1]['error']);
        }
    }

    #[Test]
    public function commandsAndTargetsAreChecked(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findExistingUids')
            ->willReturnCallback(static fn(string $table, array $uids): array => $table === 'pages' ? [] : $uids);
        $preflight = new RecordsApplyPreflight(new TcaSchemaService(), $recordService, $this->connectionPool);

        try {
            $preflight->check(
                [],
                ['tt_content' => [
                    1 => ['explode' => 1],
                    2 => ['delete' => 2],
                    3 => ['move' => 'abc'],
                    4 => ['move' => 77],
                    5 => ['move' => 0],
                    6 => ['localize' => 0],
                ]],
                true,
            );
            self::fail('Expected a validation exception.');
        } catch (RecordsApplyValidationException $exception) {
            $messages = array_column($exception->getProblems(), 'error');
            self::assertStringContainsString('Unknown command', $messages[0]);
            self::assertStringContainsString('takes the value 1', $messages[1]);
            self::assertStringContainsString('takes an integer', $messages[2]);
            self::assertStringContainsString('Target page 77', $messages[3]);
            self::assertStringContainsString('only valid for pages', $messages[4]);
            self::assertStringContainsString('language uid greater than 0', $messages[5]);
        }
    }

    #[Test]
    public function undeleteDoesNotNeedTheRecordToBeVisible(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('findExistingUids');
        $preflight = new RecordsApplyPreflight(new TcaSchemaService(), $recordService, $this->connectionPool);

        $checked = $preflight->check([], ['tt_content' => [9 => ['undelete' => 1]]], true);

        self::assertSame(['tt_content' => [9 => ['undelete' => 1]]], $checked['cmdmap']);
    }

    #[Test]
    public function moreThanTheLimitIsRefused(): void
    {
        $records = [];
        for ($i = 1; $i <= RecordsApplyPreflight::MAX_RECORDS + 1; ++$i) {
            $records['NEW' . $i] = ['pid' => 1, 'header' => 'x'];
        }

        $problems = $this->problemsOf(['tt_content' => $records], []);

        self::assertStringContainsString('Too many records', $problems[0]['error']);
    }

    #[Test]
    public function aRequestAtTheLimitPasses(): void
    {
        $records = [];
        for ($i = 1; $i <= RecordsApplyPreflight::MAX_RECORDS; ++$i) {
            $records['NEW' . $i] = ['pid' => 1, 'header' => 'x'];
        }

        self::assertSame(RecordsApplyPreflight::MAX_RECORDS, $this->preflight->check(['tt_content' => $records], [], true)['count']);
    }

    #[Test]
    public function problemsNeverRepeatFieldValues(): void
    {
        $problems = $this->problemsOf(
            ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'SECRET-VALUE-123', 'bogus' => 'SECRET-VALUE-456', 'categories' => 'SECRET-VALUE-789!']]],
            [],
        );

        self::assertStringNotContainsString('SECRET-VALUE', (string) json_encode($problems));
    }

    #[Test]
    public function clientSuppliedNamesAreCutAndStrippedBeforeTheyAreEchoed(): void
    {
        $problems = $this->problemsOf(['tt_content' => ["NEW\n" . str_repeat('x', 200) => ['pid' => 1]]], []);

        $id = (string) $problems[0]['id'];
        self::assertStringNotContainsString("\n", $id);
        self::assertLessThanOrEqual(80, mb_strlen($id));
    }

    /**
     * @param array<mixed> $datamap
     * @param array<mixed> $cmdmap
     * @return list<array<string, mixed>>
     */
    private function problemsOf(array $datamap, array $cmdmap): array
    {
        try {
            $this->preflight->check($datamap, $cmdmap, true);
        } catch (RecordsApplyValidationException $exception) {
            return $exception->getProblems();
        }

        self::fail('Expected a validation exception.');
    }
}
