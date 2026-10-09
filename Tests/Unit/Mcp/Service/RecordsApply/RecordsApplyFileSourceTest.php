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

use Mcp\Exception\ToolCallException;
use NITSAN\NsT3AF\Mcp\Service\AdvancedSettingsService;
use NITSAN\NsT3AF\Mcp\Service\RecordsApply\RecordsApplyFileSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * What a payload file may contain. Who may read it is covered by the functional test.
 */
final class RecordsApplyFileSourceTest extends TestCase
{
    #[Test]
    public function allThreeSectionsAreRead(): void
    {
        [$data, $cmd, $bulk] = RecordsApplyFileSource::parse((string) json_encode([
            'data' => ['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'Hi']]],
            'cmd' => ['tt_content' => [7 => ['delete' => 1]]],
            'bulk' => [['table' => 'tt_content', 'uids' => [1, 2], 'set' => ['hidden' => 1]]],
        ]));

        self::assertSame(['tt_content' => ['NEWc' => ['pid' => 1, 'header' => 'Hi']]], $data);
        self::assertSame(['tt_content' => [7 => ['delete' => 1]]], $cmd);
        self::assertSame([['table' => 'tt_content', 'uids' => [1, 2], 'set' => ['hidden' => 1]]], $bulk);
    }

    #[Test]
    public function aSingleSectionIsEnough(): void
    {
        [$data, $cmd, $bulk] = RecordsApplyFileSource::parse('{"bulk":[{"table":"tt_content","uids":[1],"delete":true}]}');

        self::assertSame([], $data);
        self::assertSame([], $cmd);
        self::assertCount(1, $bulk);
    }

    #[Test]
    public function aByteOrderMarkIsIgnored(): void
    {
        [$data] = RecordsApplyFileSource::parse("\xEF\xBB\xBF" . '{"data":{"tt_content":{"NEWc":{"pid":1}}}}');

        self::assertArrayHasKey('tt_content', $data);
    }

    /** @return iterable<string, array{string, int}> */
    public static function badFiles(): iterable
    {
        yield 'not json' => ['{"data": ', 1790500030];
        yield 'empty file' => ['', 1790500030];
        yield 'a list' => ['[1, 2]', 1790500031];
        yield 'a scalar' => ['"text"', 1790500031];
        yield 'unknown key' => ['{"data":{"tt_content":{"NEWc":{"pid":1}}},"extra":1}', 1790500031];
        yield 'data is a list' => ['{"data":[1]}', 1790500031];
        yield 'cmd is a list' => ['{"cmd":[1]}', 1790500031];
        yield 'bulk is an object' => ['{"bulk":{"a":1}}', 1790500031];
        yield 'nothing in it' => ['{}', 1790500031];
        yield 'empty sections' => ['{"data":{},"cmd":{},"bulk":[]}', 1790500031];
    }

    #[Test]
    #[DataProvider('badFiles')]
    public function aFileThatIsNotAPayloadIsRefused(string $contents, int $code): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode($code);

        RecordsApplyFileSource::parse($contents);
    }

    #[Test]
    public function anUnknownKeyIsNamedButItsValueIsNot(): void
    {
        try {
            RecordsApplyFileSource::parse('{"data":{"tt_content":{"NEWc":{"pid":1}}},"secret-token":"hunter2"}');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $exception) {
            self::assertStringContainsString('secret-token', $exception->getMessage());
            self::assertStringNotContainsString('hunter2', $exception->getMessage());
        }
    }

    #[Test]
    public function theSizeCapNeverExceedsTheHardCeiling(): void
    {
        self::assertSame(10 * 1024 * 1024, RecordsApplyFileSource::MAX_FILE_BYTES);
        // Constructing it needs nothing but its two collaborators.
        self::assertInstanceOf(
            RecordsApplyFileSource::class,
            new RecordsApplyFileSource($this->createMock(ResourceFactory::class), $this->createMock(AdvancedSettingsService::class)),
        );
    }
}
