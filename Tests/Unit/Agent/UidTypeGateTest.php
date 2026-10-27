<?php

declare(strict_types=1);

namespace NITSAN\NsT3AF\Tests\Unit\Agent;

use NITSAN\NsT3AF\Agent\Service\AgentPromptBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UidTypeGateTest extends TestCase
{
    #[Test]
    public function deletingImagesWithNoAltTextIsNotAContentDelete(): void
    {
        self::assertTrue(AgentRunner::isFileRecordDeleteRequest('Delete all images that have no alt text'));
        self::assertTrue(AgentRunner::isMissingAltTextRequest('Delete all images that have no alt text'));
        self::assertTrue(AgentRunner::isFileRecordDeleteRequest('Lösche alle Bilder ohne Alt-Text'));
        self::assertFalse(AgentRunner::isFileRecordDeleteRequest('Delete content element 20'));
        self::assertFalse(AgentRunner::isFileRecordDeleteRequest('Change the header of element 12'));
        self::assertContains('content_delete', AgentRunner::toolsNotAskedFor('Delete all images that have no alt text'));
        self::assertNotContains('content_delete', AgentRunner::toolsNotAskedFor('Delete content element 20'));
    }

    #[Test]
    public function aNamedPageUidIsNotAContentUid(): void
    {
        self::assertSame(234, AgentRunner::namedPageUid('Summarize the content of page 234'));
        self::assertSame(234, AgentRunner::namedPageUid('Fasse den Inhalt von Seite 234 zusammen'));
        self::assertSame(0, AgentRunner::namedPageUid('Summarize content element 234'));
        self::assertTrue(AgentRunner::usesPageUidAsContentUid('Summarize the content of page 234', 234));
        self::assertFalse(AgentRunner::usesPageUidAsContentUid('Summarize the content of page 234', 412));
        self::assertTrue(AgentRunner::isSummarizeRequest('Summarize the content of page 234'));
        self::assertContains('content_delete', AgentRunner::toolsNotAskedFor('Summarize the content of page 234'));
        self::assertNotContains('t3aa_summarize_content', AgentRunner::toolsNotAskedFor('Summarize the content of page 234'));
    }

    #[Test]
    public function sayContinueIsNotUsedForANeverStartedTextElement(): void
    {
        $request = 'Add a text element to this page';
        self::assertTrue(AgentPromptBuilder::hasBlockingRemainingWork([], [], $request));
        self::assertFalse(AgentRunner::shouldPromptToContinue([], [], $request, []));
        self::assertTrue(AgentRunner::shouldPromptToContinue(
            [],
            [['title' => 'Create text', 'status' => 'open']],
            $request,
            ['write_table'],
        ));
        $attachRequest = 'Generate an image and attach it to the page.';
        $attachHistory = [
            ['role' => 'user', 'content' => $attachRequest, 'meta' => ['type' => 'message']],
            [
                'role' => 'assistant',
                'content' => 'Image saved.',
                'meta' => [
                    'type' => 'tool_result',
                    'tool' => 't3ai_generate_image',
                    'success' => true,
                    'details' => ['fileUid' => 12],
                ],
            ],
        ];
        self::assertNotSame('', AgentPromptBuilder::pendingImageAttachNote($attachHistory));
        self::assertTrue(AgentRunner::shouldPromptToContinue($attachHistory, [], $attachRequest, []));
    }

    #[Test]
    public function simplifiedTextInALanguageIsSummarizeNotTranslate(): void
    {
        $query = 'Create a simplified text version in German';
        self::assertTrue(AgentRunner::isSimplifyRequest($query));
        self::assertTrue(AgentRunner::isSummarizeRequest($query));
        self::assertNotContains('t3aa_summarize_content', AgentRunner::toolsNotAskedFor($query));
        self::assertFalse(AgentRunner::isSimplifyRequest('Translate this page into German'));
    }
}
