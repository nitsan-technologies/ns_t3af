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

use NITSAN\NsT3AF\Agent\Service\AgentChangeMessageBuilder;
use NITSAN\NsT3AF\Agent\Service\AgentRecordLabeler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * The sentence after Apply and Undo says what happened, not how many fields were counted
 * (tickets 14zervyu2kx and 14zervyu2ky).
 *
 * @internal
 */
final class AgentChangeMessageBuilderTest extends TestCase
{
    use AgentTranslatorTrait;

    private AgentChangeMessageBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $translator = $this->createAgentTranslator();
        $GLOBALS['TCA']['tt_content']['columns']['header']['label'] = 'Header';
        $this->subject = new AgentChangeMessageBuilder(
            $translator,
            new AgentRecordLabeler($this->createMock(UriBuilder::class), $translator),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        $this->releaseAgentTranslator();
        parent::tearDown();
    }

    #[Test]
    public function appliedNamesTheRecordAndTheNewValueOfASingleField(): void
    {
        $message = $this->subject->applied($this->applyResult('update', ['header' => 'QA Rename v1']));

        self::assertSame('Done: Page Content „QA visible element“ was updated. Header is now “QA Rename v1”.', $message);
    }

    #[Test]
    public function appliedListsTheFieldsOfAMultiFieldChange(): void
    {
        $message = $this->subject->applied($this->applyResult('update', ['header' => 'A', 'bodytext' => 'B']));

        self::assertSame('Done: Page Content „QA visible element“ was updated (Header, bodytext).', $message);
    }

    #[Test]
    public function appliedSaysARecordWasCreated(): void
    {
        $message = $this->subject->applied($this->applyResult('create', ['header' => 'A']));

        self::assertSame('Done: Page Content „QA visible element“ was created.', $message);
    }

    #[Test]
    public function appliedKeepsTheCounterWhenOnlyPartOfTheChangeWasSaved(): void
    {
        $result = $this->applyResult('update', ['header' => 'A']);
        $result['appliedCount'] = 1;
        $result['totalCount'] = 3;

        self::assertSame('Saved 1 of 3 changes.', $this->subject->applied($result));
    }

    #[Test]
    public function appliedFallsBackToTheCounterWithoutReadback(): void
    {
        self::assertSame('Saved 2 of 2 changes.', $this->subject->applied(['appliedCount' => 2, 'totalCount' => 2, 'readback' => []]));
    }

    #[Test]
    public function undoneNamesTheFieldAndItsRestoredValue(): void
    {
        $message = $this->subject->undone(['reverted' => [
            ['table' => 'tt_content', 'uid' => 5, 'field' => 'header', 'reverted' => 'restored', 'previousValue' => 'QA visible element'],
        ]]);

        self::assertSame('Undone: Header is back to “QA visible element”.', $message);
    }

    #[Test]
    public function undoneSaysWhenTheFieldIsEmptyAgain(): void
    {
        $message = $this->subject->undone(['reverted' => [
            ['table' => 'tt_content', 'uid' => 5, 'field' => 'header', 'reverted' => 'restored', 'previousValue' => ''],
        ]]);

        self::assertSame('Undone: Header is empty again.', $message);
    }

    #[Test]
    public function undoneSaysTheNewItemWasRemoved(): void
    {
        $message = $this->subject->undone(['reverted' => [
            ['table' => 'pages', 'uid' => 99, 'field' => '_record', 'reverted' => 'deleted'],
        ]]);

        self::assertSame('Undone: the new item was removed again.', $message);
    }

    /**
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function applyResult(string $action, array $values): array
    {
        return [
            'action' => $action,
            'appliedCount' => count($values),
            'totalCount' => count($values),
            'readback' => [[
                'table' => 'tt_content',
                'uid' => 5,
                'values' => $values,
                'recordLabel' => 'Page Content „QA visible element“',
                'fieldLabels' => ['header' => 'Header'],
            ]],
        ];
    }
}
