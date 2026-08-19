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

namespace NITSAN\NsT3AF\Tests\Unit\AiLabel;

use Doctrine\DBAL\Result;
use NITSAN\NsT3AF\AiLabel\Service\AiLabelRecordEvaluator;
use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelStateFactory;
use NITSAN\NsT3AF\AiLabel\Service\MediaRuleEngine;
use NITSAN\NsT3AF\AiLabel\Service\TextRuleEngine;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class FrontendLabelStateFactoryTest extends TestCase
{
    public function testTextWithoutPublicInterestDoesNotShow(): void
    {
        $state = $this->factory(true)->fromRecord('tt_content', [
            'uid' => 12,
            'tx_nst3af_ailabel_involvement' => 'ai_generated',
            'tx_nst3af_ailabel_labelling_mode' => 'automatic',
            'tx_nst3af_ailabel_public_interest' => 0,
            'crdate' => time(),
        ]);

        self::assertFalse($state->showLabel);
        self::assertTrue($state->hasAiInvolvement());
        self::assertSame('not_public_interest', $state->reasonCodeKey());
    }

    public function testMediaGeneratedConfirmedShowsLabel(): void
    {
        $state = $this->factory(true)->fromRecord('sys_file_metadata', [
            'uid' => 9,
            'tx_nst3af_ailabel_involvement' => 'ai_generated',
            'tx_nst3af_ailabel_labelling_mode' => 'automatic',
            'crdate' => time(),
        ]);

        self::assertTrue($state->showLabel);
        self::assertTrue($state->isAiGenerated());
        self::assertSame('rule_default', $state->reasonCodeKey());
    }

    public function testUnknownFileYieldsEmptyState(): void
    {
        $state = $this->factory(false)->fromFile(null);

        self::assertFalse($state->showLabel);
        self::assertFalse($state->hasAiInvolvement());
        self::assertSame(0, $state->uid);
        self::assertSame('sys_file_metadata', $state->table);
    }

    private function factory(bool $confirmed): FrontendLabelStateFactory
    {
        $strings = new ComplianceStringsService();

        return new FrontendLabelStateFactory(
            $this->confirmationService($confirmed),
            new AiLabelRecordEvaluator(
                new MediaRuleEngine($strings),
                new TextRuleEngine(),
            ),
        );
    }

    private function confirmationService(bool $confirmed): ConfirmationService
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'tx_nst3af_ailabel_confirmed_at' => $confirmed ? time() : 0,
            'tx_nst3af_ailabel_version_hash' => $confirmed ? 'hash' : '',
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('select')->willReturn($result);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        return new ConfirmationService($pool);
    }
}
