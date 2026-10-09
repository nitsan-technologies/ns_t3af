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

use NITSAN\NsT3AF\Agent\Service\AgentRecordAttachmentResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentRecordAttachmentResolverTest extends TestCase
{
    private AgentRecordAttachmentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AgentRecordAttachmentResolver();
    }

    #[Test]
    public function extractAttachmentsFindsTableUidPairs(): void
    {
        self::assertSame(
            [
                ['table' => 'tt_content', 'uid' => 198],
            ],
            $this->resolver->extractAttachments('Give me details @tt_content:198 please'),
        );
    }

    #[Test]
    public function extractAttachmentsDedupesRepeatedReferences(): void
    {
        self::assertSame(
            [
                ['table' => 'tt_content', 'uid' => 198],
            ],
            $this->resolver->extractAttachments('@tt_content:198 @tt_content:198'),
        );
    }

    #[Test]
    public function resolveReadInvocationMapsKnownTables(): void
    {
        $invocation = $this->resolver->resolveReadInvocation(
            'tt_content',
            198,
            static fn(string $tool): bool => $tool === 'content_get',
        );

        self::assertSame(
            [
                'tool' => 'content_get',
                'arguments' => ['uid' => 198],
            ],
            $invocation,
        );
    }

    #[Test]
    public function resolveReadInvocationFallsBackToTableGetPattern(): void
    {
        $invocation = $this->resolver->resolveReadInvocation(
            'tx_news',
            5,
            static fn(string $tool): bool => $tool === 'tx_news_get',
        );

        self::assertSame(
            [
                'tool' => 'tx_news_get',
                'arguments' => ['uid' => 5],
            ],
            $invocation,
        );
    }

    #[Test]
    public function mergeUidFromAttachmentsMatchesToolTable(): void
    {
        self::assertSame(
            ['uid' => 198],
            $this->resolver->mergeUidFromAttachments('content_get', [
                ['table' => 'pages', 'uid' => 1],
                ['table' => 'tt_content', 'uid' => 198],
            ]),
        );
    }

    #[Test]
    public function extractFileAttachmentsParsesStorageAndIdentifier(): void
    {
        self::assertSame(
            [
                ['storageUid' => 1, 'identifier' => '/user_upload/logo.png'],
            ],
            $this->resolver->extractFileAttachments('Please inspect @file:1:/user_upload/logo.png'),
        );
    }

    #[Test]
    public function resolveFileReadInvocationMapsToFileGetInfo(): void
    {
        $invocation = $this->resolver->resolveFileReadInvocation(
            1,
            '/user_upload/logo.png',
            static fn(string $tool): bool => $tool === 'file_get_info',
        );

        self::assertSame(
            [
                'tool' => 'file_get_info',
                'arguments' => [
                    'storageUid' => 1,
                    'fileIdentifier' => '/user_upload/logo.png',
                ],
            ],
            $invocation,
        );
    }
}
