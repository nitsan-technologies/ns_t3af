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

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentSlashArgumentBinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentSlashArgumentBinderTest extends TestCase
{
    private AgentSlashArgumentBinder $binder;

    protected function setUp(): void
    {
        $this->binder = new AgentSlashArgumentBinder(
            $this->createMock(\NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService::class),
        );
    }

    #[Test]
    public function mapsFreeTextToRequiredQuestion(): void
    {
        $params = [
            ['name' => 'question', 'type' => 'string', 'required' => true, 'default' => null],
            ['name' => 'options', 'type' => 'array', 'required' => false, 'default' => '[]'],
        ];

        self::assertSame(
            ['question' => 'What can you do here?'],
            $this->binder->bind([], 'What can you do here?', $params),
        );
    }

    #[Test]
    public function mapsFreeTextToSearch(): void
    {
        $params = [
            ['name' => 'search', 'type' => 'string', 'required' => true, 'default' => null],
            ['name' => 'limit', 'type' => 'int', 'required' => false, 'default' => '20'],
        ];

        self::assertSame(
            ['search' => 'hello world'],
            $this->binder->bind([], 'hello world', $params),
        );
    }

    #[Test]
    public function mapsTableNameAndSearch(): void
    {
        $params = [
            ['name' => 'tableName', 'type' => 'string', 'required' => true, 'default' => null],
            ['name' => 'search', 'type' => 'string', 'required' => true, 'default' => null],
        ];

        self::assertSame(
            ['tableName' => 'tt_content', 'search' => 'hello world'],
            $this->binder->bind([], 'tt_content hello world', $params),
        );
    }

    #[Test]
    public function mapsOptionalEmptyStringParamForFileSearch(): void
    {
        $params = [
            ['name' => 'storageUid', 'type' => 'int', 'required' => false, 'default' => '1'],
            ['name' => 'namePattern', 'type' => 'string', 'required' => false, 'default' => ''],
            ['name' => 'extension', 'type' => 'string', 'required' => false, 'default' => ''],
        ];

        self::assertSame(
            ['namePattern' => 'logo'],
            $this->binder->bind([], 'logo', $params),
        );
    }

    #[Test]
    public function mapsLeadingDigitToUidAndRestToString(): void
    {
        $params = [
            ['name' => 'uid', 'type' => 'int', 'required' => true, 'default' => null],
            ['name' => 'selectFields', 'type' => 'string', 'required' => false, 'default' => ''],
        ];

        self::assertSame(
            ['uid' => 49, 'selectFields' => 'title,slug'],
            $this->binder->bind([], '49 title,slug', $params),
        );
    }

    #[Test]
    public function legacyFallbackMapsLeadingDigitToUidWithoutSchema(): void
    {
        self::assertSame(
            ['uid' => 49],
            $this->binder->bind([], '49', []),
        );
    }

    #[Test]
    public function doesNotOverwriteExistingArguments(): void
    {
        $params = [
            ['name' => 'question', 'type' => 'string', 'required' => true, 'default' => null],
        ];

        self::assertSame(
            ['question' => 'from-json'],
            $this->binder->bind(['question' => 'from-json'], 'ignored text', $params),
        );
    }

    #[Test]
    public function bindForToolUsesIntrospectedParams(): void
    {
        $introspector = $this->createMock(\NITSAN\NsT3AF\Mcp\Service\McpToolIntrospectorService::class);
        $introspector->method('listTools')->willReturn([
            [
                'name' => 'ask_clarification',
                'params' => [
                    ['name' => 'question', 'type' => 'string', 'required' => true, 'default' => null],
                ],
            ],
        ]);
        $binder = new AgentSlashArgumentBinder($introspector);

        self::assertSame(
            ['question' => 'Which language?'],
            $binder->bindForTool('ask_clarification', [], 'Which language?'),
        );
    }

    #[Test]
    public function fileRenameEmptyRemainderLeavesArgumentsUnchanged(): void
    {
        self::assertSame([], $this->binder->bind([], '', $this->fileRenameParams()));
    }

    #[Test]
    public function fileRenameOneTokenFillsOnlyTheFile(): void
    {
        self::assertSame(
            ['fileIdentifier' => '/user_upload/test.txt'],
            $this->binder->bind([], '/user_upload/test.txt', $this->fileRenameParams()),
        );
    }

    #[Test]
    public function fileRenameTwoTokensFillFileAndNewName(): void
    {
        self::assertSame(
            ['fileIdentifier' => '/user_upload/test.txt', 'newName' => 'renamed.txt'],
            $this->binder->bind([], '/user_upload/test.txt renamed.txt', $this->fileRenameParams()),
        );
    }

    #[Test]
    #[DataProvider('emptyRemainderProvider')]
    public function emptyRemainderIsNoop(string $remainder): void
    {
        self::assertSame(
            ['uid' => 1],
            $this->binder->bind(['uid' => 1], $remainder, [
                ['name' => 'question', 'type' => 'string', 'required' => true, 'default' => null],
            ]),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function emptyRemainderProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    /**
     * @return list<array{name: string, type: string, required: bool, default: string|null}>
     */
    private function fileRenameParams(): array
    {
        return [
            ['name' => 'fileIdentifier', 'type' => 'string', 'required' => true, 'default' => null],
            ['name' => 'newName', 'type' => 'string', 'required' => true, 'default' => null],
            ['name' => 'storageUid', 'type' => 'int', 'required' => false, 'default' => '1'],
        ];
    }
}
