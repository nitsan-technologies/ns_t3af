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
use NITSAN\NsT3AF\AiLabel\Service\ComplianceStringsService;
use NITSAN\NsT3AF\AiLabel\Service\ConfirmationService;
use NITSAN\NsT3AF\AiLabel\Service\FrontendLabelStateFactory;
use NITSAN\NsT3AF\AiLabel\Service\MediaRuleEngine;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class FrontendLabelStateFactoryTest extends TestCase
{
    public function testGeneratedConfirmedRecordShowsLabel(): void
    {
        $factory = new FrontendLabelStateFactory(
            $this->confirmationService(true),
            new MediaRuleEngine(new ComplianceStringsService()),
        );

        $state = $factory->fromRecord('tt_content', [
            'uid' => 12,
            'tx_nst3af_ailabel_involvement' => 'ai_generated',
            'tx_nst3af_ailabel_labelling_mode' => 'automatic',
            'crdate' => time(),
        ]);

        self::assertTrue($state->showLabel);
        self::assertTrue($state->hasAiInvolvement());
        self::assertTrue($state->isAiGenerated());
        self::assertSame('ai_generated', $state->involvementKey());
        self::assertSame('rule_default', $state->reasonCodeKey());
    }

    public function testUnknownFileYieldsEmptyState(): void
    {
        $factory = new FrontendLabelStateFactory(
            $this->confirmationService(false),
            new MediaRuleEngine(new ComplianceStringsService()),
        );

        $state = $factory->fromFile(null);

        self::assertFalse($state->showLabel);
        self::assertFalse($state->hasAiInvolvement());
        self::assertSame(0, $state->uid);
        self::assertSame('sys_file_metadata', $state->table);
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
