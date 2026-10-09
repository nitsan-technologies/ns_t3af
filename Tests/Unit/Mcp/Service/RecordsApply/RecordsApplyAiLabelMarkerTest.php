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

use NITSAN\NsT3AF\AiLabel\Domain\Involvement;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelSettingsService;
use NITSAN\NsT3AF\AiLabel\Service\ApplicableTablesResolver;
use NITSAN\NsT3AF\Api\AiLabelRecorderInterface;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyAiLabelMarker;
use NITSAN\NsT3AF\Mcp\Service\WorkspaceContextService;
use NITSAN\NsT3AF\Settings\ExtensionSettingsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal
 */
final class RecordsApplyAiLabelMarkerTest extends TestCase
{
    private AiLabelRecorderInterface&MockObject $recorder;

    private AdvancedSettingsService&MockObject $settings;

    private LoggerInterface&MockObject $logger;

    private RecordsApplyAiLabelMarker $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = $this->createMock(AiLabelRecorderInterface::class);

        $this->settings = $this->createMock(AdvancedSettingsService::class);
        $this->settings->method('markWritesAsAi')->willReturn(true);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);
        $extensionSettings = $this->createMock(ExtensionSettingsService::class);
        $extensionSettings->method('getAllIgnorePid')->willReturn([]);
        $resolver = new ApplicableTablesResolver($dispatcher, new AiLabelSettingsService($extensionSettings));

        $workspace = $this->createMock(WorkspaceContextService::class);
        $workspace->method('getCurrentWorkspaceId')->willReturn(0);

        // The current involvement cannot be read in a unit test: the marker then treats the record as unlabelled.
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willThrowException(new \RuntimeException('no database in a unit test'));

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->marker = new RecordsApplyAiLabelMarker($this->recorder, $resolver, $this->settings, $workspace, $connectionPool, $this->logger);
    }

    #[Test]
    public function recordsCreatedByAnMcpClientAreMarkedAiGeneratedWithSourceMcp(): void
    {
        $this->recorder->expects(self::exactly(3))
            ->method('recordOrigin')
            ->willReturnCallback(function (string $table, int $uid, Involvement $involvement, string $source): void {
                self::assertSame('tt_content', $table);
                self::assertContains($uid, [7, 8, 9]);
                self::assertSame(Involvement::AiGenerated, $involvement);
                self::assertSame('mcp', $source);
            });

        self::assertSame(3, $this->marker->mark(['tt_content' => [7, 8, 9]], []));
    }

    #[Test]
    public function tablesWithoutAiLabelFieldsAreLeftAlone(): void
    {
        $this->recorder->expects(self::never())->method('recordOrigin');
        $this->recorder->expects(self::never())->method('markModified');

        self::assertSame(0, $this->marker->mark(['tx_not_registered' => [1]], ['tx_not_registered' => [2 => ['title']]]));
    }

    #[Test]
    public function theSettingTurnsMarkingOff(): void
    {
        $settings = $this->createMock(AdvancedSettingsService::class);
        $settings->method('markWritesAsAi')->willReturn(false);
        $marker = new RecordsApplyAiLabelMarker(
            $this->recorder,
            new ApplicableTablesResolver(
                $this->createMock(EventDispatcherInterface::class),
                new AiLabelSettingsService($this->createMock(ExtensionSettingsService::class)),
            ),
            $settings,
            $this->createMock(WorkspaceContextService::class),
            $this->createMock(ConnectionPool::class),
            $this->logger,
        );

        $this->recorder->expects(self::never())->method('recordOrigin');
        $this->recorder->expects(self::never())->method('markModified');

        self::assertSame(0, $marker->mark(['tt_content' => [1]], ['tt_content' => [2 => ['header']]]));
    }

    #[Test]
    public function aChangeOfContentMarksTheRecordAiModified(): void
    {
        $this->recorder->expects(self::once())->method('markModified')->with('tt_content', 5, 'mcp');

        self::assertSame(1, $this->marker->mark([], ['tt_content' => [5 => ['header', 'hidden']]]));
    }

    #[Test]
    public function aChangeOfVisibilityPositionOrDatesAloneIsNotAChangeOfContent(): void
    {
        $this->recorder->expects(self::never())->method('markModified');

        self::assertSame(0, $this->marker->mark([], [
            'tt_content' => [
                1 => ['hidden'],
                2 => ['starttime', 'endtime'],
                3 => ['colPos', 'sorting', 'sys_language_uid'],
                4 => ['fe_group', 'editlock'],
            ],
        ]));
    }

    #[Test]
    public function aMarkingProblemIsLoggedAndDoesNotStopTheOthers(): void
    {
        $this->recorder->method('recordOrigin')->willReturnCallback(static function (string $table, int $uid): void {
            if ($uid === 1) {
                throw new \RuntimeException('column missing');
            }
        });
        $this->logger->expects(self::once())->method('warning');

        self::assertSame(1, $this->marker->mark(['tt_content' => [1, 2]], []));
    }
}
