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

use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentRequestChecklist;
use NITSAN\NsT3AF\Agent\Service\AgentRequestedFiles;
use NITSAN\NsT3AF\Agent\Service\AgentTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * @internal
 */
#[CoversClass(AgentRequestedFiles::class)]
final class AgentRequestedFilesTest extends TestCase
{
    use AgentTranslatorTrait;

    private const OUR_TREATS = 'Create a text & media content element with the heading Our Treats and attach the existing images sys_file uid 3 (cake.png) and uid 4 (csm_cabin.png).';

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function requests(): iterable
    {
        yield 'two images by uid' => [self::OUR_TREATS, [3, 4]];
        yield 'file uid into an element' => ['Attach sys_file uid 145 to content element 168', [145]];
        yield 'sys_file number' => ['Add sys_file 7 to the text & media element', [7]];
        yield 'list of uids' => ['Use the images with uids 3, 4 and 5', [3, 4, 5]];
        yield 'record uid only' => ['Attach an image to tt_content uid 9', []];
        yield 'no attach wording' => ['Show me sys_file uid 3', []];
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('requests')]
    public function namedFileUidsReadsTheFilesNotTheTargetRecord(string $request, array $expected): void
    {
        self::assertSame($expected, AgentRequestedFiles::namedFileUids($request));
    }

    #[Test]
    public function messageNamesTheFilesThatCannotBeAttached(): void
    {
        $message = $this->service()->messageFor(self::OUR_TREATS, $this->translator(), 'c1');

        self::assertNotNull($message);
        self::assertSame('assistant', $message['role']);
        self::assertSame(AgentRequestedFiles::MESSAGE_TYPE, $message['meta']['type']);
        self::assertSame([3 => 'cake.png (uid 3)', 4 => 'uid 4'], $message['meta']['unavailableFiles']);
        self::assertTrue($message['meta']['noFileLeft']);
        self::assertStringContainsString('missing from the file storage: cake.png (uid 3), uid 4.', $message['content']);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function imageRequests(): array
    {
        return [
            'ai-generated' => ['Create a text & media content element with an AI-generated image of a red lighthouse at sunset', true],
            'generate an image' => ['Generate an image of a blue mountain lake', true],
            'german' => ['Erstelle ein Text & Medien Element und generiere ein Bild von einem Leuchtturm', true],
            'ki-bild' => ['Füge ein KI-Bild von einem Strand hinzu', true],
            'existing file' => ['Attach sys_file uid 145 to element 12', false],
            'generate seo' => ['Generate SEO metadata for this page', false],
            'image alt text' => ['Set the image alt text of element 5', false],
        ];
    }

    #[Test]
    #[DataProvider('imageRequests')]
    public function anImageTheAiHasToMakeIsRecognised(string $request, bool $expected): void
    {
        self::assertSame($expected, AgentRequestedFiles::asksForGeneratedImage($request));
    }

    #[Test]
    public function withoutImageGenerationTheAttachStepClosesAndNoWebImageIsFetched(): void
    {
        $request = 'Create a text & media content element with an AI-generated image of a red lighthouse at sunset and a short text about coastal travel';
        $notice = $this->service()->generationUnavailableMessage($request, $this->translator(), 'c1');
        self::assertNotNull($notice);
        self::assertStringContainsString('AI image generation is not available', $notice['content']);

        $history = [['role' => 'user', 'content' => $request, 'meta' => []], $notice];
        self::assertSame(['in_progress', 'failed'], array_column(AgentRequestChecklist::reconcile($history, $request), 'status'));
        self::assertStringContainsString('Do not search, invent, download or upload an image from the web', AgentRequestedFiles::promptNote($history, $request));
        // The editor gave a URL or an existing file: that is what gets attached, no notice.
        self::assertNull($this->service()->generationUnavailableMessage('Generate an image like https://example.com/a.png', $this->translator(), 'c1'));
    }

    #[Test]
    public function messageSaysTheOtherFilesAreStillAttached(): void
    {
        $message = $this->service()->messageFor('Create a text & media element and attach sys_file uid 145 and 3', $this->translator(), 'c1');

        self::assertNotNull($message);
        self::assertFalse($message['meta']['noFileLeft']);
        self::assertStringContainsString('Skipped cake.png (uid 3)', $message['content']);
        self::assertStringContainsString('continue with the other files (uid 145)', $message['content']);
    }

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function noMessageWhenEveryNamedFileIsThere(): void
    {
        self::assertNull($this->service()->messageFor('Attach sys_file uid 145 to content element 168', $this->translator(), 'c1'));
    }

    #[Test]
    public function attachStepFailsInsteadOfStayingOpenWhenNoFileIsLeft(): void
    {
        $notice = $this->service()->messageFor(self::OUR_TREATS, $this->translator(), 'c1');
        self::assertNotNull($notice);
        $history = [
            ['role' => 'user', 'content' => self::OUR_TREATS, 'meta' => []],
            $notice,
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 1, 'values' => ['CType' => 'textmedia']]],
                ],
            ],
        ];

        $steps = AgentRequestChecklist::reconcile($history, self::OUR_TREATS);

        self::assertSame(['completed', 'failed'], array_column($steps, 'status'));
        self::assertFalse(AgentRequestChecklist::hasOpen($steps));
        self::assertFalse(AgentPromptBuilder::hasBlockingRemainingWork($history, [], self::OUR_TREATS));
        self::assertStringContainsString('cake.png (uid 3)', AgentRequestedFiles::promptNote($history, self::OUR_TREATS));
    }

    #[Test]
    public function aRefusalForEveryNamedFileClosesTheStepAfterContinue(): void
    {
        $history = [
            ['role' => 'user', 'content' => self::OUR_TREATS, 'meta' => []],
            [
                'role' => 'assistant',
                'content' => 'Applied.',
                'meta' => [
                    'type' => 'readback_result',
                    'readback' => [['table' => 'tt_content', 'uid' => 248, 'values' => ['CType' => 'textmedia']]],
                ],
            ],
            [
                'role' => 'assistant',
                'content' => 'I could not prepare this change: The file cake.png (sys_file uid 3) is missing from the storage, so it would show as a broken image.'
                    . ' The file csm_cabin.png (sys_file uid 4) is missing from the storage, so it would show as a broken image.',
                'meta' => ['type' => 'tool_result', 'tool' => 'file_reference_add', 'success' => false],
            ],
            ['role' => 'user', 'content' => 'continue', 'meta' => ['type' => 'message']],
        ];
        $request = AgentPromptBuilder::latestUserRequestText($history);

        self::assertSame(self::OUR_TREATS, $request);
        self::assertSame(['completed', 'failed'], array_column(AgentRequestChecklist::reconcile($history, $request), 'status'));
        self::assertFalse(AgentPromptBuilder::hasBlockingRemainingWork($history, [], $request));
        self::assertStringContainsString('csm_cabin.png (uid 4)', AgentRequestedFiles::promptNote($history, $request));
    }

    #[Test]
    public function aRefusalForOneOfTwoFilesKeepsTheStepOpen(): void
    {
        $history = [
            ['role' => 'user', 'content' => self::OUR_TREATS, 'meta' => []],
            [
                'role' => 'assistant',
                'content' => 'The file cake.png (sys_file uid 3) is missing from the storage.',
                'meta' => ['type' => 'tool_result', 'tool' => 'file_reference_add', 'success' => false],
            ],
        ];

        self::assertFalse(AgentRequestedFiles::noFileLeftToAttach($history, self::OUR_TREATS));
    }

    #[Test]
    public function attachStepStaysOpenWithoutTheNotice(): void
    {
        $history = [['role' => 'user', 'content' => self::OUR_TREATS, 'meta' => []]];

        self::assertSame(['in_progress', 'pending'], array_column(AgentRequestChecklist::reconcile($history, self::OUR_TREATS), 'status'));
        self::assertSame('', AgentRequestedFiles::promptNote($history, self::OUR_TREATS));
    }

    private function service(): AgentRequestedFiles
    {
        $missing = $this->createMock(File::class);
        $missing->method('isMissing')->willReturn(true);
        $missing->method('getName')->willReturn('cake.png');
        $present = $this->createMock(File::class);
        $present->method('isMissing')->willReturn(false);
        $present->method('exists')->willReturn(true);
        $present->method('getName')->willReturn('photo.jpg');

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturnCallback(static function (int $uid) use ($missing, $present): File {
            return match ($uid) {
                3 => $missing,
                145 => $present,
                default => throw new FileDoesNotExistException('No file ' . $uid, 1),
            };
        });

        return new AgentRequestedFiles($factory);
    }

    private function translator(): AgentTranslator
    {
        return $this->createAgentTranslator();
    }
}
