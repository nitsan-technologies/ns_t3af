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

use NITSAN\NsT3AF\Agent\Service\AgentAttachTargetRequest;
use NITSAN\NsT3AF\Agent\Service\AgentAttachTargetResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class AgentAttachTargetRequestTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?array<string, mixed>}>
     */
    public static function requests(): iterable
    {
        yield 'news by title' => [
            'Add this image to the news QA News: Market outlook 2027',
            ['kind' => 'news', 'title' => 'QA News: Market outlook 2027'],
        ];
        yield 'news by quoted title' => [
            "Add this image to the news \u{2018}QA News: Market outlook 2027\u{2019}.",
            ['kind' => 'news', 'title' => 'QA News: Market outlook 2027'],
        ];
        yield 'news by uid' => ['Add this image to news article 4', ['kind' => 'news', 'uid' => 4]];
        yield 'content element by uid' => ['Add this image to content element 82.', ['kind' => 'content', 'uid' => 82]];
        yield 'content element by name' => [
            'Add this image to the content element Textmedia with image',
            ['kind' => 'content', 'title' => 'Textmedia with image'],
        ];
        yield 'new element is a later step' => ['Add this image to a new content element on this page.', null];
        yield 'no attach intent' => ['What is in the news QA News?', null];
        yield 'news without a title' => ['Add this image to the news', null];
        yield 'no target' => ['Add this image', null];
    }

    #[Test]
    public function newContentElementAttachIsDetected(): void
    {
        self::assertTrue(AgentAttachTargetRequest::asksForNewContentElementAttach(
            'Add this image to a new content element on this page.',
        ));
        self::assertFalse(AgentAttachTargetRequest::asksForNewContentElementAttach(
            'Add this image to the content element Textmedia with image',
        ));
    }

    /**
     * @param array<string, mixed>|null $expected
     */
    #[Test]
    #[DataProvider('requests')]
    public function readsTheRecordTheRequestNames(string $request, ?array $expected): void
    {
        self::assertSame($expected, AgentAttachTargetRequest::parse($request));
    }

    #[Test]
    public function textMediaUsesAssetsAndImageElementsUseImage(): void
    {
        self::assertSame('assets', AgentAttachTargetResolver::pickField('textmedia', ['image', 'assets']));
        self::assertSame('image', AgentAttachTargetResolver::pickField('textpic', ['image', 'assets']));
        self::assertSame('assets', AgentAttachTargetResolver::pickField('textmedia', ['assets']));
        self::assertSame('media', AgentAttachTargetResolver::pickField('special', ['media']));
        self::assertNull(AgentAttachTargetResolver::pickField('special', ['a', 'b']));
        self::assertNull(AgentAttachTargetResolver::pickField('text', []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function upload(int $uid, bool $success = true): array
    {
        return [
            'role' => 'assistant',
            'content' => 'file',
            'meta' => [
                'type' => 'tool_result',
                'tool' => 'file_get_info',
                'success' => $success,
                'triggeredByAttachment' => true,
                'details' => ['uid' => $uid],
            ],
        ];
    }

    #[Test]
    public function readsTheUidsOfFilesAttachedInTheComposer(): void
    {
        $other = ['role' => 'assistant', 'content' => 'x', 'meta' => ['type' => 'tool_result', 'tool' => 'file_get_info', 'details' => ['uid' => 9]]];

        self::assertSame([67, 70], AgentAttachTargetRequest::uploadedFileUids([self::upload(67), $other, self::upload(70), self::upload(67), self::upload(71, false)]));
        self::assertSame([], AgentAttachTargetRequest::uploadedFileUids([]));
    }

    #[Test]
    public function aSingleWaitingUploadIsUsedForTheNextMessage(): void
    {
        self::assertSame(67, AgentAttachTargetRequest::onlyWaitingUpload([self::upload(67)], []));
        self::assertSame(70, AgentAttachTargetRequest::onlyWaitingUpload([self::upload(67), self::upload(70)], [67 => true]));
        // Two files wait: the model asks which one.
        self::assertNull(AgentAttachTargetRequest::onlyWaitingUpload([self::upload(67), self::upload(70)], []));
        // Already attached, or nothing uploaded.
        self::assertNull(AgentAttachTargetRequest::onlyWaitingUpload([self::upload(67)], [67 => true]));
        self::assertNull(AgentAttachTargetRequest::onlyWaitingUpload([], []));
    }
}
