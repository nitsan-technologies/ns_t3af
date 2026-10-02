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

use NITSAN\NsT3AF\Agent\Service\AgentRecordLabeler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Editor names for records and fields on draft and result cards.
 *
 * @internal
 */
final class AgentRecordLabelerTest extends TestCase
{
    use AgentTranslatorTrait;

    /** @var array<string, mixed>|null */
    private ?array $tcaBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA']['tx_demo_item'] = [
            'ctrl' => ['title' => 'Demo item'],
            'columns' => ['header' => ['label' => 'Header:']],
        ];
    }

    protected function tearDown(): void
    {
        $this->releaseAgentTranslator();
        if ($this->tcaBackup === null) {
            unset($GLOBALS['TCA']);
        } else {
            $GLOBALS['TCA'] = $this->tcaBackup;
        }
        parent::tearDown();
    }

    #[Test]
    public function labelsComeFromTcaWithFallbacks(): void
    {
        $labeler = new AgentRecordLabeler($this->createMock(UriBuilder::class), $this->createAgentTranslator());

        self::assertSame('Demo item', $labeler->tableLabel('tx_demo_item'));
        self::assertSame('tx_unknown', $labeler->tableLabel('tx_unknown'));
        self::assertSame('Header', $labeler->fieldLabel('tx_demo_item', 'header'));
        self::assertSame('bodytext', $labeler->fieldLabel('tx_demo_item', 'bodytext'));
        self::assertSame('New Demo item', $labeler->recordLabel('tx_demo_item', 0));
        self::assertSame([], $labeler->links('tx_demo_item', 0));
    }
}
