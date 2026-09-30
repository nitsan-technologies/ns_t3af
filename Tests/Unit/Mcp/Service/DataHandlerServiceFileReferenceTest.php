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

namespace NITSAN\NsT3AF\Tests\Unit\Mcp\Service;

use NITSAN\NsT3AF\Mcp\Service\DataHandlerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regression: sys_file_reference placeholders must not contain "_", otherwise
 * DataHandler::processRemapStack() parses them as "<table>_<uid>" and the
 * parent field remap fails (v12: exception after the row is written → MCP
 * retry duplicates; v13/v14: reference silently dropped from the parent).
 *
 * @internal
 */
final class DataHandlerServiceFileReferenceTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function createFileReferencesUsesPlaceholdersWithoutUnderscore(): void
    {
        /** @var array<string, array<int|string, array<string, mixed>>> $capturedDatamap */
        $capturedDatamap = [];

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler
            ->expects(self::once())
            ->method('start')
            ->willReturnCallback(static function (array $datamap) use (&$capturedDatamap): void {
                $capturedDatamap = $datamap;
            });
        $dataHandler
            ->expects(self::once())
            ->method('process_datamap')
            ->willReturnCallback(static function () use ($dataHandler, &$capturedDatamap): void {
                $uid = 500;
                foreach (array_keys($capturedDatamap['sys_file_reference'] ?? []) as $newId) {
                    $dataHandler->substNEWwithIDs[(string) $newId] = ++$uid;
                }
            });
        GeneralUtility::addInstance(DataHandler::class, $dataHandler);

        $service = new DataHandlerService($this->createMock(SiteFinder::class));
        $result = $service->createFileReferences('tt_content', 10, 'image', [42, 43]);

        $placeholders = array_map('strval', array_keys($capturedDatamap['sys_file_reference'] ?? []));
        self::assertCount(2, $placeholders);
        self::assertCount(2, array_unique($placeholders));

        foreach ($placeholders as $placeholder) {
            self::assertStringNotContainsString('_', $placeholder);
            self::assertMatchesRegularExpression('/^NEW[0-9a-f]+$/', $placeholder);
        }

        self::assertSame(
            implode(',', $placeholders),
            $capturedDatamap['tt_content'][10]['image'] ?? null,
            'Parent field must reference exactly the generated placeholders',
        );
        self::assertSame([501, 502], $result);
    }
}
